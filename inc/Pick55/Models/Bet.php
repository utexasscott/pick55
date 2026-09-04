<?php

namespace Pick55\Models;

class Bet extends BaseModel
{
	protected $table = 'football_bets';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function user()
	{
		return $this->belongsTo(User::class, 'user_id');
	}

	public function game()
	{
		return $this->belongsTo(Game::class, 'football_game_id');
	}

	/**
	 * @param string or int $option
	 * @return bool
	 */
	public static function isValidOption($option)
	{
		return in_array(intval($option), range(0, 3));
	}

	/**
	 * @param string or int $mult
	 * @return bool
	 */
	public static function isValidMultiplier($mult)
	{
		return in_array(intval($mult), range(0, 10));
	}
}
