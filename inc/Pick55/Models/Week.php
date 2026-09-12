<?php

namespace Pick55\Models;

use Pick55\DB;
use Pick55\Models\Season;

class Week extends BaseModel
{
	protected $table = 'football_weeks';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function season()
	{
		return $this->belongsTo(Season::class, 'football_season_id');
	}

	public function format()
	{
		return $this->belongsTo(WeekFormat::class, 'football_week_format_id');
	}

	public function games()
	{
		return $this->hasMany(Game::class, 'football_week_id');
	}

	public function pools()
	{
		return $this->hasMany(Pool::class, 'week_id');
	}

	public function weekWinners()
	{
		return $this->hasMany(WeekWinner::class, 'week_id');
	}

	public function guarantees()
	{
		return $this->hasMany(Guarantee::class, 'week_id');
	}

	/**
	 * @return Week or null
	 */
	public static function getActive()
	{
		$season = Season::getActive();
		if (!$season) {
			return null;
		}
		$active_week = null;
		foreach ($season->weeks as $week) {
			if ($week->canSeeResults()) {
				$active_week = $week;
			}
			if ($week->canPick()) {
				return $week;
			}
		}
		return $active_week;
	}

	/**
	 * @return Week or null
	 */
	public static function getNext()
	{
		$season = Season::getActive();
		if (!$season) {
			return null;
		}
		$active_week = null;
		foreach ($season->weeks as $week) {
			if ($week->canSeeResults()) {
				$active_week = $week;
			}
			else {
				return $week;
			}
		}
		return $active_week;
	}

	/**
	 * @param int $num
	 */
	public function setNumPools($num = 0)
	{
		$num = intval($num);
		if (!$num) {
			PoolsUsersLink::where('week_id', '=', $this->id)
				->delete();
			Pool::where('week_id', '=', $this->id)
				->delete();
			$this->num_pools = null;
			$this->save();
			return;
		}
		$pool_ids = [];
		foreach (range(1, $num) as $pool_num) {
			$pool = Pool::firstOrCreate([
				'week_id' => $this->id,
				'pool_num' => $pool_num,
			]);
			if (!$pool->name) {
				$pool->name = 'Pool #' . $pool_num;
				$pool->save();
			}
			$pool_ids[] = $pool->id;
		}
		PoolsUsersLink::where('week_id', '=', $this->id)
			->whereNotIn('pool_id', $pool_ids)
			->delete();
		Pool::where('week_id', '=', $this->id)
			->whereNotIn('id', $pool_ids)
			->delete();
		$this->num_pools = $num;
		$this->save();
	}

	/**
	 * Returns the "result" of the given bet. The bet is identified by the multiplier.
	 * The result is one of -1, 0, 1, or 3:
	 *   -1 - incorrect
	 *    0 - not decided yet
	 *    1 - correct
	 *    3 - automatically correct (guaranteed guess)
	 *
	 * @param int $user_id
	 * @param int $multi
	 * @return int
	 */
	public function getUserPickResult($user_id, $multi = 0) {
		$q = DB::table(Bet::getTableName() . ' AS bet')
			->leftJoin(Game::getTableName() . ' AS game', 'bet.football_game_id', '=', 'game.id')
			->select([
				DB::raw("
					IF(
						bet.option = '3',
						3,
						IF(
							game.correct_option = '0',
							0,
							IF(
								game.correct_option = bet.option,
								1,
								-1
							)
						)
					) AS result
				"),
			])
			->where('game.football_week_id', '=', $this->id)
			->where('bet.multiplier', '=', $multi)
			->where('bet.user_id', '=', $user_id);
		$row = $q->first();
		if (!$row) {
			return 0;
		}
		return $row->result;
	}

	/**
	 * @param int $user_id
	 * @return int
	 */
	public function getUserRank($user_id) {
		$q = DB::table(Bet::getTableName() . ' AS bet')
			->leftJoin(Game::getTableName() . ' AS game', 'bet.football_game_id', '=', 'game.id')
			->select([
				'bet.user_id',
				DB::raw("SUM(bet.multiplier) AS points"),
				DB::raw("COUNT(*) AS num_correct"),
				DB::raw("SUM(POWER(2, bet.multiplier)) AS bit_mult"),
			])
			->where('game.football_week_id', '=', $this->id)
			->where('game.correct_option', '!=', '0')
			->whereRaw('bet.option = game.correct_option')
			->groupBy('bet.user_id')
			->orderBy('points', 'DESC')
			->orderBy('num_correct', 'DESC')
			->orderBy('bit_mult', 'DESC')
			;
		$rank = 0;
		foreach ($q->cursor() as $row) {
			$rank++;
			if ($row->user_id == $user_id) {
				return $rank;
			}
		}
		return 0;
	}

	/**
	 * @param int $user_id
	 * @return int
	 */
	public function getUserScore($user_id) {
		$q = DB::table(Bet::getTableName() . ' AS bet')
			->leftJoin(Game::getTableName() . ' AS game', 'bet.football_game_id', '=', 'game.id')
			->select([
				DB::raw("SUM(bet.multiplier) AS score"),
			])
			->where('bet.user_id', '=', $user_id)
			->where('game.football_week_id', '=', $this->id)
			->where('game.correct_option', '!=', '0')
			->whereRaw('bet.option = game.correct_option');
		$row = $q->first();
		if ($row) {
			return $row->score;
		}
		return 0;
	}

	/**
	 * @return string datetime or null
	 */
	public function getFirstGameAt()
	{
		// Memoized per request: nav bars ask this for every week on every page.
		static $memo = [];
		if (array_key_exists($this->id, $memo)) {
			return $memo[$this->id];
		}
		$game = $this->games()
			->orderBy('date', 'ASC')
			->orderBy('time', 'ASC')
			->first();
		if (!$game) {
			return $memo[$this->id] = null;
		}
		$ts = strtotime($game->date . ' ' . $game->time);
		if (!$ts) {
			return $memo[$this->id] = null;
		}
		return $memo[$this->id] = date("Y-m-d H:i:s", $ts);
	}

	/**
	 * @return string datetime or null
	 */
	public function getPicksAvailableAt()
	{
		if (!$this->picks_due_date) {
			return null;
		}
		$ts = strtotime($this->picks_due_date);
		if (!$ts) {
			return null;
		}
		return date("Y-m-d H:i:s", $ts);
	}

	/**
	 * @return bool
	 */
	public function canCreateGames()
	{
		if (!$this->getPicksAvailableAt()) {
			return true;
		}
		return strtotime($this->getPicksAvailableAt()) > time();
	}

	/**
	 * Returns true if the given user ID can pick, false otherwise.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public function canUserPick($user_id)
	{
		if (!$this->season->hasPlayer($user_id)) {
			return false;
		}
		return $this->canPick();
	}

	/**
	 * Returns true if users can pick, false otherwise.
	 *
	 * @return bool
	 */
	public function canPick()
	{
		if (!$this->getPicksAvailableAt()) {
			return false;
		}
		if (!$this->getFirstGameAt()) {
			return false;
		}
		return strtotime($this->getPicksAvailableAt()) <= time()
			&& strtotime($this->getFirstGameAt()) >= time();
	}

	/**
	 * Returns true if the given user ID can see the results, false otherwise.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public function canUserSeeResults($user_id)
	{
		if (!$this->season->hasPlayer($user_id)) {
			return false;
		}
		return $this->canSeeResults();
	}

	/**
	 * Returns true if users can see results, false otherwise.
	 *
	 * @return bool
	 */
	public function canSeeResults()
	{
		if (!$this->games()->count()) {
			return false;
		}
		if (!$this->getFirstGameAt()) {
			return false;
		}
		return strtotime($this->getFirstGameAt()) <= time();
	}

	/**
	 *
	 * @return bool
	 */
	public function setGuaranteedPoints()
	{
		$guarantees = Guarantee::where('week_id','=',$this->id)->get();
		foreach ($guarantees as $guarantee) {
			DB::table(Bet::getTableName() . ' AS b')
				->leftJoin(Game::getTableName() . ' AS g', 'b.football_game_id', '=', 'g.id')
				->where('b.user_id', '=', $guarantee->user_id)
				->where('g.football_week_id', '=', $this->id)
				->where('b.multiplier','<',$guarantee->multipliers_less_than)
				->update(['b.option' => '3']);
		}
		return true;
	}
}
