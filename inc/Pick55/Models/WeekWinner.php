<?php

namespace Pick55\Models;

class WeekWinner extends BaseModel
{
	protected $table = 'football_week_winners';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function week()
	{
		return $this->belongsTo(Week::class, 'week_id');
	}

	public function user()
	{
		return $this->belongsTo(User::class, 'er_user_id');
	}
}
