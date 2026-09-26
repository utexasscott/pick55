<?php

namespace Pick55\Models;

use Pick55\Cache;
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
	 * The week's rules. Every week is expected to have one; callers must
	 * still handle null for a week that has not been assigned a format yet.
	 *
	 * @return WeekFormat|null
	 */
	public function getFormat()
	{
		return $this->format;
	}

	/**
	 * The format's name, or "Week N" when no format is assigned
	 * (replaces football_weeks.description).
	 *
	 * @return string
	 */
	public function getName()
	{
		$format = $this->getFormat();
		if ($format && strlen($format->name)) {
			return $format->name;
		}
		return 'Week ' . $this->week_num;
	}

	/**
	 * Replaces football_weeks.description_long.
	 *
	 * @return string
	 */
	public function getDescriptionLong()
	{
		$format = $this->getFormat();
		return $format ? (string) $format->description_long : '';
	}

	/**
	 * Number of pools the format calls for; 0 when the week has no pools
	 * (replaces football_weeks.num_pools).
	 *
	 * @return int
	 */
	public function getNumPools()
	{
		$format = $this->getFormat();
		return $format && $format->hasPools() ? (int) $format->num_pools : 0;
	}

	/**
	 * Replaces football_weeks.num_winners. Pass the selected pool's pool_num
	 * when showing one pool's results.
	 *
	 * @param int|null $pool_num
	 * @return int
	 */
	public function getNumWinners($pool_num = null)
	{
		$format = $this->getFormat();
		return $format ? $format->getNumWinners($pool_num) : 1;
	}

	/**
	 * Replaces football_weeks.min_score_threshold.
	 *
	 * @param int|null $pool_num
	 * @return int
	 */
	public function getMinScoreThreshold($pool_num = null)
	{
		$format = $this->getFormat();
		return $format ? $format->getMinScoreThreshold($pool_num) : 0;
	}

	/**
	 * Replaces football_weeks.is_playoffs.
	 *
	 * @return bool
	 */
	public function isPlayoffs()
	{
		$format = $this->getFormat();
		return $format ? (bool) $format->is_playoffs : false;
	}

	/**
	 * Creates or removes this week's football_pools rows so there are
	 * exactly $num of them (default: what the format calls for), keeping
	 * existing pools, their names and their players.
	 *
	 * @param int|null $num
	 */
	public function setNumPools($num = null)
	{
		if ($num === null) {
			$num = $this->getNumPools();
		}
		$num = intval($num);
		if (!$num) {
			PoolsUsersLink::where('week_id', '=', $this->id)
				->delete();
			Pool::where('week_id', '=', $this->id)
				->delete();
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

	/**
	 * Gives one player a side on every game of this week they have not
	 * picked, and a point value to every pick of theirs left without one
	 * while they still have unused values. Sides already chosen (including
	 * guaranteed '3's) and values already placed are kept; only '0' sides are
	 * replaced, unless $all. Game order is random, so which unvalued picks
	 * receive the leftover values is random too. This is the admin picks
	 * page's "Randomize" and the automatic fill at kickoff
	 * (randomizeRemainingPicks()).
	 *
	 * @param int $user_id
	 * @param bool $all  replace every side, not just the missing ones
	 * @return bool
	 */
	public function randomizePicksForUser($user_id, $all = false)
	{
		$user_id = (int) $user_id;
		if (!$user_id) {
			return false;
		}
		$games = $this->games()
			->inRandomOrder()
			->get();
		// value => true once a pick of this player holds it (0 too, so
		// further 0s are treated as unvalued and offered the free values).
		$held = array_fill(0, 11, false);
		$unvalued = [];
		foreach ($games as $game) {
			$pick = Bet::firstOrCreate([
				'user_id' => $user_id,
				'football_game_id' => $game->id,
			]);
			if ($all || $pick->option == '0' || !$pick->option) {
				$pick->option = (string) mt_rand(1, 2);
				$pick->save();
			}
			$mult = (int) $pick->multiplier;
			if ($mult < 0 || $mult > 10 || $held[$mult]) {
				$unvalued[] = $pick;
			}
			else {
				$held[$mult] = true;
			}
		}
		foreach ($unvalued as $pick) {
			$value = 0;
			for ($v = 1; $v <= 10; $v++) {
				if (!$held[$v]) {
					$held[$v] = true;
					$value = $v;
					break;
				}
			}
			if ((int) $pick->multiplier !== $value) {
				$pick->multiplier = $value;
				$pick->save();
			}
		}
		return true;
	}

	/**
	 * The season's players who do not have a side on every game of this week
	 * (a side is option '1', '2' or '3'; point values do not count), the
	 * admin picks page's SOME + NONE lists. One query.
	 *
	 * @return array of int user ids
	 */
	public function getIncompletePickerIds()
	{
		$num_games = (int) $this->games()->count();
		$week_id = (int) $this->id;
		$q = DB::table('football_users_seasons AS l')
			->leftJoin('football_bets AS b', function ($join) use ($week_id) {
				$join->on('b.user_id', '=', 'l.er_user_id')
					->whereIn('b.option', ['1', '2', '3'])
					->whereIn('b.football_game_id', function ($sub) use ($week_id) {
						$sub->select('id')
							->from('football_games')
							->where('football_week_id', '=', $week_id);
					});
			})
			->where('l.football_season_id', '=', (int) $this->football_season_id)
			->groupBy('l.er_user_id')
			->havingRaw('COUNT(b.id) < ?', [$num_games])
			->orderBy('l.er_user_id', 'ASC');
		$ids = [];
		foreach ($q->pluck('l.er_user_id') as $id) {
			$ids[] = (int) $id;
		}
		return $ids;
	}

	/**
	 * How long after a week's first kickoff the automatic fill may still run.
	 * A week's games span Thursday to Monday; a first view of the results
	 * later than this is of a week that is over or was never played.
	 */
	const AUTO_PICKS_WINDOW_DAYS = 7;

	/**
	 * The automatic "Randomize Remaining Picks" (docs/auto-picks.md): once
	 * the week has kicked off, gives every season player who is missing a
	 * side their random picks, exactly as the admin picks page does for one
	 * player at a time. Called before every results computation
	 * (Context::resultsBase() for the r/ pages and APIs, the classic
	 * season/week/results.php), so the first results view after the first
	 * kickoff already shows complete picks.
	 *
	 * Runs only while the week is live: first kickoff at or before now and
	 * within AUTO_PICKS_WINDOW_DAYS, and at least one game still undecided.
	 * A finished week is never rewritten, so a player left blank in a past
	 * week stays blank. Cheap when nothing is missing (two queries), which is
	 * every call but the first. A per-week file lock keeps two simultaneous
	 * first views from filling the same player twice. Never throws: a failure
	 * is logged and the results page renders with what there is (the admin
	 * picks page remains as the manual fallback).
	 *
	 * @return array  the user ids filled by this call, in id order
	 */
	public function randomizeRemainingPicks()
	{
		try {
			$agg = DB::table('football_games')
				->where('football_week_id', '=', (int) $this->id)
				->selectRaw("COUNT(*) AS n, SUM(correct_option = '0') AS undecided, MIN(CONCAT(`date`, ' ', IFNULL(`time`, '00:00:00'))) AS first_at")
				->first();
			if (!$agg || !(int) $agg->n || !(int) $agg->undecided || !$agg->first_at) {
				return [];
			}
			$first_at = strtotime($agg->first_at);
			$now = time();
			if (!$first_at || $first_at > $now || $first_at < $now - self::AUTO_PICKS_WINDOW_DAYS * 86400) {
				return [];
			}
			if (!sizeof($this->getIncompletePickerIds())) {
				return [];
			}
			$week = $this;
			return Cache::withLock('auto-picks-' . (int) $this->id, function () use ($week) {
				// Another request may have filled them while this one waited.
				$ids = $week->getIncompletePickerIds();
				$filled = [];
				foreach ($ids as $user_id) {
					if ($week->randomizePicksForUser($user_id)) {
						$filled[] = $user_id;
					}
				}
				if (sizeof($filled)) {
					$key = 'auto-picks-' . (int) $week->id;
					$runs = Cache::get($key);
					if (!is_array($runs)) {
						$runs = [];
					}
					$runs[] = ['at' => now(), 'user_ids' => $filled];
					Cache::set($key, $runs);
					error_log('pick55: auto-filled the remaining picks of week #' . (int) $week->id . ' for ' . sizeof($filled) . ' player(s): ' . implode(', ', $filled));
				}
				return $filled;
			});
		}
		catch (\Throwable $e) {
			error_log('pick55: auto-fill of week #' . (int) $this->id . ' picks failed: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * What randomizeRemainingPicks() has done for this week, for the admin
	 * picks page: a list of runs, each ['at' => datetime, 'user_ids' => [...]].
	 * From the file cache, so empty after a cache clear.
	 *
	 * @return array
	 */
	public function getAutoPickRuns()
	{
		$runs = Cache::get('auto-picks-' . (int) $this->id);
		return is_array($runs) ? $runs : [];
	}
}
