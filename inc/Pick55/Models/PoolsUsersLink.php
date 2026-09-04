<?php

namespace Pick55\Models;

class PoolsUsersLink extends BaseModel
{
	protected $table = 'football_pool_users';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function user()
	{
		return $this->belongsTo(User::class, 'er_user_id');
	}

	public function week()
	{
		return $this->belongsTo(Week::class, 'week_id');
	}

	public function pool()
	{
		return $this->belongsTo(Pool::class, 'pool_id');
	}
}
