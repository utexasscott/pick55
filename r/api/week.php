<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Models\Game;
use Pick55\Models\GameScore;
use Pick55\Models\Team;
use Pick55\Models\Week;
use Pick55\R\Api;
use Pick55\R\Context;
use Pick55\R\Fmt;

/*
 * The results page's live refresh (docs/redesign.md section 6):
 *
 *   GET api/week.php?id=<week_id>&pool=<pool_id|0>&g<game_id>=1|2
 *
 * Same access rules as the page (a player of the week's season, results
 * visible). Scores come from football_game_scores (read every call); the
 * standings from WeekResults::get with exactly the page's arguments, so the
 * cached entry is shared and a poll costs the fingerprint queries only.
 */

$me = Api::guard('GET');

$week = get('id') ? Week::find((int) get('id')) : null;
if (!$week || !$week->season) {
	Api::error('Week not found.', 404);
}
if (!$week->season->hasPlayer($me->id)) {
	Api::error('You are not in this season.', 403);
}
if (!$week->canUserSeeResults($me->id)) {
	Api::error('Results are not available yet.', 403);
}

// Players, pools and results exactly as the page computes them.
$what_ifs = [];
foreach ($_GET as $k => $v) {
	if (is_string($v) && preg_match('/^g(\d+)$/', $k, $m) && in_array($v, ['1', '2'], true)) {
		$what_ifs[$m[1]] = $v;
	}
}
$rf = Context::get()->resultsFor($week, get('pool', null), $what_ifs);
$selected_pool_id = $rf['selected_pool_id'];
$overall = $rf['overall'];
$results = $rf['results'];

// Games: the stored result and the live score.
$scores = [];
try {
	$scores = GameScore::forWeek($week->id);
}
catch (\Throwable $e) {
	$scores = [];
}
$games = [];
foreach (Game::hydrate($results['games']) as $game) {
	$games[(int) $game->id] = $game;
}
$teams = [];
$team_ids = [];
foreach ($games as $game) {
	$team_ids[(int) $game->away_team_id] = true;
	$team_ids[(int) $game->home_team_id] = true;
}
unset($team_ids[0]);
if (sizeof($team_ids)) {
	foreach (Team::whereIn('id', array_keys($team_ids))->get() as $team) {
		$teams[(int) $team->id] = $team;
	}
}

$out_games = [];
$any_live = false;
$fetched_at = null;
$counts = ['in' => 0, 'final' => 0, 'upcoming' => 0];
foreach ($games as $id => $game) {
	if (isset($teams[(int) $game->away_team_id])) {
		$game->setRelation('awayTeam', $teams[(int) $game->away_team_id]);
	}
	if (isset($teams[(int) $game->home_team_id])) {
		$game->setRelation('homeTeam', $teams[(int) $game->home_team_id]);
	}
	$decided = $game->correct_option != '0';
	$entry = [
		'away_score' => null,
		'home_score' => null,
		'state' => GameScore::STATE_PRE,
		'completed' => false,
		'label' => '',
		'leading_option' => null,
		'correct_option' => (string) $game->correct_option,
	];
	$score = isset($scores[$id]) ? $scores[$id] : null;
	if ($score) {
		$score->setRelation('game', $game);
		if ($score->hasScore()) {
			$entry['away_score'] = (int) $score->away_score;
			$entry['home_score'] = (int) $score->home_score;
		}
		$entry['state'] = (string) $score->state;
		$entry['completed'] = (bool) $score->completed;
		$entry['label'] = $score->getStatusLabel();
		$entry['leading_option'] = $decided ? null : $score->getLeadingOption();
		if ($score->isLive()) {
			$any_live = true;
		}
		if ($score->fetched_at && ($fetched_at === null || $score->fetched_at > $fetched_at)) {
			$fetched_at = $score->fetched_at;
		}
	}
	if ($decided && $entry['state'] === GameScore::STATE_PRE) {
		$entry['state'] = GameScore::STATE_POST;
		$entry['label'] = 'Final';
	}
	if ($decided || $entry['state'] === GameScore::STATE_POST) {
		$counts['final']++;
	}
	elseif ($entry['state'] === GameScore::STATE_IN) {
		$counts['in']++;
	}
	else {
		$counts['upcoming']++;
	}
	$out_games[(string) $id] = $entry;
}

// Standings of the view; money from the overall computation.
$standings = [];
foreach ($results['stats_by_user_id'] as $u_user_id => $stats) {
	$over = isset($overall['stats_by_user_id'][$u_user_id]) ? $overall['stats_by_user_id'][$u_user_id] : null;
	$standings[] = [
		'user_id' => (int) substr($u_user_id, 1),
		'rank' => (int) $stats['rank'],
		'points' => (int) $stats['points'],
		'right' => (int) $stats['right'],
		'wrong' => (int) $stats['wrong'],
		'expected' => $over ? round((float) $over['expected_payout'], 2) : 0.0,
		'payout' => $over ? round((float) $over['payout'], 2) : 0.0,
	];
}

Api::json([
	'week_id' => (int) $week->id,
	'pool_id' => (int) $selected_pool_id,
	'fetched_at' => $fetched_at ? Fmt::iso($fetched_at) : null,
	'any_live' => $any_live,
	'num_unknowns' => (int) $overall['num_unknowns'],
	'in_play' => $counts['in'],
	'final' => $counts['final'],
	'upcoming' => $counts['upcoming'],
	'games' => (object) $out_games,
	'standings' => $standings,
]);
