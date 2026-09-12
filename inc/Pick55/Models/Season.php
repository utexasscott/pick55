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
		return $this->hasMany(Week::class, 'football_season_id');
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
		$weeks = $this->weeks()
			->orderBy('week_num', 'DESC')
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
		$weeks = $this->weeks()
			->orderBy('week_num', 'DESC')
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
		$weeks = $this->weeks()
			->orderBy('week_num', 'DESC')
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
