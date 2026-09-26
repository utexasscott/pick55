<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\DB;
use Pick55\Models\Bet;
use Pick55\Models\Week;
use Pick55\R\Api;
use Pick55\R\Context;
use Pick55\R\Fmt;

// Saves a player's picks for a week (docs/redesign.md section 6).
//
// POST {week_id, picks: [{game_id, option, mult}]} (JSON)
//   -> {ok: true, saved_at, picks: [{game_id, option, mult}], badges, progress}
//
// The classic rules (season/week/save-picks.php): options 0-3, each point
// value 1-10 used once, everything else 0, and only while the week can be
// picked. Differences, all stricter or kinder: games outside the week are
// ignored; a repeated point value keeps its game's side and becomes 0
// (the classic page dropped the whole pick); a game listed twice keeps its
// first entry; games of the week missing from the body are reset to 0, as
// the classic reset did.
$me = Api::guard('POST');

/** An int from a JSON number or a digit string, else null. */
$to_int = function ($v) {
	if (is_int($v)) {
		return $v;
	}
	if (is_float($v) && floor($v) == $v) {
		return (int) $v;
	}
	if (is_string($v) && preg_match('/^\s*\d{1,9}\s*$/', $v)) {
		return (int) $v;
	}
	return null;
};

$week_id = $to_int(Api::input('week_id'));
$picks = Api::input('picks');
if (!$week_id) {
	Api::error('Which week? week_id is missing.', 400);
}
if (!is_array($picks)) {
	Api::error('picks must be a list.', 400);
}

$week = Week::find($week_id);
if (!$week || !$week->season) {
	Api::error('That week does not exist.', 404);
}
if (!$week->season->hasPlayer($me->id)) {
	Api::error('You are not a player in this season.', 403);
}
if (!$week->canPick()) {
	Api::json([
		'error' => 'Picks for this week are locked.',
		'locked' => true,
		'results' => $week->canSeeResults() ? config('base_url') . 'r/season/week/results.php?id=' . (int) $week->id : null,
	], 409);
}

// The week's games.
$week_game_ids = [];
foreach (DB::table('football_games')->where('football_week_id', '=', $week->id)->pluck('id') as $gid) {
	$week_game_ids[(int) $gid] = true;
}

// Normalise: first entry per game wins; options 0-3; each value 1-10 once.
$set = [];
$taken = [];
foreach ($picks as $pick) {
	if (!is_array($pick)) {
		continue;
	}
	$gid = $to_int(isset($pick['game_id']) ? $pick['game_id'] : null);
	if (!$gid || !isset($week_game_ids[$gid]) || isset($set[$gid])) {
		continue;
	}
	$option = $to_int(isset($pick['option']) ? $pick['option'] : 0);
	if ($option === null || !Bet::isValidOption($option)) {
		$option = 0;
	}
	$mult = $to_int(isset($pick['mult']) ? $pick['mult'] : 0);
	if ($mult === null || $mult < 1 || $mult > 10 || isset($taken[$mult])) {
		$mult = 0;
	}
	if ($mult > 0) {
		$taken[$mult] = true;
	}
	$set[$gid] = ['option' => $option, 'mult' => $mult];
}

$user_id = (int) $me->id;
DB::connection()->transaction(function () use ($user_id, $set, $week_game_ids) {
	// Missing bet rows for the games being set.
	$have = [];
	if (sizeof($set)) {
		$existing = DB::table('football_bets')
			->where('user_id', '=', $user_id)
			->whereIn('football_game_id', array_keys($set))
			->pluck('football_game_id');
		foreach ($existing as $gid) {
			$have[(int) $gid] = true;
		}
	}
	foreach (array_keys($set) as $gid) {
		if (!isset($have[$gid])) {
			Bet::firstOrCreate([
				'user_id' => $user_id,
				'football_game_id' => $gid,
			]);
		}
	}
	// Every game of the week: the value sent, or reset to nothing.
	foreach (array_keys($week_game_ids) as $gid) {
		$row = isset($set[$gid]) ? $set[$gid] : ['option' => 0, 'mult' => 0];
		DB::table('football_bets')
			->where('user_id', '=', $user_id)
			->where('football_game_id', '=', $gid)
			->update([
				'option' => (string) $row['option'],
				'multiplier' => (int) $row['mult'],
			]);
	}
});

Context::forget();

// What is stored now.
$out = [];
$rows = DB::table('football_bets')
	->where('user_id', '=', $user_id)
	->whereIn('football_game_id', array_keys($week_game_ids))
	->orderBy('multiplier', 'DESC')
	->orderBy('football_game_id', 'ASC')
	->get(['football_game_id', 'option', 'multiplier']);
foreach ($rows as $row) {
	$out[] = [
		'game_id' => (int) $row->football_game_id,
		'option' => (int) $row->option,
		'mult' => (int) $row->multiplier,
	];
}

$ctx = Context::get();
$progress = null;
if ($ctx->pick_week && (int) $ctx->pick_week->id === (int) $week->id && $ctx->my_picks_progress) {
	$progress = $ctx->my_picks_progress;
	$progress['due_at'] = Fmt::iso($progress['due_at']);
}

Api::json([
	'ok' => true,
	'saved_at' => Fmt::iso(time()),
	'picks' => $out,
	'badges' => (object) $ctx->badges(),
	'progress' => $progress,
]);
