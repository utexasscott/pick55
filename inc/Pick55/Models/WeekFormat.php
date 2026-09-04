<?php

namespace Pick55\Models;

class WeekFormat extends BaseModel
{
	protected $table = 'football_week_formats';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];
}
