<?php

namespace Pick55\Models;

use Pick55\DB;

class Season extends BaseModel
{
	protected $table = 'football_seasons';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function weeks()
	{
		return $this->hasMany(Week::class, 'football_season_id')
			->orderBy('week_num');
	}

	/**
	 * @return Season or null
	 */
	public static function getActive()
	{
		return self::where('is_active', '=', 1)
			->orderBy('id', 'DESC')
			->first();
	}

	/**
	 * @return Season or null
	 */
	public static function getLatest()
	{
		return self::orderBy('id', 'DESC')
			->first();
	}

	/**
	 * Returns a list of seasons that are accessible by the given user ID.
	 *
	 * @param int or null $user_id
	 * @return array of Season
	 */
	public static function getListForUser($user_id = null)
	{
		$seasons = [];
		$active_season = self::getActive();
		if ($active_season) {
			$seasons[$active_season->id] = $active_season;
		}
		$links = UsersSeasonsLink::where('er_user_id', '=', $user_id)
			->orderBy('id', 'DESC')
			->get();
		foreach ($links as $link) {
			$seasons[$link->season->id] = $link->season;
		}
		return $seasons;
	}

	/**
	 * @return Week or null
	 */
	public function getActiveWeek()
	{
		// reorder(): weeks() already orders ascending, and a second orderBy
		// would come after it and lose (the classic results page showed
		// Week 1 while a later week was live, 2026-09-26).
		$weeks = $this->weeks()
			->reorder('week_num', 'DESC')
			->get();
		foreach ($weeks as $week) {
			if ($week->canPick()) {
				return $week;
			}
		}
		foreach ($weeks as $week) {
			if ($week->canSeeResults()) {
				return $week;
			}
		}
		foreach ($weeks as $week) {
			return $week;
		}
		return null;
	}

	/**
	 * @return Week or null
	 */
	public function getResultWeek()
	{
		// reorder(): weeks() already orders ascending, and a second orderBy
		// would come after it and lose (the classic results page showed
		// Week 1 while a later week was live, 2026-09-26).
		$weeks = $this->weeks()
			->reorder('week_num', 'DESC')
			->get();
		foreach ($weeks as $week) {
			if ($week->canSeeResults()) {
				return $week;
			}
		}
		return null;
	}

	/**
	 * @return Week or null
	 */
	public function getPickWeek()
	{
		// reorder(): weeks() already orders ascending, and a second orderBy
		// would come after it and lose (the classic results page showed
		// Week 1 while a later week was live, 2026-09-26).
		$weeks = $this->weeks()
			->reorder('week_num', 'DESC')
			->get();
		foreach ($weeks as $week) {
			if ($week->canPick()) {
				return $week;
			}
		}
		return null;
	}

	/**
	 * Returns the number of games associated with this season.
	 *
	 * @return int
	 */
	public function getNumGames()
	{
		return DB::table(Game::getTableName() . ' AS game')
			->leftJoin(Week::getTableName() . ' AS week', 'game.football_week_id', '=', 'week.id')
			->where('week.football_season_id', '=', $this->id)
			->count();
	}

	/**
	 * Returns the number of games associated with this season.
	 *
	 * @return int
	 */
	public function getNumPlayers()
	{
		return UsersSeasonsLink::where('football_season_id', '=', $this->id)
			->count();
	}

	// ------------------------------------------------------------------
	// Rules (docs/season-rules.md)
	// ------------------------------------------------------------------

	/**
	 * What each player's fee puts into each regular week's pot.
	 *
	 * @return float
	 */
	public function getWeeklyPot()
	{
		return $this->weekly_pot === null ? 10.0 : (float) $this->weekly_pot;
	}

	/**
	 * The rest of the fee: the playoff pot, or the season-standings pot when
	 * the season has no playoff weeks.
	 *
	 * @return float
	 */
	public function getFinalsPot()
	{
		return max(0, round((float) $this->fee - $this->getWeeklyPot() * (int) $this->num_weeks, 2));
	}

	/**
	 * @return int regular plus playoff weeks
	 */
	public function getNumWeeksTotal()
	{
		return (int) $this->num_weeks + (int) $this->playoff_weeks;
	}

	/**
	 * The knock-out round: the first playoff week whose format advances
	 * players. The number advancing lives on that format only.
	 *
	 * @return Week or null
	 */
	public function getKnockoutWeek()
	{
		foreach ($this->weeks as $week) {
			$format = $week->getFormat();
			if ($format && $format->is_playoffs && $format->advance) {
				return $week;
			}
		}
		return null;
	}

	/**
	 * @return int|null players advancing from the knock-out round
	 */
	public function getNumAdvancing()
	{
		$week = $this->getKnockoutWeek();
		return $week ? (int) $week->getFormat()->advance : null;
	}

	/**
	 * @return bool true if any regular week splits players into pools
	 */
	public function hasPools()
	{
		foreach ($this->weeks as $week) {
			if (!$week->isPlayoffs() && $week->getNumPools()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Kickoff of week 1's first game, when week 1's picks and fees are due.
	 *
	 * @return string datetime or null
	 */
	public function getStartsAt()
	{
		foreach ($this->weeks as $week) {
			return $week->getFirstGameAt();
		}
		return null;
	}

	/**
	 * The usual number of games in a week (the most common count among
	 * weeks that have games). A season with no games yet takes the latest
	 * earlier season's.
	 *
	 * @return int
	 */
	public function getGamesPerWeek()
	{
		$rows = DB::table(Week::getTableName() . ' AS week')
			->join(Game::getTableName() . ' AS game', 'game.football_week_id', '=', 'week.id')
			->where('week.football_season_id', '<=', $this->id)
			->groupBy('week.football_season_id', 'week.id')
			->orderBy('week.football_season_id', 'DESC')
			->select(['week.football_season_id AS season_id', DB::raw('COUNT(*) AS n')])
			->get();
		$counts = [];
		$season_id = null;
		foreach ($rows as $row) {
			if ($season_id !== null && (int) $row->season_id !== $season_id) {
				break;
			}
			$season_id = (int) $row->season_id;
			$n = (int) $row->n;
			$counts[$n] = isset($counts[$n]) ? $counts[$n] + 1 : 1;
		}
		$best = 14;
		$best_weeks = 0;
		foreach ($counts as $n => $weeks) {
			if ($weeks > $best_weeks || ($weeks == $best_weeks && $n > $best)) {
				$best = $n;
				$best_weeks = $weeks;
			}
		}
		return $best;
	}

	/**
	 * The knock-out round's guaranteed games by regular-season place, read
	 * from the football_guaranteed_points rows the admin created: rows are
	 * grouped by multipliers_less_than, highest first, and each group takes
	 * the next places in turn. The active season, whose rows are only made
	 * when its regular season ends, shows the latest earlier season's grid.
	 *
	 * @return array|null [
	 *   'season' => Season the rows came from,
	 *   'rows' => [['min' => 1, 'max' => 1, 'lt' => 11], ...],
	 *   'places' => the last place that gets any,
	 * ]
	 */
	public function getGuaranteeGrid()
	{
		$source = $this;
		$rows = self::guaranteeCounts($this->id);
		if (!sizeof($rows) && $this->is_active) {
			$prev_id = DB::table(Guarantee::getTableName() . ' AS gp')
				->join(Week::getTableName() . ' AS week', 'week.id', '=', 'gp.week_id')
				->where('week.football_season_id', '<', $this->id)
				->max('week.football_season_id');
			if ($prev_id) {
				$source = self::find($prev_id);
				$rows = self::guaranteeCounts($prev_id);
			}
		}
		if (!sizeof($rows)) {
			return null;
		}
		$grid = [];
		$place = 1;
		foreach ($rows as $row) {
			$grid[] = ['min' => $place, 'max' => $place + (int) $row->n - 1, 'lt' => (int) $row->lt];
			$place += (int) $row->n;
		}
		return ['season' => $source, 'rows' => $grid, 'places' => $place - 1];
	}

	/**
	 * @param int $season_id
	 * @return array of {lt, n}, highest lt first
	 */
	private static function guaranteeCounts($season_id)
	{
		return DB::table(Guarantee::getTableName() . ' AS gp')
			->join(Week::getTableName() . ' AS week', 'week.id', '=', 'gp.week_id')
			->where('week.football_season_id', '=', $season_id)
			->groupBy('gp.multipliers_less_than')
			->orderBy('gp.multipliers_less_than', 'DESC')
			->select(['gp.multipliers_less_than AS lt', DB::raw('COUNT(*) AS n')])
			->get()
			->all();
	}

	/**
	 * Returns true if the given week is in this season, false otherwise.
	 *
	 * @param int $week_id
	 * @return bool
	 */
	public function hasWeek($week_id)
	{
		return $this->weeks()
			->where('id', '=', $week_id)
			->count();
	}

	/**
	 * Returns true if the given user is a player in this season, false otherwise.
	 *
	 * @param int $user_id
	 */
	public function hasPlayer($user_id)
	{
		$link = UsersSeasonsLink::where('football_season_id', '=', $this->id)
			->where('er_user_id', '=', $user_id)
			->first();
		if ($link) {
			return true;
		}
		return false;
	}

	/**
	 * Returns an array of all the User players in this season.
	 *
	 * @return array of User
	 */
	public function getPlayers($paid_only = false)
	{
		$users = [];
		$user_ids = [];
		$q = UsersSeasonsLink::where('football_season_id', '=', $this->id);
		foreach ($q->cursor() as $link) {
			if ($paid_only && !$link->paid_at) {
				continue;
			}
			$user_ids[] = $link->er_user_id;
		}
		if (sizeof($user_ids)) {
			$users = User::whereIn('id', $user_ids)->get()->all();
		}
		$names = [];
		foreach ($users as $user) {
			$names[] = strtolower($user->getName());
		}
		array_multisort($names, SORT_ASC, $users);
		return $users;
	}
}
