<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\App;
use Pick55\Auth;
use Pick55\DB;
use Pick55\WeekPayouts;
use Pick55\WeekResults;
use Pick55\Models\Game;
use Pick55\Models\GameScore;
use Pick55\Models\Team;
use Pick55\Models\Week;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\Shell;
use Pick55\R\Today;
use Pick55\Snippets\WeekFormatPayouts;

/*
 * Week Results (docs/redesign.md section 5): standings, games with their
 * pickers and what-ifs, picks by point value, expected winnings. Live while
 * a game is undecided (results.js polls api/week.php), a recap once every
 * game is decided.
 *
 *   ?id=<week_id>&pool=<pool_id|0>&g<game_id>=1|2
 *
 * The money logic is the classic page's (season/week/results.php): the
 * overall WeekResults decides payouts, a pool view adds a pool-only
 * WeekResults for its ranks and probabilities, WeekPayouts::syncWinners
 * records a complete week, and winnings are read from football_week_winners.
 */

Shell::guard();

const RESULTS_PICKERS_SHOWN = 5;
// Standings and the point-value grid show this many rows (plus the viewer)
// until expanded, unless the list is at most RESULTS_GRID_FULL players, in
// which case every row shows and there is nothing to expand.
const RESULTS_GRID_ROWS = 10;
const RESULTS_GRID_FULL = 15;
const RESULTS_CHART_PLAYERS = 5;

$shell = new Shell;
$ctx = $shell->ctx;
$me = $ctx->user;
$shell->setNav('results');
$shell->setTitle('Results');
$shell->addStyle('css/pages/results.css');

/**
 * A season's weeks in week_num order with whether their results are
 * visible (Week::canSeeResults' rule) and whether any game is undecided:
 * one query over the games.
 */
$weeks_of = function ($season) {
	$rows = $season->weeks()->with('format')->get();
	$ids = [];
	foreach ($rows as $w) {
		$ids[] = (int) $w->id;
	}
	$agg = [];
	if (sizeof($ids)) {
		$q = DB::table('football_games')
			->whereIn('football_week_id', $ids)
			->groupBy('football_week_id')
			->selectRaw("football_week_id AS week_id, COUNT(*) AS n, SUM(correct_option = '0') AS undecided, MIN(CONCAT(`date`, ' ', IFNULL(`time`, '00:00:00'))) AS first_at");
		foreach ($q->get() as $row) {
			$agg[(int) $row->week_id] = $row;
		}
	}
	$now = time();
	$out = [];
	foreach ($rows as $w) {
		$row = isset($agg[(int) $w->id]) ? $agg[(int) $w->id] : null;
		$out[] = [
			'week' => $w,
			'id' => (int) $w->id,
			'num' => (int) $w->week_num,
			'name' => $w->getName(),
			'visible' => $row && (int) $row->n > 0 && $row->first_at && strtotime($row->first_at) <= $now,
			'live' => $row && (int) $row->undecided > 0,
		];
	}
	return $out;
};

/** An empty state instead of the page, then stop. */
$bail = function ($title, $text, array $actions = [], $icon = 'info') use ($shell) {
	$shell->setContent(Shell::empty($title, $text, $actions, $icon));
	print $shell->render();
	exit();
};

// ---------------------------------------------------------------------
// Which week, and may the viewer see it (the classic page's rules)
// ---------------------------------------------------------------------

$week = null;
if (get('id')) {
	$week = Week::find((int) get('id'));
	if (!$week || !$week->season) {
		$bail('Week not found', 'That week does not exist. The latest results are one tap away.', [
			['label' => 'Latest results', 'href' => $shell->link('season/week/results.php'), 'kind' => 'primary'],
		], 'search');
	}
}
else {
	$season = App::get()->getSeason();
	if (!$season) {
		$bail('No season yet', 'There is no season to show results for. The record book is always open.', [
			['label' => 'All-time stats', 'href' => $shell->link('stats/index.php'), 'kind' => 'primary'],
		], 'calendar');
	}
	// The latest week with visible results. (Season::getResultWeek() is not
	// used: its DESC order comes after weeks()'s own ascending order, so it
	// returns the earliest visible week.)
	$season_weeks = $weeks_of($season);
	foreach ($season_weeks as $w) {
		if ($w['visible']) {
			$week = $w['week'];
		}
	}
	if (!$week) {
		$bail('No results yet', 'Results appear here at the first kickoff of the season.', [
			['label' => 'Today', 'href' => $shell->link('index.php'), 'kind' => 'primary'],
		], 'clock');
	}
}

$season = $week->season;
if (!$season->hasPlayer($me->id)) {
	$bail('You are not in the ' . $season->name, 'Results are open to the players of their season. Ask the commissioner to add you.', [
		['label' => 'Today', 'href' => $shell->link('index.php'), 'kind' => 'primary'],
		['label' => 'All-time stats', 'href' => $shell->link('stats/index.php')],
	], 'users');
}
if (!$week->canUserSeeResults($me->id)) {
	$first = $week->getFirstGameAt();
	$bail(
		'Week ' . (int) $week->week_num . ' results open at kickoff',
		$first ? 'Everyone\'s picks appear at the first kickoff, ' . Fmt::dateLong($first, true) . '.' : 'This week has no games yet.',
		[
			['label' => 'Make picks', 'href' => $shell->link('season/week/pick.php'), 'kind' => 'primary'],
			['label' => 'Latest results', 'href' => $shell->link('season/week/results.php')],
		],
		'lock'
	);
}
App::get()->setSeason($season);
$shell->setTitle('Week ' . (int) $week->week_num . ' Results');

// ---------------------------------------------------------------------
// Players, pools, what-ifs
// ---------------------------------------------------------------------

$friends = $ctx->friendIds();
$my_id = (int) $me->id;

// What-ifs from the query string, passed to WeekResults exactly as the
// classic page passes them (so both pages share cache entries).
$what_ifs = [];
foreach ($_GET as $k => $v) {
	if (is_string($v) && preg_match('/^g(\d+)$/', $k, $m) && in_array($v, ['1', '2'], true)) {
		$what_ifs[$m[1]] = $v;
	}
}

// ---------------------------------------------------------------------
// Results (cached) and money: players, pools (no ?pool = the viewer's
// own pool, as on the classic page; ?pool=0 = everyone), the overall and
// the view's WeekResults, shared with api/week.php through the Context.
// ---------------------------------------------------------------------

$rf = $ctx->resultsFor($week, get('pool', null), $what_ifs);
$users = $rf['users'];
$all_user_ids = $rf['user_ids'];
$pools = $rf['pools'];
$pool_by_user_id = $rf['pool_by_user_id'];
$my_pool_id = $rf['my_pool_id'];
$selected_pool_id = $rf['selected_pool_id'];
$selected_pool_num = $rf['selected_pool_num'];
$overall = $rf['overall'];
$results = $rf['results'];

$format = $week->getFormat();
$num_winners = $week->getNumWinners($selected_pool_num);
$threshold = $week->getMinScoreThreshold($selected_pool_num);
$stats_by_user_id = $results['stats_by_user_id'];
// Rows shown before "Show all": every row for a small list, else the top ten
// (the viewer's row is never hidden).
$grid_rows = sizeof($stats_by_user_id) <= RESULTS_GRID_FULL ? sizeof($stats_by_user_id) : RESULTS_GRID_ROWS;
$bets_by_game_id = $results['bets_by_game_id'];
$num_predictions = $results['num_predictions'];
$show_auto_column = $results['show_auto_column'];

$games = [];
$last_game_at = null;
foreach (Game::hydrate($results['games']) as $game) {
	$games[(int) $game->id] = $game;
	$at = $game->date . ' ' . $game->time;
	if ($last_game_at === null || $at > $last_game_at) {
		$last_game_at = $at;
	}
}
$has_payouts = $overall['has_payouts'];
$week_complete = $overall['num_unknowns'] == 0;

$payout_by_user_id = [];
$expected_by_user_id = [];
foreach ($overall['stats_by_user_id'] as $u_user_id => $stats) {
	$payout_by_user_id[substr($u_user_id, 1)] = $stats['payout'];
	$expected_by_user_id[(int) substr($u_user_id, 1)] = $stats['expected_payout'];
}

// Winnings are recorded from the format once the week is complete, then
// shown from football_week_winners (WeekPayouts::syncWinners' own rules
// decide whether anything is written).
if ($week_complete && $has_payouts) {
	if (WeekPayouts::syncWinners($week, $payout_by_user_id, $last_game_at)) {
		$week->unsetRelation('weekWinners');
	}
}
$winnings_by_user_id = [];
foreach ($week->weekWinners as $week_winner) {
	$user_id = (int) $week_winner->er_user_id;
	$winnings_by_user_id[$user_id] = (isset($winnings_by_user_id[$user_id]) ? $winnings_by_user_id[$user_id] : 0) + $week_winner->amount;
}
$show_winnings = sizeof($winnings_by_user_id) > 0;
$show_probabilities = $num_predictions || sizeof($what_ifs);
$show_expected = $has_payouts && !$week_complete && $num_predictions > 0;
$is_admin = Auth::isAdmin();

// Until a game has a result (real or what-if) everyone is tied for 1st.
$decided_in_view = sizeof($games) - sizeof($results['unknown_game_ids']);

// ---------------------------------------------------------------------
// Live scores and the games' presentation
// ---------------------------------------------------------------------

$scores = [];
try {
	$scores = GameScore::forWeek($week->id);
}
catch (\Throwable $e) {
	$scores = [];
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

/** "Texas (-4.5)" -> ['Texas', '−4.5']; "OVER (55.5)" -> ['Over', '55.5']. */
$split_option = function ($text) {
	$text = (string) $text;
	$name = GameScore::optionLabel($text);
	$line = '';
	if (preg_match('/\(([^)]*)\)\s*$/', $text, $m)) {
		$line = str_replace('-', "\u{2212}", trim($m[1]));
	}
	if (in_array(strtoupper($name), ['OVER', 'UNDER'], true)) {
		$name = ucfirst(strtolower($name));
	}
	return [$name, $line];
};

$counts = ['in' => 0, 'final' => 0, 'upcoming' => 0];
$any_live = false;
$cards = [];
$active_what_ifs = [];
foreach ($games as $id => $game) {
	$away = isset($teams[(int) $game->away_team_id]) ? $teams[(int) $game->away_team_id] : null;
	$home = isset($teams[(int) $game->home_team_id]) ? $teams[(int) $game->home_team_id] : null;
	if ($away) {
		$game->setRelation('awayTeam', $away);
	}
	if ($home) {
		$game->setRelation('homeTeam', $home);
	}
	$nfl = $game->type == Game::LEAGUE_NFL;
	$ou = $game->bet_type == Game::BET_TYPE_OVER_UNDER;
	$decided = $game->correct_option != '0';
	$score = isset($scores[$id]) ? $scores[$id] : null;
	$state = GameScore::STATE_PRE;
	$label = '';
	$leading = null;
	$has_score = false;
	if ($score) {
		$score->setRelation('game', $game);
		$state = (string) $score->state;
		$label = $score->getStatusLabel();
		$has_score = $score->hasScore();
		if (!$decided) {
			$leading = $score->getLeadingOption();
		}
		if ($score->isLive()) {
			$any_live = true;
		}
	}
	if ($decided && $state === GameScore::STATE_PRE) {
		$state = GameScore::STATE_POST;
		$label = 'Final';
	}
	if ($decided || $state === GameScore::STATE_POST) {
		$counts['final']++;
	}
	elseif ($state === GameScore::STATE_IN) {
		$counts['in']++;
	}
	else {
		$counts['upcoming']++;
	}
	$what_if = (!$decided && isset($what_ifs[(string) $id])) ? $what_ifs[(string) $id] : null;
	if ($what_if !== null) {
		$active_what_ifs[$id] = $what_if;
	}

	// Team colours on spread sides: which option is the away team.
	$colors = ['1' => null, '2' => null];
	if (!$ou) {
		$sides = GameScore::sidesOf($game);
		$away_opt = $sides ? $sides['away'] : '1';
		$home_opt = $away_opt === '1' ? '2' : '1';
		$colors[$away_opt] = Today::teamColor($away);
		$colors[$home_opt] = Today::teamColor($home);
	}

	$side_data = [];
	foreach (['1', '2', '3'] as $opt) {
		$pickers = [];
		foreach ($bets_by_game_id[$id] as $bet) {
			if ((string) $bet['option'] === $opt) {
				$pickers[] = $bet;
			}
		}
		usort($pickers, function ($a, $b) {
			if ($a['multiplier'] != $b['multiplier']) {
				return $b['multiplier'] <=> $a['multiplier'];
			}
			return $a['id'] <=> $b['id'];
		});
		$sum = 0;
		$mine = false;
		foreach ($pickers as $bet) {
			$sum += (int) $bet['multiplier'];
			if ((int) $bet['user_id'] === $my_id) {
				$mine = true;
			}
		}
		$side_data[$opt] = ['pickers' => $pickers, 'count' => sizeof($pickers), 'sum' => $sum, 'mine' => $mine];
	}

	$cards[$id] = [
		'game' => $game,
		'nfl' => $nfl,
		'ou' => $ou,
		'decided' => $decided,
		'state' => $state,
		'label' => $label,
		'leading' => $leading,
		'has_score' => $has_score,
		'away_score' => $has_score ? (int) $score->away_score : null,
		'home_score' => $has_score ? (int) $score->home_score : null,
		'away_name' => $away ? ($nfl ? $away->nickname : $away->team) : 'Away',
		'home_name' => $home ? ($nfl ? $home->nickname : $home->team) : 'Home',
		'what_if' => $what_if,
		'colors' => $colors,
		'sides' => $side_data,
	];
}
$poll = $overall['num_unknowns'] > 0;

// ---------------------------------------------------------------------
// Links: pools, what-ifs, weeks
// ---------------------------------------------------------------------

$pool_param = sizeof($pools) ? ($selected_pool_id ? (int) $selected_pool_id : 0) : null;

/** This page with the given what-ifs and pool ('keep' = the current pool). */
$url = function (array $wis, $pool = 'keep') use ($shell, $week, $pool_param) {
	$q = ['id' => (int) $week->id];
	$p = $pool === 'keep' ? $pool_param : $pool;
	if ($p !== null) {
		$q['pool'] = (int) $p;
	}
	ksort($wis);
	foreach ($wis as $game_id => $opt) {
		$q['g' . (int) $game_id] = (string) $opt;
	}
	return $shell->link('season/week/results.php?' . http_build_query($q));
};

// The season's weeks for the picker (already loaded for a default week).
if (!isset($season_weeks)) {
	$season_weeks = $weeks_of($season);
}
$prev_week = null;
$next_week = null;
$seen = false;
foreach ($season_weeks as $w) {
	if ($w['id'] === (int) $week->id) {
		$seen = true;
		continue;
	}
	if (!$w['visible']) {
		continue;
	}
	if (!$seen) {
		$prev_week = $w;
	}
	elseif ($next_week === null) {
		$next_week = $w;
	}
}
$week_link = function ($week_id) use ($shell) {
	return $shell->link('season/week/results.php?id=' . (int) $week_id);
};

// ---------------------------------------------------------------------
// Standings columns (the classic page's rules)
// ---------------------------------------------------------------------

$pct = function ($value) {
	return round((float) $value, 1);
};
$prob_cols = [];
if ($show_probabilities) {
	if ($num_winners == 1 || (sizeof($pools) > 1 && !$selected_pool_id)) {
		$prob_cols[] = ['1st %', 'Chance of finishing 1st over every outcome of the undecided games', function ($s) use ($pct) {
			return $pct($s['prediction_ranks_pct']['r1']);
		}];
	}
	else {
		if ($threshold) {
			$prob_cols[] = ['Top ' . $num_winners . ' or ' . "\u{2265}" . $threshold . ' %', 'Chance of a top ' . $num_winners . ' finish or at least ' . $threshold . ' points', function ($s) use ($pct) {
				return $pct($s['either_threshold_pct']);
			}];
		}
		if (!$threshold && $num_winners < 10) {
			foreach (range(1, $num_winners) as $place) {
				$prob_cols[] = [Fmt::ordinal($place) . ' %', 'Chance of finishing ' . Fmt::ordinal($place), function ($s) use ($pct, $place) {
					return $pct($s['prediction_ranks_pct']['r' . $place]);
				}];
			}
		}
		$prob_cols[] = ['Top ' . $num_winners . ' %', 'Chance of a top ' . $num_winners . ' finish', function ($s) use ($pct) {
			return $pct(array_sum($s['prediction_ranks_pct']));
		}];
	}
	if ($threshold) {
		$prob_cols[] = ["\u{2265}" . $threshold . ' %', 'Chance of scoring at least ' . $threshold, function ($s) use ($pct) {
			return $pct($s['prediction_gte_threshold_pct']);
		}];
		$prob_cols[] = ['1st %', 'Chance of finishing 1st', function ($s) use ($pct) {
			return $pct($s['prediction_ranks_pct']['r1']);
		}];
	}
}

/** "$12", "<$1" for a small positive amount, '' for zero. */
$money = function ($amount) {
	$amount = (float) $amount;
	if ($amount < 0.005) {
		return '';
	}
	if ($amount < 0.5) {
		return '<$1';
	}
	return Fmt::money($amount);
};

$player_name = function ($user_id) use ($users) {
	return isset($users[$user_id]) ? $users[$user_id]->getDisplayName() : 'Player #' . (int) $user_id;
};

// ---------------------------------------------------------------------
// Recap line, chart
// ---------------------------------------------------------------------

$recap = null;
if ($week_complete && sizeof($overall['stats_by_user_id'])) {
	$winners = [];
	$top_points = 0;
	foreach ($overall['stats_by_user_id'] as $u_user_id => $stats) {
		if ((int) $stats['rank'] !== 1) {
			break;
		}
		$top_points = (int) $stats['points'];
		$winners[] = $player_name((int) substr($u_user_id, 1));
	}
	$mine = isset($overall['stats_by_user_id']['u' . $my_id]) ? $overall['stats_by_user_id']['u' . $my_id] : null;
	$recap = [
		'winners' => $winners,
		'top_points' => $top_points,
		'rank' => $mine ? (int) $mine['rank'] : null,
		'points' => $mine ? (int) $mine['points'] : null,
		'players' => sizeof($overall['stats_by_user_id']),
		'winnings' => isset($winnings_by_user_id[$my_id]) ? (float) $winnings_by_user_id[$my_id] : 0.0,
	];
}

$chart = null;
if ($has_payouts && $overall['num_unknowns'] < sizeof($games)) {
	$timeline = WeekResults::timeline($week, $all_user_ids, $pool_by_user_id);
	if (sizeof($timeline['labels']) >= 2) {
		$chart_user_ids = [];
		foreach ($overall['stats_by_user_id'] as $u_user_id => $stats) {
			if (sizeof($chart_user_ids) >= RESULTS_CHART_PLAYERS) {
				break;
			}
			$chart_user_ids[] = (int) substr($u_user_id, 1);
		}
		if (!in_array($my_id, $chart_user_ids, true)) {
			$chart_user_ids[] = $my_id;
		}
		$series = [];
		foreach ($chart_user_ids as $user_id) {
			$series[] = [
				'label' => $player_name($user_id) . ($user_id === $my_id ? ' (you)' : ''),
				'me' => $user_id === $my_id,
				'data' => isset($timeline['series'][$user_id]) ? array_values($timeline['series'][$user_id]) : [],
			];
		}
		$chart = ['labels' => $timeline['labels'], 'series' => $series, 'final' => (bool) $timeline['final']];
	}
}

// ---------------------------------------------------------------------
// Module
// ---------------------------------------------------------------------

$api_query = ['id' => (int) $week->id];
if ($pool_param !== null) {
	$api_query['pool'] = $pool_param;
}
foreach ($what_ifs as $game_id => $opt) {
	$api_query['g' . $game_id] = $opt;
}
$shell->setModule('results', [
	'week_id' => (int) $week->id,
	'pool_id' => (int) $pool_param,
	'me' => $my_id,
	'api' => $shell->link('api/week.php?' . http_build_query($api_query)),
	'poll' => $poll,
	'interval' => $any_live ? 60000 : 300000,
	'show_expected' => $show_expected,
	'ranked' => $decided_in_view > 0,
	'chart' => $chart,
]);
$shell->addScript('js/pages/results.js');

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------

$week_label = 'Week ' . (int) $week->week_num;
$title = $week->getName();
$eyebrow = $title === $week_label ? $season->name : $week_label . " \u{00B7} " . $season->name;
$me_stats = isset($stats_by_user_id['u' . $my_id]) ? $stats_by_user_id['u' . $my_id] : null;

/** The rank badge for a row. */
$rank_html = function ($rank) use ($decided_in_view) {
	if (!$decided_in_view) {
		return '<span class="rank" title="No results yet">' . "\u{2013}" . '</span>';
	}
	$rank = (int) $rank;
	return '<span class="rank' . ($rank <= 3 ? ' rank-' . $rank : '') . '">' . $rank . '</span>';
};

$status_text = function ($c) {
	if ($c['state'] === GameScore::STATE_IN) {
		return '<span class="badge-live">LIVE</span><span class="gs-label">' . h($c['label']) . '</span>';
	}
	if ($c['state'] === GameScore::STATE_POST || $c['decided']) {
		return '<span class="gs-label">' . h($c['label'] !== '' ? $c['label'] : 'Final') . '</span>';
	}
	if ($c['label'] !== '') {
		return '<span class="gs-label">' . h($c['label']) . '</span>';
	}
	return '';
};

/** One side of a game card. */
$side_html = function ($c, $opt) use ($split_option, $player_name, $friends, $my_id, $url, $active_what_ifs) {
	$game = $c['game'];
	$side = $c['sides'][$opt];
	if ($opt === '3') {
		$name = 'Guaranteed';
		$line = '';
	}
	else {
		list($name, $line) = $split_option($opt === '1' ? $game->option_1 : $game->option_2);
	}
	$classes = ['side'];
	$flag = '';
	if ($opt === '3') {
		$classes[] = 'is-auto';
	}
	elseif ($c['decided']) {
		if ($game->correct_option == $opt) {
			$classes[] = 'is-right';
			$flag = '<span class="side-flag text-good" title="Correct">' . Icons::svg('check') . '<span class="sr-only">Correct</span></span>';
		}
		else {
			$classes[] = 'is-wrong';
			$flag = '<span class="side-flag text-bad" title="Wrong">' . Icons::svg('x') . '<span class="sr-only">Wrong</span></span>';
		}
	}
	elseif ($c['what_if'] !== null) {
		$classes[] = $c['what_if'] === $opt ? 'is-wi-right' : 'is-wi-wrong';
	}
	if ($opt !== '3' && !$c['decided'] && $c['leading'] === $opt) {
		$classes[] = 'is-leading';
	}
	if ($side['mine']) {
		$classes[] = 'has-mine';
	}
	$style = '';
	if ($opt !== '3' && $c['colors'][$opt]) {
		$style = ' style="--team: ' . h($c['colors'][$opt]) . '"';
	}
	ob_start();
	?>
	<div class="<?=h(implode(' ', $classes))?>" data-option="<?=h($opt)?>"<?=$style?>>
		<div class="side-head">
			<span class="side-name"><?=h($name)?><?php if ($line !== ''): ?> <span class="side-line num"><?=h($line)?></span><?php endif; ?></span>
			<?=$flag?>
		</div>
		<div class="side-meta">
			<?php if ($opt !== '3' && !$c['decided']): ?>
				<span class="lead-mark"><?=Icons::svg('trending-up')?>Leading</span>
			<?php endif; ?>
			<?php if ($c['what_if'] === $opt && $opt !== '3'): ?>
				<span class="wi-mark">What-if</span>
			<?php endif; ?>
			<span class="side-count"><b class="num"><?=(int) $side['count']?></b> <?=$side['count'] == 1 ? 'pick' : 'picks'?> &middot; <b class="num"><?=(int) $side['sum']?></b> pts</span>
		</div>
		<?php if ($side['count']): ?>
			<ol class="pickers">
				<?php
				$others = 0;
				foreach ($side['pickers'] as $bet):
					$uid = (int) $bet['user_id'];
					$is_me = $uid === $my_id;
					$extra = false;
					if (!$is_me) {
						$extra = $others >= RESULTS_PICKERS_SHOWN;
						$others++;
					}
					$pc = 'picker' . ($is_me ? ' is-me' : '') . (isset($friends[$uid]) ? ' is-friend' : '') . ($extra ? ' is-extra' : '');
					?>
					<li class="<?=h($pc)?>"><b class="pk-mult num"><?=(int) $bet['multiplier']?></b><span class="pk-name truncate"><?=h($player_name($uid))?></span><?php if (isset($friends[$uid])): ?><span class="friend-mark" title="Friend"></span><?php endif; ?></li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
		<?php if ($opt !== '3' && !$c['decided']): ?>
			<?php
			$wis = $active_what_ifs;
			if ($c['what_if'] === $opt) {
				unset($wis[(int) $game->id]);
				$wi_label = 'Undo';
				$wi_aria = 'Undo the what-if on ' . $name;
			}
			else {
				$wis[(int) $game->id] = $opt;
				$wi_label = 'What if';
				$wi_aria = 'What if ' . $name . ($line !== '' ? ' ' . $line : '') . ' wins';
			}
			?>
			<a class="wi-btn<?=$c['what_if'] === $opt ? ' is-on' : ''?>" href="<?=h($url($wis))?>" data-whatif aria-label="<?=h($wi_aria)?>"><?=Icons::svg($c['what_if'] === $opt ? 'undo' : 'zap')?><?=h($wi_label)?></a>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
};

ob_start();
?>
<div class="results" data-week="<?=(int) $week->id?>">
	<header class="res-head enter">
		<div class="res-top">
			<div class="res-titles">
				<span class="eyebrow"><?=h($eyebrow)?></span>
				<h1 class="page-title"><?=h($title)?></h1>
				<?php if (strlen($week->getDescriptionLong())): ?>
					<p class="page-sub"><?=h($week->getDescriptionLong())?></p>
				<?php endif; ?>
			</div>
			<nav class="week-nav" aria-label="Weeks">
				<?php if ($prev_week): ?>
					<a class="btn btn-ghost btn-sm btn-icon" href="<?=h($week_link($prev_week['id']))?>" aria-label="Week <?=(int) $prev_week['num']?>" title="Week <?=(int) $prev_week['num']?>"><?=Icons::svg('chevron-left')?></a>
				<?php else: ?>
					<span class="btn btn-ghost btn-sm btn-icon" aria-disabled="true"><?=Icons::svg('chevron-left')?></span>
				<?php endif; ?>
				<details class="menu week-menu" data-menu>
					<summary class="btn btn-ghost btn-sm"><?=h($week_label)?><?=Icons::svg('chevron-down')?></summary>
					<div class="menu-panel week-menu-panel">
						<?php foreach ($season_weeks as $w): ?>
							<?php if ($w['visible']): ?>
								<a class="menu-item<?=$w['id'] === (int) $week->id ? ' is-current' : ''?>" href="<?=h($week_link($w['id']))?>"<?=$w['id'] === (int) $week->id ? ' aria-current="page"' : ''?>>
									<span class="wm-num num"><?=(int) $w['num']?></span>
									<span class="truncate"><?=h($w['name'])?></span>
									<?php if ($w['live']): ?><span class="live-dot" title="Games still to decide"></span><?php endif; ?>
								</a>
							<?php else: ?>
								<span class="menu-item is-disabled" aria-disabled="true">
									<span class="wm-num num"><?=(int) $w['num']?></span>
									<span class="truncate"><?=h($w['name'])?></span>
									<?=Icons::svg('lock')?>
								</span>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				</details>
				<?php if ($next_week): ?>
					<a class="btn btn-ghost btn-sm btn-icon" href="<?=h($week_link($next_week['id']))?>" aria-label="Week <?=(int) $next_week['num']?>" title="Week <?=(int) $next_week['num']?>"><?=Icons::svg('chevron-right')?></a>
				<?php else: ?>
					<span class="btn btn-ghost btn-sm btn-icon" aria-disabled="true"><?=Icons::svg('chevron-right')?></span>
				<?php endif; ?>
			</nav>
		</div>

		<?php if ($format): ?>
			<?php $groups = WeekFormatPayouts::groups($format); ?>
			<?php if (sizeof($groups)): ?>
				<div class="payouts" aria-label="Payouts">
					<?php foreach ($groups as $label => $rows): ?>
						<div class="payout-group">
							<span class="payout-label"><?=h($label)?></span>
							<?php foreach ($rows as $row): ?>
								<span class="pill payout-chip"><span class="faint"><?=h($row->getPlaceLabel())?></span> <b class="num"><?=h($row->getAmountLabel())?></b></span>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ($poll || sizeof($active_what_ifs)): ?>
			<div class="res-bar">
				<?php if ($poll): ?>
					<div class="status-line" data-status>
						<span class="sl-live"<?=$counts['in'] ? '' : ' hidden'?>><span class="live-dot"></span><b class="num" data-f="in_play"><?=(int) $counts['in']?></b> in play</span>
						<span class="sl-sep"<?=$counts['in'] ? '' : ' hidden'?>>&middot;</span>
						<span><b class="num" data-f="final"><?=(int) $counts['final']?></b> final</span>
						<span class="sl-sep">&middot;</span>
						<span><b class="num" data-f="upcoming"><?=(int) $counts['upcoming']?></b> to come</span>
					</div>
				<?php endif; ?>
				<?php if (sizeof($active_what_ifs)): ?>
					<a class="pill pill-accent wi-reset" href="<?=h($url([]))?>" data-whatif><?=Icons::svg('undo')?>Reset <?=sizeof($active_what_ifs)?> what-if<?=sizeof($active_what_ifs) == 1 ? '' : 's'?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if (sizeof($pools)): ?>
			<nav class="seg pool-seg" aria-label="Pools">
				<a href="<?=h($url($active_what_ifs, 0))?>"<?=!$selected_pool_id ? ' aria-current="page"' : ''?>>All players</a>
				<?php foreach ($pools as $pool_id => $pool): ?>
					<a href="<?=h($url($active_what_ifs, $pool_id))?>"<?=$selected_pool_id === $pool_id ? ' aria-current="page"' : ''?>><?=h($pool->name !== '' ? $pool->name : 'Pool ' . (int) $pool->pool_num)?><?php if ($pool_id === $my_pool_id): ?><span class="pool-mine" title="Your pool"></span><?php endif; ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</header>

	<?php if ($recap): ?>
		<section class="card recap enter" style="--i: 1" aria-label="Week in one line">
			<span class="recap-icon"><?=Icons::svg('trophy')?></span>
			<p class="recap-text">
				<?php if (sizeof($recap['winners'])): ?>
					<?=sizeof($recap['winners']) > 1 ? 'Shared by' : 'Won by'?> <strong><?=h(implode(' & ', $recap['winners']))?></strong> with <b class="num"><?=(int) $recap['top_points']?></b>.
				<?php endif; ?>
				<?php if ($recap['rank'] !== null): ?>
					<?php if ($recap['rank'] === 1): ?>
						<span class="recap-me">That includes you.</span>
					<?php else: ?>
						<span class="recap-me">You finished <strong><?=h(Fmt::ordinal($recap['rank']))?></strong> of <?=(int) $recap['players']?> with <b class="num"><?=(int) $recap['points']?></b>.</span>
					<?php endif; ?>
					<?php if ($recap['winnings'] > 0): ?>
						<span class="recap-won">You won <strong class="text-good"><?=h(Fmt::money($recap['winnings']))?></strong>.</span>
					<?php endif; ?>
				<?php endif; ?>
			</p>
		</section>
	<?php endif; ?>

	<div class="res-layout">
		<section class="card card-flush standings is-collapsed enter" style="--i: 2" aria-labelledby="st-title" data-standings data-fold data-fold-rows="<?=(int) $grid_rows?>">
			<div class="card-head">
				<h2 class="card-title" id="st-title">Standings</h2>
				<span class="faint small"><?=sizeof($stats_by_user_id)?> players<?=$selected_pool_id ? ' &middot; ' . h($pools[$selected_pool_id]->name) : ''?><?=sizeof($active_what_ifs) ? ' &middot; with what-ifs' : ''?></span>
			</div>
			<div class="table-wrap table-sticky">
				<table class="table table-compact st-table" data-sortable>
					<thead>
						<tr>
							<th class="c-rank" scope="col" data-sort="num" data-first="ascending" aria-sort="ascending"><button type="button" class="th-btn">#</button></th>
							<th class="c-player" scope="col" data-sort="str" data-first="ascending"><button type="button" class="th-btn">Player</button></th>
							<th class="num" scope="col" data-sort="num"><button type="button" class="th-btn">Pts</button></th>
							<th class="num" scope="col" data-sort="num" title="Right&ndash;wrong"><button type="button" class="th-btn">Right</button></th>
							<?php foreach ($prob_cols as $col): ?>
								<th class="num c-prob" scope="col" data-sort="num" title="<?=h($col[1])?>"><button type="button" class="th-btn"><?=h($col[0])?></button></th>
							<?php endforeach; ?>
							<?php if ($show_expected): ?>
								<th class="num" scope="col" data-sort="num" title="Expected winnings: the average payout over every outcome of the remaining games"><button type="button" class="th-btn">Exp $</button></th>
							<?php endif; ?>
							<?php if ($show_winnings): ?>
								<th class="num" scope="col" data-sort="num"><button type="button" class="th-btn">Won</button></th>
							<?php endif; ?>
							<?php if ($is_admin): ?>
								<th class="num" scope="col" data-sort="num" title="Possible points"><button type="button" class="th-btn">Poss</button></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php $i = 0; $st_hidden_rows = 0; ?>
						<?php foreach ($stats_by_user_id as $u_user_id => $stats): ?>
							<?php
							$uid = (int) substr($u_user_id, 1);
							$is_me = $uid === $my_id;
							$is_friend = isset($friends[$uid]);
							$is_extra = $i >= $grid_rows && !$is_me;
							if ($is_extra) {
								$st_hidden_rows++;
							}
							$exp = isset($expected_by_user_id[$uid]) ? (float) $expected_by_user_id[$uid] : 0.0;
							$won = isset($winnings_by_user_id[$uid]) ? (float) $winnings_by_user_id[$uid] : 0.0;
							$name = $player_name($uid);
							$rc = trim(($is_me ? 'row-me' : ($is_friend ? 'row-friend' : '')) . ($is_extra ? ' is-extra' : ''));
							?>
							<tr<?=$rc !== '' ? ' class="' . h($rc) . '"' : ''?> data-user="<?=$uid?>" data-i="<?=$i++?>"<?=$is_me ? ' id="st-me"' : ''?>>
								<td class="c-rank" data-v="<?=(int) $stats['rank']?>" data-f="rank"><?=$rank_html($stats['rank'])?></td>
								<td class="c-player" data-v="<?=h(strtolower($name))?>">
									<span class="player-name"><?=h($name)?></span><?php if ($is_me): ?> <span class="faint small">(you)</span><?php endif; ?><?php if ($is_friend): ?><span class="friend-mark" title="Friend"></span><?php endif; ?>
									<?php if (!$selected_pool_id && isset($pool_by_user_id[$uid]) && sizeof($pools) > 1): ?><span class="pool-tag" title="Pool <?=(int) $pool_by_user_id[$uid]?>">P<?=(int) $pool_by_user_id[$uid]?></span><?php endif; ?>
								</td>
								<td class="num c-pts" data-v="<?=(int) $stats['points']?>"><b data-f="points"><?=(int) $stats['points']?></b></td>
								<td class="num c-right" data-v="<?=(int) $stats['right']?>"><span data-f="right"><?=(int) $stats['right']?></span><span class="rec-wrong">&ndash;<span data-f="wrong"><?=(int) $stats['wrong']?></span></span></td>
								<?php foreach ($prob_cols as $col): ?>
									<?php $v = $col[2]($stats); ?>
									<td class="num c-prob" data-v="<?=h($v)?>"><?=$v > 0 ? h(number_format($v, 1)) : '<span class="faint">&ndash;</span>'?></td>
								<?php endforeach; ?>
								<?php if ($show_expected): ?>
									<td class="num c-exp" data-v="<?=h(round($exp, 2))?>" data-f="expected"><?=h($money($exp))?></td>
								<?php endif; ?>
								<?php if ($show_winnings): ?>
									<td class="num c-won" data-v="<?=h(round($won, 2))?>"><?=$won > 0 ? '<b class="text-good">' . h(Fmt::money($won)) . '</b>' : ''?></td>
								<?php endif; ?>
								<?php if ($is_admin): ?>
									<td class="num" data-v="<?=(int) $stats['possible']?>"><?=(int) $stats['possible']?></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ($st_hidden_rows): ?>
				<div class="card-foot fold-foot">
					<button type="button" class="btn btn-quiet btn-sm" data-fold-toggle aria-expanded="false" data-more-text="Show all <?=sizeof($stats_by_user_id)?> players" data-less-text="Show top <?=RESULTS_GRID_ROWS?>"><?=Icons::svg('chevron-down')?><span>Show all <?=sizeof($stats_by_user_id)?> players</span></button>
				</div>
			<?php endif; ?>
		</section>

		<section class="games enter" style="--i: 3" aria-labelledby="games-title">
			<div class="games-head">
				<h2 class="section-title mt-0 mb-0" id="games-title">Games</h2>
				<span class="faint small">Your picks are outlined</span>
			</div>
			<?php $day = null; ?>
			<?php foreach ($cards as $id => $c): ?>
				<?php
				$game = $c['game'];
				$this_day = (string) $game->date;
				if ($this_day !== $day):
					if ($day !== null) {
						print '</div>';
					}
					$day = $this_day;
					?>
					<h3 class="day-head"><?=h(date('l, M j', strtotime($this_day)))?></h3>
					<div class="game-list">
				<?php endif; ?>
				<?php
				$extra = 0;
				foreach ($c['sides'] as $opt => $side) {
					$others = $side['count'] - ($side['mine'] ? 1 : 0);
					$extra += max(0, $others - RESULTS_PICKERS_SHOWN);
				}
				$total_picks = $c['sides']['1']['count'] + $c['sides']['2']['count'] + $c['sides']['3']['count'];
				$gc = 'card game is-' . $c['state'] . ($c['decided'] ? ' is-decided' : '') . ($c['what_if'] !== null ? ' has-whatif' : '');
				?>
				<article class="<?=h($gc)?>" id="game-<?=(int) $id?>" data-game="<?=(int) $id?>" data-correct="<?=h((string) $game->correct_option)?>" data-state="<?=h($c['state'])?>">
					<div class="game-top">
						<span class="tag tag-<?=$c['nfl'] ? 'nfl' : 'ncaa'?>"><?=h($game->type)?></span>
						<span class="tag tag-<?=$c['ou'] ? 'ou' : 'spread'?>"><?=$c['ou'] ? 'O/U' : 'Spread'?></span>
						<span class="game-when"><?=h(Fmt::kickoff($game->date, $game->time))?></span>
						<span class="game-status" data-f="status"><?=$status_text($c)?></span>
					</div>
					<h3 class="game-title"><?=h($game->title)?></h3>
					<div class="game-score" data-f="score"<?=$c['has_score'] ? '' : ' hidden'?>>
						<span class="gs-team"><span class="truncate"><?=h($c['away_name'])?></span><b class="gs-num num" data-f="away"><?=$c['away_score'] === null ? '' : (int) $c['away_score']?></b></span>
						<span class="gs-team"><span class="truncate"><?=h($c['home_name'])?></span><b class="gs-num num" data-f="home"><?=$c['home_score'] === null ? '' : (int) $c['home_score']?></b></span>
					</div>
					<div class="sides<?=$show_auto_column ? ' has-auto' : ''?>">
						<?=$side_html($c, '1')?>
						<?=$side_html($c, '2')?>
						<?php if ($show_auto_column): ?>
							<?=$side_html($c, '3')?>
						<?php endif; ?>
					</div>
					<?php if ($extra > 0): ?>
						<button type="button" class="btn btn-quiet btn-sm game-more" data-more aria-expanded="false" data-more-text="Show all <?=(int) $total_picks?> picks" data-less-text="Show fewer"><?=Icons::svg('chevron-down')?><span>Show all <?=(int) $total_picks?> picks</span></button>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
			<?php if ($day !== null): ?>
				</div>
			<?php endif; ?>
		</section>
	</div>

	<section class="card card-flush pv is-collapsed enter" style="--i: 4" aria-labelledby="pv-title" data-fold data-fold-rows="<?=(int) $grid_rows?>">
		<div class="card-head">
			<h2 class="card-title" id="pv-title">Picks by point value</h2>
			<span class="pv-legend faint small">
				<span><b class="pv-right">&#x2713;</b> right</span>
				<span><b class="pv-wrong">&#x2717;</b> wrong</span>
				<span><b class="pv-auto">&#x25CE;</b> guaranteed</span>
				<span><b class="pv-unknown">?</b> to come</span>
			</span>
		</div>
		<div class="table-wrap table-sticky">
			<table class="table table-compact pv-table">
				<thead>
					<tr>
						<th scope="col">Player</th>
						<?php for ($m = 10; $m >= 1; $m--): ?>
							<th scope="col" class="center num"><?=$m?></th>
						<?php endfor; ?>
					</tr>
				</thead>
				<tbody>
					<?php $row_num = 0; $hidden_rows = 0; ?>
					<?php foreach ($stats_by_user_id as $u_user_id => $stats): ?>
						<?php
						$uid = (int) substr($u_user_id, 1);
						$is_me = $uid === $my_id;
						$is_extra = $row_num >= $grid_rows && !$is_me;
						if ($is_extra) {
							$hidden_rows++;
						}
						$row_num++;
						$rc = trim(($is_me ? 'row-me' : (isset($friends[$uid]) ? 'row-friend' : '')) . ($is_extra ? ' is-extra' : ''));
						?>
						<tr<?=$rc !== '' ? ' class="' . h($rc) . '"' : ''?>>
							<td><span class="player-name truncate"><?=h($player_name($uid))?></span></td>
							<?php for ($m = 10; $m >= 1; $m--): ?>
								<?php $r = (int) $stats['by_multiplier'][$m]; ?>
								<?php if ($r === 1): ?>
									<td class="center pv-c pv-right" title="<?=$m?>: right">&#x2713;</td>
								<?php elseif ($r === -1): ?>
									<td class="center pv-c pv-wrong" title="<?=$m?>: wrong">&#x2717;</td>
								<?php elseif ($r === 3): ?>
									<td class="center pv-c pv-auto" title="<?=$m?>: guaranteed">&#x25CE;</td>
								<?php else: ?>
									<td class="center pv-c pv-unknown" title="<?=$m?>: to come">?</td>
								<?php endif; ?>
							<?php endfor; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ($hidden_rows): ?>
			<div class="card-foot fold-foot">
				<button type="button" class="btn btn-quiet btn-sm" data-fold-toggle aria-expanded="false" data-more-text="Show all <?=sizeof($stats_by_user_id)?> players" data-less-text="Show top <?=RESULTS_GRID_ROWS?>"><?=Icons::svg('chevron-down')?><span>Show all <?=sizeof($stats_by_user_id)?> players</span></button>
			</div>
		<?php endif; ?>
	</section>

	<?php if ($chart): ?>
		<section class="card chart-card enter" style="--i: 5" aria-labelledby="chart-title">
			<div class="card-head">
				<h2 class="card-title" id="chart-title">Expected winnings</h2>
				<span class="faint small">After each kickoff, averaged over every outcome still to play</span>
			</div>
			<div class="chart-box">
				<canvas data-chart role="img" aria-label="Expected winnings after each kickoff for the top <?=RESULTS_CHART_PLAYERS?> players and you"></canvas>
			</div>
		</section>
	<?php endif; ?>

	<?php if ($me_stats): ?>
		<?php
		$dock_extra = '';
		if ($show_expected) {
			$dock_extra = 'Exp ' . ($money(isset($expected_by_user_id[$my_id]) ? $expected_by_user_id[$my_id] : 0) ?: '$0');
		}
		elseif (sizeof($prob_cols)) {
			$dock_extra = $prob_cols[0][0] . ' ' . number_format($prob_cols[0][2]($me_stats), 1);
		}
		elseif (isset($winnings_by_user_id[$my_id])) {
			$dock_extra = 'Won ' . Fmt::money($winnings_by_user_id[$my_id]);
		}
		?>
		<div class="me-dock" data-medock aria-hidden="true">
			<button type="button" class="me-dock-inner" tabindex="-1" data-medock-jump>
				<span data-f="rank"><?=$rank_html($me_stats['rank'])?></span>
				<span class="player-name truncate"><?=h($player_name($my_id))?> <span class="faint">(you)</span></span>
				<span class="md-pts num"><b data-f="points"><?=(int) $me_stats['points']?></b> pts</span>
				<?php if ($dock_extra !== ''): ?>
					<span class="md-extra num" data-f="<?=$show_expected ? 'expected-label' : 'extra'?>"><?=h($dock_extra)?></span>
				<?php endif; ?>
			</button>
		</div>
	<?php endif; ?>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
