<?php

namespace Pick55\Models;

class Signup extends BaseModel
{
	protected $table = 'signups';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = true;
	protected $guarded = [];
}
