<?php

namespace Pick55\Models;

class Guarantee extends BaseModel
{
	protected $table = 'football_guaranteed_points';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function user()
	{
		return $this->belongsTo(User::class, 'user_id');
	}

	public function week()
	{
		return $this->belongsTo(Week::class, 'football_week_id');
	}
}
