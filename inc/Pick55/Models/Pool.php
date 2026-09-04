<?php

namespace Pick55\Models;

class Pool extends BaseModel
{
	protected $table = 'football_pools';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];
}
