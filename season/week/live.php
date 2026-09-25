<?php

/**
 * JSON for the results page's live-score refresh (docs/live-scores.md):
 *
 *   GET season/week/live.php?id=WEEK_ID
 *   {
 *     "week_id": 231, "fetched_at": "2026-09-20 15:10:02" | null, "any_live": false,
 *     "games": {
 *       "2389": {"away_score": 24, "home_score": 32, "state": "post", "completed": true,
 *                "label": "Final", "leading_option": "2", "correct_option": "2"},
 *       ...one entry per football_games row of the week; scores null and state "pre"
 *       when the cron has not written a row for the game yet
 *     }
 *   }
 *
 * Same access rules as results.php: logged in, a player of the week's season,
 * and the week's results visible. Errors are JSON with a 4xx status.
 */

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Auth;
use Pick55\Models\GameScore;
use Pick55\Models\Week;

header('Content-Type: application/json; charset=utf-8');

function live_error($status, $msg)
{
	http_response_code($status);
	print json_encode(['error' => $msg]);
	exit;
}

$me = Auth::user();
if (!$me) {
	live_error(401, 'Not logged in.');
}
$week = get('id') ? Week::find((int) get('id')) : null;
if (!$week || !$week->season) {
	live_error(404, 'Invalid week.');
}
if (!$week->season->hasPlayer($me->id)) {
	live_error(403, 'Unauthorized.');
}
if (!$week->canUserSeeResults($me->id)) {
	live_error(403, 'Results are not available yet.');
}

$scores = GameScore::forWeek($week->id);
$games = [];
$fetched_at = null;
$any_live = false;
$q = $week->games()
	->with(['awayTeam', 'homeTeam'])
	->orderBy('date', 'ASC')
	->orderBy('time', 'ASC');
foreach ($q->get() as $game) {
	$score = isset($scores[$game->id]) ? $scores[$game->id] : null;
	$entry = [
		'away_score' => null,
		'home_score' => null,
		'state' => GameScore::STATE_PRE,
		'completed' => false,
		'label' => '',
		'leading_option' => null,
		'correct_option' => (string) $game->correct_option,
	];
	if ($score) {
		$score->setRelation('game', $game);
		$entry['away_score'] = $score->away_score === null ? null : (int) $score->away_score;
		$entry['home_score'] = $score->home_score === null ? null : (int) $score->home_score;
		$entry['state'] = (string) $score->state;
		$entry['completed'] = (bool) $score->completed;
		$entry['label'] = $score->getStatusLabel();
		$entry['leading_option'] = $score->getLeadingOption();
		if ($score->isLive()) {
			$any_live = true;
		}
		if ($score->fetched_at && ($fetched_at === null || $score->fetched_at > $fetched_at)) {
			$fetched_at = $score->fetched_at;
		}
	}
	$games[(string) $game->id] = $entry;
}

print json_encode([
	'week_id' => (int) $week->id,
	'fetched_at' => $fetched_at,
	'any_live' => $any_live,
	'games' => $games,
]);
