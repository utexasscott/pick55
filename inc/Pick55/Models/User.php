<?php

namespace Pick55\Models;

use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\UserFriendLink;
use Pick55\DB;

class User extends BaseModel
{
	protected $table = 'er_users';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];
	
	public function userFriendLinks()
	{
		return $this->hasMany(UserFriendLink::class, 'er_user_id');
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->getName();
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return trim(implode(' ', [
			$this->first_name,
			$this->last_name,
		]));
	}

	public function getFullDisplay()
	{
		return trim($this->getName() . ' &lt;' . $this->email . '&gt;');
	}

	/**
	 * @return string
	 */
	public function getDisplayName()
	{
		$last = trim(substr($this->last_name, 0, 2));
		if (strlen($this->last_name) > 2) {
			$last .= '.';
		}
		$str = trim(implode(' ', [
			$this->first_name,
			$last,
		]));
		if (strlen($str)) {
			return $str;
		}
		return 'User #' . $this->id;
	}

	/**
	 * @param int $week_id
	 * @param bool $all
	 * @return bool
	 */
	public function randomizePicksForWeek($week_id, $all = false)
	{
		$week = Week::find($week_id);
		if (!$week) {
			return false;
		}

		$games = $week->games()
			->inRandomOrder()
			->get();
		$multipliers_set = [];
		$multipliers_unset = [];
		foreach (range(0,10) as $i) {
			$multipliers_set[$i] = false;
		}

		foreach ($games as $game) {
			$pick = Bet::firstOrCreate([
				'user_id' => $this->id,
				'football_game_id' => $game->id,
			]);
			if ($all || $pick->option == '0' || !$pick->option) {
				$pick->option = (string) round(rand(1, 2));
			}
			$pick->save();
			if ($multipliers_set[$pick->multiplier]) {
				$multipliers_unset[] = $pick;
				$pick->multiplier = 0;
				$pick->save();
			}
			else {
				$multipliers_set[$pick->multiplier] = $pick;
			}
		}
		foreach ($multipliers_unset as $pick) {
			foreach ($multipliers_set as $mult => $set_pick) {
				if (!$set_pick) {
					$pick->multiplier = $mult;
					$pick->save();
					$multipliers_set[$mult] = $pick;
					break;
				}
			}
		}
		return true;
	}

	/**
	 * @param int $season_id
	 * @return bool
	 */
	public function isActiveForSeason($season_id)
	{
		$season = Season::find($season_id);
		if (!$season) {
			return false;
		}
		return UsersSeasonsLink::where('er_user_id', '=', $this->id)
			->where('football_season_id', '=', $season->id)
			->first() ? true : false;
	}

	/**
	 * @return arr of User
	 */
	public function getFriends()
	{
		$friends = [];
		$q = UserFriendLink::where('er_user_id', '=', $this->id);
		foreach ($q->cursor() as $link) {
			$friends[] = $link->friend;
		}
		$names = [];
		foreach ($friends as $friend) {
			$names[] = $friend->getName();
		}
		array_multisort($names, SORT_ASC, $friends);
		return $friends;
	}

	/**
	 * @return arr of User
	 */
	public function getFriendedUsers()
	{
		$friends = [];
		$q = UserFriendLink::where('friend_er_user_id', '=', $this->id);
		foreach ($q->cursor() as $link) {
			$friends[] = $link->user;
		}
		$names = [];
		foreach ($friends as $friend) {
			$names[] = $friend->getName();
		}
		array_multisort($names, SORT_ASC, $friends);
		return $friends;
	}

	/**
	 * @return int $num_friends
	 */
	public function getNumFriends()
	{
		return sizeof($this->getFriends()) + sizeof($this->getFriendedUsers());
	}
	
	/**
	 * @return arr $num_friends_per_pool [pool_id => num_friends]
	 */
	public function getNumFriendsPerPool($week_id)
	{
		$my_friend_ids = DB::table('er_users_friends')
			->where('er_user_id', '=', $this->id)
			->pluck('friend_er_user_id')
			->toArray();
		$friend_of_mine_ids = DB::table('er_users_friends')
			->where('friend_er_user_id', '=', $this->id)
			->pluck('er_user_id')
			->toArray();
		$pool_ids = DB::table('football_pools')
			->where('week_id', '=', $week_id)
			->pluck('id');
		$pool_users = DB::table('football_pool_users')
			->whereIn('pool_id', $pool_ids)
			->select([
				'er_user_id',
				'pool_id',
			])
			->get();
		foreach ($pool_ids as $pool_id) {
			$num_friends_per_pool[$pool_id] = 0;
		}
		foreach ($pool_users as $pool_user) {
			if (in_array($pool_user->er_user_id, $my_friend_ids)) {
				$num_friends_per_pool[$pool_user->pool_id]++;
			}
			if (in_array($pool_user->er_user_id, $friend_of_mine_ids)) {
				$num_friends_per_pool[$pool_user->pool_id]++;
			}
		}
		return $num_friends_per_pool;
	}
}
