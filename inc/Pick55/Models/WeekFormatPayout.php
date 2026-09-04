<?php

namespace Pick55\Models;

class WeekFormatPayout extends BaseModel
{
	protected $table = 'football_week_format_payouts';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function format()
	{
		return $this->belongsTo(WeekFormat::class, 'football_week_format_id');
	}
}
