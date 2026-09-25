<?php

namespace Pick55\Models;

class Game extends BaseModel
{
	const LEAGUE_NFL = 'NFL';
	const LEAGUE_NCAA = 'NCAA';
	const BET_TYPE_OVER_UNDER = 'over-under';
	const BET_TYPE_SPREAD = 'spread';

	protected $table = 'football_games';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function week()
	{
		return $this->belongsTo(Week::class, 'football_week_id');
	}

	public function homeTeam()
	{
		return $this->belongsTo(Team::class, 'home_team_id');
	}

	public function awayTeam()
	{
		return $this->belongsTo(Team::class, 'away_team_id');
	}

	public function bets()
	{
		return $this->hasMany(Bet::class, 'football_game_id');
	}

	/**
	 * The live/final ESPN score row, if scrape/live-scores.php has written one.
	 */
	public function score()
	{
		return $this->hasOne(GameScore::class, 'football_game_id');
	}

	/**
	 * @return array of string
	 */
	public static function getLeagues()
	{
		return [
			self::LEAGUE_NFL,
			self::LEAGUE_NCAA,
		];
	}

	/**
	 * @return array of string
	 */
	public static function getPickTypes()
	{
		return [
			self::BET_TYPE_OVER_UNDER,
			self::BET_TYPE_SPREAD,
		];
	}
}
