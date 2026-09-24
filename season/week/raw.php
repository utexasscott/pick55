<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\App;
use Pick55\Auth;
use Pick55\Models\Week;

Auth::guard();

$week = null;
if (get('id')) {
	$week = Week::find(get('id'));
}
if (!$week) {
	$app = App::get();
	$season = $app->getSeason();
	if (!$season) {
		http_response_code(404);
		header('Content-Type: application/json');
		print json_encode(['error' => 'No active season']);
		exit;
	}
	$week = $season->getPickWeek();
	if (!$week) {
		http_response_code(404);
		header('Content-Type: application/json');
		print json_encode(['error' => 'No active week']);
		exit;
	}
}

$me = Auth::user();
if (!$week->season->hasPlayer($me->id)) {
	http_response_code(403);
	header('Content-Type: application/json');
	print json_encode(['error' => 'Unauthorized']);
	exit;
}

$games = [];
foreach ($week->games()->orderBy('date')->orderBy('time')->get() as $game) {
	$games[] = [
		'id'            => $game->id,
		'title'         => $game->title,
		'type'          => $game->type,
		'bet_type'      => $game->bet_type,
		'date'          => $game->date,
		'time'          => $game->time,
		'option_1'      => $game->option_1,
		'option_2'      => $game->option_2,
		'correct_option' => $game->correct_option,
		'away_team_id'  => $game->away_team_id,
		'home_team_id'  => $game->home_team_id,
	];
}

header('Content-Type: application/json');
print json_encode([
	'week' => [
		'id'       => $week->id,
		'week_num' => $week->week_num,
	],
	'games' => $games,
]);
