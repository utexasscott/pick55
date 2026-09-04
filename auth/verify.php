<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Models\Season;
use Pick55\Models\Signup;
use Pick55\Models\UsersSeasonsLink;

Auth::guardGuest();

$signup = Signup::where('verify_token', 'LIKE', get('token'))
	->first();
if (!$signup) {
	redir('auth/signup.php');
}
$user = Auth::convertSignup($signup);
$season = Season::getActive();
if ($season) {
	$first_week = $season->weeks()
		->where('week_num', '=', 1)
		->first();
	if ($first_week) {
		$first_game_at = $first_week->getFirstGameAt();
		if ($first_game_at && strtotime($first_game_at) && strtotime($first_game_at) > time()) {
			// auto link
			UsersSeasonsLink::firstOrCreate([
				'er_user_id' => $user->id,
				'football_season_id' => $season->id,
			]);
		}
	}
}
Alert::success("Your email has been verified! Please sign in.");
redir('auth/login.php');
