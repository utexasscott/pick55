<?php

namespace Pick55\Models;

class UserFriendLink extends BaseModel
{
	protected $table = 'er_users_friends';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function user()
	{
		return $this->belongsTo(User::class, 'er_user_id');
	}

	public function friend()
	{
		return $this->belongsTo(User::class, 'friend_er_user_id');
	}
}
