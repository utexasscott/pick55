<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Models\Season;
use Pick55\Models\Signup;
use Pick55\Models\UsersSeasonsLink;
use Pick55\R\Shell;

// Mirrors auth/verify.php: turn the signup into a user, link them to the
// active season when its first game has not been played yet, then sign in.
Shell::guardGuest();

$token = trim((string) get('token'));
$signup = null;
if (strlen($token) >= 16) {
	$signup = Signup::where('verify_token', 'LIKE', $token)
		->first();
}
if (!$signup) {
	redir('r/auth/signup.php');
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
			UsersSeasonsLink::firstOrCreate([
				'er_user_id' => $user->id,
				'football_season_id' => $season->id,
			]);
		}
	}
}
Alert::success("Your email has been verified! Please sign in.");
redir('r/auth/login.php');
