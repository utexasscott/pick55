<?php

namespace Pick55\Models;

abstract class BaseModel extends \Illuminate\Database\Eloquent\Model
{
	public static function getTableName()
	{
		return with(new static)->getTable();
	}
}
