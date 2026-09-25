<?php

/**
 * Slate helper for the /pick-games skill (.claude/skills/pick-games/SKILL.md).
 *
 * Turns the newest VegasInsider scrape into a decision board (who is ranked, which time slot,
 * which games are eligible under the pool's rules) and turns a chosen slate into the exact
 * football_games INSERTs that admin/weeks/week/bulk-games.php would have written.
 *
 *   php scrape/slate.php board [--rankings=FILE.json] [--teams=FILE.tsv] [--allow-early]
 *   php scrape/slate.php sql --week=ID [--teams=FILE.tsv] --pick=NCAA:texas@tennessee:spread ...
 *   php scrape/slate.php sql --week=ID [--teams=FILE.tsv] --slate=FILE      (one pick per line)
 *
 * --rankings  JSON object {"<vegas slug>": <AP rank>, ...}; unranked slugs are simply absent.
 * --teams     TSV with header id/type/team/nickname/vegas_insider_url, dumped from production
 *             (see the skill). Without it the local database's football_teams is used.
 * --allow-early  treat Thursday/Friday games as eligible (Thanksgiving week is detected anyway).
 *
 * Pick keys are LEAGUE:<away slug>@<home slug>:<spread|over-under>, stable across re-scrapes
 * (bulk-games.php keys by row index, which is not).
 *
 * Runs under PHP 7.4 locally; keep the syntax 7.4-safe. Nothing here writes to any database.
 */

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\VegasInsider;
use Pick55\Models\Game;
use Pick55\Models\Team;
use Pick55\Models\Week;

const NCAA_MAX_SPREAD = 14;

$args = array_slice($argv, 1);
$command = null;
$opts = ['pick' => []];
foreach ($args as $arg) {
	if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
		$name = $m[1];
		$value = isset($m[2]) ? $m[2] : true;
		if ($name == 'pick') {
			$opts['pick'][] = $value;
		}
		else {
			$opts[$name] = $value;
		}
	}
	elseif ($command === null) {
		$command = $arg;
	}
}

if (!in_array($command, ['board', 'sql'])) {
	fwrite(STDERR, "Usage: php scrape/slate.php board|sql [options]  (see the file header)\n");
	exit(2);
}

/**
 * @return array league => slug => ['id' => int, 'type' => string, 'team' => string, 'nickname' => string]
 */
function load_teams(array $opts)
{
	$teams = [];
	if (!empty($opts['teams'])) {
		$lines = file($opts['teams'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			fwrite(STDERR, "Cannot read --teams file " . $opts['teams'] . "\n");
			exit(2);
		}
		$header = null;
		foreach ($lines as $line) {
			if (substr($line, 0, 2) == '**') {
				continue; // ssh banner lines that leaked into the dump
			}
			$cols = explode("\t", $line);
			if ($header === null) {
				$header = $cols;
				continue;
			}
			$row = [];
			foreach ($header as $i => $name) {
				$row[$name] = isset($cols[$i]) ? $cols[$i] : '';
			}
			if ($row['vegas_insider_url'] !== '') {
				$teams[$row['type']][strtolower($row['vegas_insider_url'])] = [
					'id' => (int) $row['id'],
					'type' => $row['type'],
					'team' => $row['team'],
					'nickname' => $row['nickname'],
				];
			}
		}
		return $teams;
	}
	foreach (Team::all() as $team) {
		if ($team->vegas_insider_url) {
			$teams[$team->type][strtolower($team->vegas_insider_url)] = [
				'id' => (int) $team->id,
				'type' => $team->type,
				'team' => $team->team,
				'nickname' => $team->nickname,
			];
		}
	}
	return $teams;
}

/**
 * @return array slug => rank
 */
function load_rankings(array $opts)
{
	if (empty($opts['rankings'])) {
		return [];
	}
	$json = json_decode((string) file_get_contents($opts['rankings']), true);
	if (!is_array($json)) {
		fwrite(STDERR, "--rankings is not a JSON object of slug => rank\n");
		exit(2);
	}
	$ranks = [];
	foreach ($json as $slug => $rank) {
		$ranks[strtolower($slug)] = (int) $rank;
	}
	return $ranks;
}

/**
 * Thanksgiving Thursday (4th Thursday of November) or the Friday after it.
 */
function is_thanksgiving_window($date)
{
	$ts = strtotime($date);
	if (date('n', $ts) != 11) {
		return false;
	}
	$dow = date('N', $ts); // 1 Mon .. 7 Sun
	$day = (int) date('j', $ts);
	if ($dow == 4) {
		return $day >= 22 && $day <= 28;
	}
	if ($dow == 5) {
		return $day >= 23 && $day <= 29;
	}
	return false;
}

/**
 * @return string slot label
 */
function slot_of($league, $date, $time)
{
	$ts = strtotime($date . ' ' . $time);
	$dow = date('D', $ts);
	$hm = (int) date('Gi', $ts);
	if ($dow == 'Thu' || $dow == 'Fri') {
		return strtoupper($dow);
	}
	if ($league == Game::LEAGUE_NFL) {
		if ($dow == 'Mon') {
			return 'MNF';
		}
		if ($dow == 'Sat') {
			return 'SAT';
		}
		if ($hm < 1100) {
			return 'SUN-AM';
		}
		if ($hm < 1400) {
			return 'NOON';
		}
		if ($hm < 1800) {
			return '3PM';
		}
		return 'SNF';
	}
	if ($dow == 'Sat') {
		if ($hm < 1300) {
			return 'SAT-11AM';
		}
		if ($hm < 1700) {
			return 'SAT-2:30PM';
		}
		if ($hm < 2000) {
			return 'SAT-6:30PM';
		}
		return 'SAT-LATE';
	}
	return strtoupper($dow);
}

/**
 * Annotates the newest scrape of a league.
 *
 * @return array|null ['ts' => int, 'json' => path, 'games' => [key => game]]
 */
function load_board($league, array $teams, array $ranks, $allow_early)
{
	$scrape = VegasInsider::newest($league);
	if (!$scrape) {
		return null;
	}
	$games = [];
	foreach ($scrape['games'] as $g) {
		$key = $g['away_team'] . '@' . $g['home_team'];
		$g['key'] = $key;
		$g['away'] = isset($teams[$league][$g['away_team']]) ? $teams[$league][$g['away_team']] : null;
		$g['home'] = isset($teams[$league][$g['home_team']]) ? $teams[$league][$g['home_team']] : null;
		$g['matched'] = $g['away'] && $g['home'];
		$g['away_rank'] = isset($ranks[$g['away_team']]) ? $ranks[$g['away_team']] : null;
		$g['home_rank'] = isset($ranks[$g['home_team']]) ? $ranks[$g['home_team']] : null;
		$g['slot'] = slot_of($league, $g['date'], $g['time']);
		$g['kickoff_ts'] = strtotime($g['date'] . ' ' . $g['time']);
		$g['early'] = in_array($g['slot'], ['THU', 'FRI']);
		$g['thanksgiving'] = is_thanksgiving_window($g['date']);
		$reasons = [];
		if ($g['early'] && !$g['thanksgiving'] && !$allow_early) {
			$reasons[] = 'before Saturday';
		}
		if (!$g['matched']) {
			$reasons[] = 'team not in football_teams';
		}
		if ($g['kickoff_ts'] < time()) {
			$reasons[] = 'already started';
		}
		if ($league == Game::LEAGUE_NCAA) {
			if ($g['away_rank'] === null && $g['home_rank'] === null) {
				$reasons[] = 'nobody ranked';
			}
			if ($g['spread'] !== null && abs($g['spread']) >= NCAA_MAX_SPREAD) {
				$reasons[] = 'spread ' . abs($g['spread']) . ' >= ' . NCAA_MAX_SPREAD;
			}
		}
		$g['ineligible'] = $reasons;
		$g['score'] = big_game_score($league, $g);
		$games[$key] = $g;
	}
	$scrape['games'] = $games;
	return $scrape;
}

/**
 * A rough "how big is this game" number, higher is bigger. It orders the candidate list;
 * the skill's judgment (records, storylines, slot needs) decides the final slate.
 */
function big_game_score($league, array $g)
{
	$spread = $g['spread'] === null ? 20 : abs($g['spread']);
	if ($league == Game::LEAGUE_NCAA) {
		$a = $g['away_rank'];
		$h = $g['home_rank'];
		if ($a !== null && $h !== null) {
			return round(100 - ($a + $h) - $spread / 2, 1);
		}
		if ($a !== null || $h !== null) {
			$r = $a !== null ? $a : $h;
			return round(50 - $r - $spread, 1);
		}
		return round(-$spread, 1);
	}
	$prime = in_array($g['slot'], ['SNF', 'MNF']) ? 6 : 0;
	return round(20 - $spread + $prime, 1);
}

function fmt_line($value, $is_spread)
{
	if ($value === null) {
		return '-';
	}
	if ($is_spread) {
		return ($value > 0 ? '+' : '') . number_format($value, 1);
	}
	return number_format($value, 1);
}

function fmt_team(array $g, $side)
{
	$rank = $g[$side . '_rank'];
	$name = $g[$side] ? trim($g[$side]['team'] . ' ' . $g[$side]['nickname']) : $g[$side . '_name'] . ' [NO TEAM ROW]';
	return ($rank !== null ? '#' . $rank . ' ' : '') . $name;
}

function print_table(array $rows, array $headers)
{
	$widths = [];
	foreach ($headers as $i => $h) {
		$widths[$i] = mb_strlen($h);
	}
	foreach ($rows as $row) {
		foreach ($row as $i => $cell) {
			$widths[$i] = max($widths[$i], mb_strlen((string) $cell));
		}
	}
	$line = function (array $cells) use ($widths) {
		$out = [];
		foreach ($cells as $i => $cell) {
			$out[] = str_pad((string) $cell, $widths[$i]);
		}
		return rtrim(implode('  ', $out));
	};
	print $line($headers) . "\n";
	print str_repeat('-', array_sum($widths) + 2 * (sizeof($widths) - 1)) . "\n";
	foreach ($rows as $row) {
		print $line($row) . "\n";
	}
}

$teams = load_teams($opts);
$ranks = load_rankings($opts);
$allow_early = !empty($opts['allow-early']);

if ($command == 'board') {
	if (!$ranks) {
		print "NOTE: no --rankings given, so every NCAA game reads as 'nobody ranked'.\n\n";
	}
	foreach (Game::getLeagues() as $league) {
		$board = load_board($league, $teams, $ranks, $allow_early);
		print "=== " . $league . " ===\n";
		if (!$board) {
			print "No scrape on disk. Run: php scrape/run.php --force\n\n";
			continue;
		}
		print "Scrape " . date('D m/d g:i A', $board['ts']) . " (" . sizeof($board['games']) . " games) " . $board['json'] . "\n\n";
		$rows = [];
		foreach ($board['games'] as $g) {
			$rows[] = [
				date('D m/d H:i', $g['kickoff_ts']),
				$g['slot'],
				$g['key'],
				fmt_team($g, 'away') . ' @ ' . fmt_team($g, 'home'),
				fmt_line($g['spread'], true),
				fmt_line($g['over-under'], false),
				$g['ineligible'] ? 'NO: ' . implode('; ', $g['ineligible']) : 'yes',
			];
		}
		print_table($rows, ['Kickoff (CT)', 'Slot', 'Key', 'Matchup', 'Spread', 'Total', 'Eligible']);

		$candidates = array_filter($board['games'], function ($g) {
			return !$g['ineligible'];
		});
		usort($candidates, function ($a, $b) {
			if ($a['score'] == $b['score']) {
				return $a['kickoff_ts'] - $b['kickoff_ts'];
			}
			return $b['score'] > $a['score'] ? 1 : -1;
		});
		print "\nEligible " . $league . " games by big-game score (" . sizeof($candidates) . "):\n";
		$rows = [];
		foreach ($candidates as $g) {
			$must = [];
			if ($league == Game::LEAGUE_NFL && $g['slot'] == 'SNF') {
				$must[] = 'SNF: always in';
			}
			if ($league == Game::LEAGUE_NFL && $g['slot'] == 'MNF') {
				$must[] = 'MNF: always in';
			}
			if ($g['thanksgiving']) {
				$must[] = 'Thanksgiving window';
			}
			$rows[] = [
				$g['score'],
				$g['slot'],
				$g['key'],
				fmt_team($g, 'away') . ' @ ' . fmt_team($g, 'home'),
				fmt_line($g['spread'], true),
				fmt_line($g['over-under'], false),
				implode('; ', $must),
			];
		}
		print_table($rows, ['Score', 'Slot', 'Key', 'Matchup', 'Spread', 'Total', 'Notes']);
		$slots = [];
		foreach ($candidates as $g) {
			$slots[$g['slot']] = isset($slots[$g['slot']]) ? $slots[$g['slot']] + 1 : 1;
		}
		ksort($slots);
		print "\nEligible per slot: ";
		foreach ($slots as $slot => $n) {
			print $slot . '=' . $n . ' ';
		}
		print "\n\n";
	}
	exit(0);
}

// ---- sql ----

$week_id = isset($opts['week']) ? (int) $opts['week'] : 0;
if (!$week_id) {
	fwrite(STDERR, "sql needs --week=ID (the football_weeks.id the games go in)\n");
	exit(2);
}
$picks = $opts['pick'];
if (!empty($opts['slate'])) {
	foreach (file($opts['slate'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
		$line = trim(preg_replace('/#.*$/', '', $line));
		if ($line !== '') {
			$picks[] = $line;
		}
	}
}
if (!$picks) {
	fwrite(STDERR, "sql needs at least one --pick=LEAGUE:away@home:spread|over-under or --slate=FILE\n");
	exit(2);
}

$boards = [];
foreach (Game::getLeagues() as $league) {
	$boards[$league] = load_board($league, $teams, $ranks, $allow_early);
}

$week = Week::find($week_id);
$week_label = $week
	? 'week #' . $week->id . ' (season ' . $week->football_season_id . ' week ' . $week->week_num . ', picks due ' . $week->picks_due_date . ')'
	: 'week #' . $week_id . ' (not in the local database; the id is used as given)';

$chosen = [];
$errors = [];
$warnings = [];
foreach ($picks as $pick) {
	if (!preg_match('/^(NFL|NCAA):([a-z0-9-]+@[a-z0-9-]+):(spread|over-under)$/i', trim($pick), $m)) {
		$errors[] = "Malformed pick '" . $pick . "'";
		continue;
	}
	$league = strtoupper($m[1]);
	$key = strtolower($m[2]);
	$bet_type = strtolower($m[3]);
	if (!$boards[$league]) {
		$errors[] = $pick . ": no " . $league . " scrape on disk";
		continue;
	}
	if (!isset($boards[$league]['games'][$key])) {
		$errors[] = $pick . ": no game " . $key . " in the newest " . $league . " scrape";
		continue;
	}
	$g = $boards[$league]['games'][$key];
	if (!$g['matched']) {
		$errors[] = $pick . ": team not matched (" . ($g['away'] ? '' : $g['away_team'] . ' ') . ($g['home'] ? '' : $g['home_team']) . ")";
		continue;
	}
	if ($g[$bet_type] === null) {
		$errors[] = $pick . ": the scrape has no " . $bet_type . " line for this game";
		continue;
	}
	if ($g['ineligible']) {
		$warnings[] = $pick . ": rule check says " . implode('; ', $g['ineligible']);
	}
	$chosen[] = ['league' => $league, 'bet_type' => $bet_type, 'game' => $g, 'pick' => $pick];
}

// Slate-shape checks: 4 spreads + 3 totals per league, NFL slot coverage, every MNF game, SNF.
foreach (Game::getLeagues() as $league) {
	$mine = array_filter($chosen, function ($c) use ($league) {
		return $c['league'] == $league;
	});
	$spreads = sizeof(array_filter($mine, function ($c) {
		return $c['bet_type'] == Game::BET_TYPE_SPREAD;
	}));
	$totals = sizeof($mine) - $spreads;
	if ($spreads != 4 || $totals != 3) {
		$warnings[] = $league . ": slate has " . $spreads . " spreads and " . $totals . " totals (the pool uses 4 + 3)";
	}
	$slots = [];
	foreach ($mine as $c) {
		$slots[$c['game']['slot']] = true;
	}
	if ($league == Game::LEAGUE_NFL && $mine) {
		foreach (['NOON', '3PM', 'SNF', 'MNF'] as $slot) {
			if (!isset($slots[$slot])) {
				$warnings[] = "NFL: no game in the " . $slot . " slot";
			}
		}
		if ($boards[$league]) {
			foreach ($boards[$league]['games'] as $g) {
				if ($g['slot'] == 'MNF' && !$g['ineligible']) {
					$in = false;
					foreach ($mine as $c) {
						if ($c['game']['key'] == $g['key']) {
							$in = true;
						}
					}
					if (!$in) {
						$warnings[] = "NFL: Monday night game " . $g['key'] . " is not in the slate (every MNF game is always in)";
					}
				}
			}
		}
	}
	if ($league == Game::LEAGUE_NCAA && $mine) {
		foreach (['SAT-11AM', 'SAT-2:30PM', 'SAT-6:30PM'] as $slot) {
			if (!isset($slots[$slot])) {
				$warnings[] = "NCAA: no game in the " . $slot . " slot";
			}
		}
	}
	$seen = [];
	foreach ($mine as $c) {
		$k = $c['game']['key'];
		if (isset($seen[$k])) {
			$warnings[] = $league . ": both lines on " . $k . " (fine when the slate is thin; just confirming it is deliberate)";
		}
		$seen[$k] = true;
	}
}

if ($errors) {
	fwrite(STDERR, "ERRORS (no SQL for these):\n  " . implode("\n  ", $errors) . "\n");
}
if ($warnings) {
	fwrite(STDERR, "WARNINGS:\n  " . implode("\n  ", $warnings) . "\n");
}
if (!$chosen) {
	exit(1);
}

usort($chosen, function ($a, $b) {
	if ($a['league'] != $b['league']) {
		return $a['league'] == Game::LEAGUE_NCAA ? -1 : 1;
	}
	if ($a['game']['kickoff_ts'] != $b['game']['kickoff_ts']) {
		return $a['game']['kickoff_ts'] - $b['game']['kickoff_ts'];
	}
	return strcmp($a['bet_type'], $b['bet_type']);
});

$q = function ($s) {
	return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
};

print "-- " . sizeof($chosen) . " football_games rows for " . $week_label . "\n";
print "-- Lines from scrapes: ";
foreach ($boards as $league => $board) {
	if ($board) {
		print $league . ' ' . date('Y-m-d H:i', $board['ts']) . ' ';
	}
}
print "\n";
print "-- Same fields admin/weeks/week/bulk-games.php writes; spread is against the away team.\n";
print "INSERT INTO football_games (football_week_id, type, away_team_id, home_team_id, title, date, time, bet_type, value, option_1, option_2, correct_option, options_flipped) VALUES\n";
$values = [];
foreach ($chosen as $c) {
	$g = $c['game'];
	$away = $g['away'];
	$home = $g['home'];
	$value = round((float) $g[$c['bet_type']], 1);
	$away_label = $c['league'] == Game::LEAGUE_NFL ? $away['nickname'] : $away['team'];
	$home_label = $c['league'] == Game::LEAGUE_NFL ? $home['nickname'] : $home['team'];
	if ($c['bet_type'] == Game::BET_TYPE_SPREAD) {
		$option_1 = $away_label . ' (' . fmt_line($value, true) . ')';
		$option_2 = $home_label . ' (' . fmt_line(-1 * $value, true) . ')';
	}
	else {
		$option_1 = 'OVER (' . number_format($value, 1) . ')';
		$option_2 = 'UNDER (' . number_format($value, 1) . ')';
	}
	$title = trim($away['team'] . ' ' . $away['nickname']) . ' @ ' . trim($home['team'] . ' ' . $home['nickname']);
	// Block comment, not "--": a line comment would swallow the comma that follows the row.
	$values[] = "\t/* " . date('D H:i', $g['kickoff_ts']) . ' ' . $g['slot'] . " */ (" . implode(', ', [
		$week_id,
		$q($c['league']),
		$away['id'],
		$home['id'],
		$q($title),
		$q($g['date']),
		$q($g['time']),
		$q($c['bet_type']),
		number_format($value, 1, '.', ''),
		$q($option_1),
		$q($option_2),
		"'0'",
		0,
	]) . ")";
}
print implode(",\n", $values) . ";\n\n";
print "-- Verify afterwards (expect " . sizeof($chosen) . " rows):\n";
print "SELECT id, type, date, time, title, bet_type, value, option_1, option_2 FROM football_games WHERE football_week_id = " . $week_id . " ORDER BY type, date, time;\n";
exit($warnings ? 3 : 0);
