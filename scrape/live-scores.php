<?php

/**
 * Cron entry point for live scores from ESPN (docs/live-scores.md).
 *
 * Runs every 10 minutes. With no "open" game (kicked off within the last
 * 8 hours and not yet final) it exits 0 silently in milliseconds, which is
 * the normal outcome all week. Otherwise it fetches each needed scoreboard
 * once, writes football_game_scores for every open game, and sets
 * football_games.correct_option for a game that has completed while its
 * result is still '0' (never overwriting a set result).
 *
 *   php scrape/live-scores.php                   normal cron run
 *   php scrape/live-scores.php --check           print the open games and the boards it would fetch; fetch nothing
 *   php scrape/live-scores.php --force           skip the open check: every started game of the current week
 *   php scrape/live-scores.php --week=N          every game of that week regardless of kickoff (verification, backfill)
 *   php scrape/live-scores.php --week=N --dry-run  as above but write nothing; print the match, score and derived option per game
 *
 * Exit status 1 if a scoreboard fetch failed, 0 otherwise.
 * Runs under PHP 8.0 on the droplet and 7.4 locally; keep the syntax valid on both.
 */

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\DB;
use Pick55\Espn;
use Pick55\Models\EspnTeam;
use Pick55\Models\Game;
use Pick55\Models\GameScore;

const OPEN_WINDOW_HOURS = 8;

$args = array_slice($argv, 1);
$check = in_array('--check', $args);
$force = in_array('--force', $args);
$dry_run = in_array('--dry-run', $args);
$week_id = null;
foreach ($args as $arg) {
	if (preg_match('/^--week=(\d+)$/', $arg, $m)) {
		$week_id = (int) $m[1];
	}
	elseif (!in_array($arg, ['--check', '--force', '--dry-run'])) {
		fwrite(STDERR, "Unknown argument " . $arg . "\n");
		exit(2);
	}
}

$now = time();
$now_sql = date('Y-m-d H:i:s', $now);

// -----
// Which games
// -----

$q = Game::with(['awayTeam', 'homeTeam'])
	->orderBy('date', 'ASC')
	->orderBy('time', 'ASC')
	->orderBy('id', 'ASC');
$mode = 'open';
if ($week_id) {
	$mode = 'week';
	$q->where('football_week_id', '=', $week_id);
}
elseif ($force) {
	// The current week: the one holding the latest game that has kicked off.
	$mode = 'force';
	$latest = Game::whereRaw("TIMESTAMP(`date`, `time`) <= ?", [$now_sql])
		->orderBy('date', 'DESC')
		->orderBy('time', 'DESC')
		->first();
	if (!$latest) {
		if ($check) {
			print "Would skip: no game has kicked off yet.\n";
		}
		exit(0);
	}
	$q->where('football_week_id', '=', $latest->football_week_id)
		->whereRaw("TIMESTAMP(`date`, `time`) <= ?", [$now_sql]);
}
else {
	$q->whereRaw("TIMESTAMP(`date`, `time`) <= ?", [$now_sql])
		->whereRaw("TIMESTAMP(`date`, `time`) >= ?", [date('Y-m-d H:i:s', $now - OPEN_WINDOW_HOURS * 3600)])
		->whereNotExists(function ($q) {
			$q->select(DB::raw(1))
				->from('football_game_scores AS s')
				->whereColumn('s.football_game_id', 'football_games.id')
				->where('s.state', '=', GameScore::STATE_POST)
				->where('s.completed', '=', 1);
		});
}
$games = $q->get();

if (!sizeof($games)) {
	if ($check) {
		print "Would skip: no open game (kicked off within " . OPEN_WINDOW_HOURS . "h and not final).\n";
	}
	elseif ($mode == 'week') {
		Espn::log("week #" . $week_id . " has no games.");
	}
	exit(0);
}

// Boards needed, one fetch per league+day
$boards = [];
foreach ($games as $game) {
	foreach (Espn::datesFor($game) as $date) {
		$boards[$game->type . ' ' . $date] = ['league' => $game->type, 'date' => $date];
	}
}
ksort($boards);

if ($check) {
	print "Would run (" . $mode . "): " . sizeof($games) . " game(s)\n";
	$scores = GameScore::whereIn('football_game_id', $games->pluck('id')->all())->get()->keyBy('football_game_id');
	foreach ($games as $game) {
		$score = isset($scores[$game->id]) ? $scores[$game->id] : null;
		print "  #" . $game->id . " " . $game->title . " " . $game->date . " " . $game->time . " " . $game->type . " " . $game->bet_type
			. " correct_option=" . $game->correct_option
			. ($score ? " score=" . $score->state . ($score->completed ? '/completed' : '') . " " . $score->away_score . "-" . $score->home_score . " fetched " . $score->fetched_at : " (no score row)")
			. "\n";
	}
	print "Would fetch " . sizeof($boards) . " scoreboard(s):\n";
	foreach ($boards as $b) {
		print "  " . $b['league'] . " " . $b['date'] . "\n";
	}
	exit(0);
}

// -----
// Fetch
// -----

$espn = new Espn();
$exit = 0;
$failed = [];
foreach ($boards as $key => $b) {
	try {
		$events = $espn->events($b['league'], $b['date']);
		Espn::log($b['league'] . " " . $b['date'] . ": " . sizeof($events) . " events");
	}
	catch (Exception $e) {
		Espn::log($b['league'] . " " . $b['date'] . " FAILED: " . $e->getMessage());
		$failed[$key] = true;
		$exit = 1;
	}
}

// -----
// Match, store, decide
// -----

$team_ids = [];
foreach ($games as $game) {
	$team_ids[] = (int) $game->away_team_id;
	$team_ids[] = (int) $game->home_team_id;
}
$espn_ids = EspnTeam::idsForTeams(array_unique($team_ids));
$scores = GameScore::whereIn('football_game_id', $games->pluck('id')->all())->get()->keyBy('football_game_id');

$unmatched_logged = [];
$n_matched = 0;
$n_agree = 0;
$n_decided = 0;
foreach ($games as $game) {
	$label = "#" . $game->id . " " . $game->title . " (" . $game->bet_type . " " . $game->value . ", " . $game->date . " " . substr((string) $game->time, 0, 5) . ")";

	// Skip a game whose board failed to fetch (it stays open and is retried next run)
	$needed_failed = false;
	foreach (Espn::datesFor($game) as $date) {
		if (isset($failed[$game->type . ' ' . $date])) {
			$needed_failed = true;
		}
	}
	if ($needed_failed) {
		continue;
	}

	$score = isset($scores[$game->id]) ? $scores[$game->id] : null;
	$match = $espn->match($game, $espn_ids, $score ? $score->espn_event_id : null);
	if (!$match['event']) {
		$key = $game->type . '|' . $game->away_team_id . '|' . $game->home_team_id . '|' . $game->date . '|' . $game->time;
		if (!isset($unmatched_logged[$key])) {
			$unmatched_logged[$key] = true;
			Espn::log("UNMATCHED " . $label . ": " . $match['reason']);
		}
		continue;
	}
	$n_matched++;
	$event = $match['event'];
	$away = $match['away'];
	$home = $match['home'];

	// Learned team ids
	foreach ($match['learned'] as $team_id => $learned) {
		$espn_ids[$team_id] = $learned['espn_team_id'];
		if ($dry_run) {
			continue;
		}
		$row = EspnTeam::find($team_id);
		if (!$row) {
			$row = new EspnTeam(['football_team_id' => $team_id]);
		}
		$row->espn_team_id = $learned['espn_team_id'];
		$row->espn_display_name = $learned['espn_display_name'];
		$row->matched_at = $now_sql;
		$row->matched_by = $learned['matched_by'];
		$row->save();
		Espn::log("learned ESPN team " . $learned['espn_team_id'] . " = football_teams #" . $team_id . " (" . $learned['espn_display_name'] . ", by " . $learned['matched_by'] . ")");
	}

	// Score row
	$new = [
		'espn_event_id' => $event['id'],
		'away_score' => $away['score'],
		'home_score' => $home['score'],
		'state' => $event['state'],
		'completed' => $event['completed'] ? 1 : 0,
		'period' => $event['period'] ? (int) $event['period'] : null,
		'clock' => $event['clock'] !== '' ? substr($event['clock'], 0, 10) : null,
		'detail' => $event['detail'] !== '' ? substr($event['detail'], 0, 60) : null,
	];
	$changed = [];
	if ($score) {
		foreach (['away_score', 'home_score', 'state', 'completed'] as $k) {
			$old = $score->$k;
			if ((string) $old !== (string) $new[$k]) {
				$changed[] = $k . " " . ($old === null ? 'null' : $old) . "->" . ($new[$k] === null ? 'null' : $new[$k]);
			}
		}
	}
	else {
		$changed[] = 'new';
	}
	$score_text = ($away['score'] === null ? '-' : $away['score']) . "-" . ($home['score'] === null ? '-' : $home['score']);
	$event_text = "ESPN " . $event['id'] . " " . $event['name'] . " [" . $event['state'] . ($event['completed'] ? "/completed" : "") . " \"" . $event['detail'] . "\"] " . $score_text
		. " by " . $match['by'] . ($match['swapped'] ? " (ESPN home/away swapped)" : "");

	if (!$dry_run) {
		if (!$score) {
			$score = new GameScore(['football_game_id' => $game->id]);
		}
		foreach ($new as $k => $v) {
			$score->$k = $v;
		}
		$score->fetched_at = $now_sql;
		if (sizeof($changed)) {
			$score->changed_at = $now_sql;
		}
		$score->save();
		$scores[$game->id] = $score;
	}
	if (sizeof($changed) && !$dry_run) {
		Espn::log($label . ": " . $event_text . " [" . implode(', ', $changed) . "]");
	}

	// Result
	$derived = null;
	if ($event['state'] == GameScore::STATE_POST && $event['completed'] && $away['score'] !== null && $home['score'] !== null) {
		$derived = GameScore::optionFor($game, $away['score'], $home['score']);
		$sides = GameScore::sidesOf($game);
		if (!$sides) {
			$flip = GameScore::flippedByText($game);
			Espn::log("SIDES DISAGREE " . $label . ": option text says option_1 is the " . ($flip ? 'home/UNDER' : 'away/OVER')
				. " side but options_flipped=" . (int) $game->options_flipped . "; not setting correct_option");
		}
		$derived_text = $derived === null ? 'none (line met exactly)' : $derived . " (" . ($derived == '1' ? $game->option_1 : $game->option_2) . ")";
		if ($dry_run) {
			$verdict = 'stored ' . $game->correct_option;
			if ($game->correct_option == '0') {
				$verdict .= ', would set';
			}
			elseif ($derived !== null && $game->correct_option == $derived) {
				$verdict .= ', agrees';
				$n_agree++;
			}
			else {
				$verdict .= ', DIFFERS';
			}
			Espn::log($label . ": " . $event_text . "; derived " . $derived_text . "; " . $verdict);
		}
		elseif ($derived !== null && $sides) {
			if ($game->correct_option == '0') {
				DB::table('football_games')
					->where('id', '=', $game->id)
					->where('correct_option', '=', '0')
					->update(['correct_option' => $derived]);
				$game->correct_option = $derived;
				$score->result_set_at = $now_sql;
				$score->save();
				$n_decided++;
				Espn::log($label . ": set correct_option=" . $derived_text . " on " . $score_text);
			}
			elseif ($game->correct_option != $derived) {
				Espn::log($label . ": stored correct_option=" . $game->correct_option . " differs from derived " . $derived_text . " on " . $score_text . "; not overwriting");
			}
			else {
				$n_agree++;
				if ($mode == 'week' && !$score->result_set_at) {
					Espn::log($label . ": already correct_option=" . $game->correct_option . ", agrees with " . $score_text);
				}
			}
		}
	}
	elseif ($dry_run) {
		Espn::log($label . ": " . $event_text . "; not final");
	}
}

if ($mode == 'week') {
	Espn::log("week #" . $week_id . ": " . sizeof($games) . " games, " . $n_matched . " matched, "
		. sizeof($unmatched_logged) . " unmatched, " . $n_agree . " stored results agree" . ($dry_run ? " (dry run, nothing written)" : ", " . $n_decided . " results set"));
}
exit($exit);
