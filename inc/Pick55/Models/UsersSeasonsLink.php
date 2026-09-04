<?php

namespace Pick55\Models;

class UsersSeasonsLink extends BaseModel
{
	protected $table = 'football_users_seasons';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function season()
	{
		return $this->belongsTo(Season::class, 'football_season_id');
	}

	public function user()
	{
		return $this->belongsTo(User::class, 'er_user_id');
	}
}
