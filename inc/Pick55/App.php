<?php

namespace Pick55;

use Pick55\Auth;
use Pick55\Models\Season;

class App
{
	private static $instance;
	public $season;

	/**
	 * @return App
	 */
	public static function get()
	{
		if (!isset(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 */
	public function __construct()
	{
		Auth::attemptCookieLogin();
		$this->season = null;
	}

	/**
	 * @param Season $season
	 * @return Season
	 */
	public function setSeason(Season $season)
	{
		$this->season = $season;
		return $this->season;
	}

	/**
	 * @param string $qs_key
	 * @return Season or null
	 */
	public function getSeason($qs_key = 'id')
	{
		if ($this->season) return $this->season;
		if (get($qs_key)) {
			// Specific season
			$season = Season::find(get($qs_key));
			if ($season) return $this->setSeason($season);
		}
		// Season fallbacks
		$season = Season::getActive();
		if ($season) return $this->setSeason($season);
		$season = Season::getLatest();
		if ($season) return $this->setSeason($season);
		return null;
	}

	/**
	 * @return Week or null
	 */
	public function getPickableWeek()
	{
		if (!Auth::authed()) {
			return null;
		}
		$me = Auth::user();
		$season = Season::getActive();
		if (!$season) {
			return null;
		}
		foreach ($season->weeks as $week) {
			if ($week->canUserPick($me->id)) {
				return $week;
			}
		}
		return null;
	}

	/**
	 * @return Week or null
	 */
	public function getViewableWeek()
	{
		if (!Auth::authed()) {
			return null;
		}
		$me = Auth::user();
		$season = Season::getActive();
		if (!$season) {
			return null;
		}
		foreach ($season->weeks as $week) {
			if ($week->canUserSeeResults($me->id)) {
				return $week;
			}
		}
		return null;
	}
}
