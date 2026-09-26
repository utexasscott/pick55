<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Models\User;
use Pick55\Models\UserFriendLink;
use Pick55\R\Api;
use Pick55\R\Context;

// Add or remove a friend (docs/redesign.md section 6).
//   POST {action: 'add'|'remove', user_id}
//   -> {ok: true, friends: [{id, name}], suggested: [{id, name}]}
// Removing someone who is not a friend, or adding one twice, is not an
// error: the reply is the current state either way (undo relies on it).
$me = Api::guard('POST');

$action = (string) Api::input('action', '');
$user_id = Api::input('user_id');
if (!in_array($action, ['add', 'remove'], true)) {
	Api::error('Unknown action.');
}
if (!(is_int($user_id) || (is_string($user_id) && ctype_digit($user_id))) || (int) $user_id <= 0) {
	Api::error('Invalid player.');
}
$user_id = (int) $user_id;
if ($user_id === (int) $me->id) {
	Api::error('You cannot friend yourself.');
}
if (!User::where('id', '=', $user_id)->exists()) {
	Api::error('Invalid player.', 404);
}

if ($action === 'add') {
	UserFriendLink::firstOrCreate([
		'er_user_id' => (int) $me->id,
		'friend_er_user_id' => $user_id,
	]);
}
else {
	UserFriendLink::where('er_user_id', '=', $me->id)
		->where('friend_er_user_id', '=', $user_id)
		->delete();
}
Context::forget();

// The same lists r/account/index.php renders: my friends, and the players
// who friended me that I have not friended back. Two link queries and two
// user queries (User::getFriends() would load one user per link).
$friend_ids = array_map('intval', UserFriendLink::where('er_user_id', '=', $me->id)
	->pluck('friend_er_user_id')
	->all());
$fan_ids = array_map('intval', UserFriendLink::where('friend_er_user_id', '=', $me->id)
	->pluck('er_user_id')
	->all());
$suggested_ids = array_values(array_diff($fan_ids, $friend_ids, [(int) $me->id]));

/**
 * @param array $ids
 * @return array list of [id, name], by name
 */
$pack = function (array $ids) {
	$out = [];
	if (sizeof($ids)) {
		foreach (User::whereIn('id', $ids)->get() as $u) {
			$out[] = ['id' => (int) $u->id, 'name' => $u->getDisplayName()];
		}
	}
	usort($out, function ($a, $b) {
		return strcasecmp($a['name'], $b['name']);
	});
	return $out;
};

Api::json([
	'ok' => true,
	'action' => $action,
	'user_id' => $user_id,
	'friends' => $pack($friend_ids),
	'suggested' => $pack($suggested_ids),
]);
