<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Models\Bet;
use Pick55\Models\Game;
use Pick55\Models\GameScore;
use Pick55\Models\Guarantee;
use Pick55\Models\Team;
use Pick55\Models\Week;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\Shell;

// Make Picks (docs/redesign.md section 5): the pick week's games as an
// ordered list of cards. Tap a side; drag (or arrow-key) a card to rank it.
// The first ten cards are worth 10..1 points, the rest 0. Every change is
// saved by picks.js through api/save-picks.php.
Shell::guard();

$shell = new Shell;
$ctx = $shell->ctx;
$me = $ctx->user;
$shell->setTitle('Make picks');
$shell->setNav('picks');

/** Renders an empty state as the whole page. */
$render_empty = function ($title, $text, array $actions, $icon) use ($shell) {
	$shell->setContent(Shell::empty($title, $text, $actions, $icon));
	print $shell->render();
	exit();
};

// ---------------------------------------------------------------------
// Which week
// ---------------------------------------------------------------------

$week = null;
$id = (int) get('id', 0);
if ($id > 0) {
	$week = Week::with('format')->find($id);
	if (!$week || !$week->season) {
		Alert::info('That week does not exist.');
		redir('r/index.php');
	}
}
else {
	if (!$ctx->season) {
		$render_empty(
			'No season is running',
			'There is nothing to pick until the next season starts. The record book is always open.',
			[['label' => 'All-time stats', 'href' => $shell->link('stats/index.php'), 'kind' => 'primary']],
			'calendar'
		);
	}
	if (!$ctx->is_player) {
		$render_empty(
			'You are not in the ' . $ctx->season->name . ' yet',
			'Ask the commissioner to add you to this season, then your picks will open here.',
			[
				['label' => 'All-time stats', 'href' => $shell->link('stats/index.php'), 'kind' => 'primary'],
				['label' => 'Rules', 'href' => $shell->link('rules.php')],
			],
			'users'
		);
	}
	$week = $ctx->pick_week;
	if (!$week) {
		// Nothing to pick: the live week's results, else Today.
		if ($ctx->live_week) {
			Alert::info('Week ' . (int) $ctx->live_week->week_num . ' picks are locked.');
			redir('r/season/week/results.php?id=' . (int) $ctx->live_week->id);
		}
		Alert::info('There are no picks to make right now.');
		redir('r/index.php');
	}
}

$season = $week->season;
if (!$season->hasPlayer($me->id)) {
	$render_empty(
		'You are not in the ' . $season->name,
		'Only players of a season can make its picks.',
		[['label' => 'Today', 'href' => $shell->link('index.php'), 'kind' => 'primary']],
		'users'
	);
}
if (!$week->canPick()) {
	if ($week->canSeeResults()) {
		Alert::info('Week ' . (int) $week->week_num . ' picks are locked.');
		redir('r/season/week/results.php?id=' . (int) $week->id);
	}
	Alert::info('Week ' . (int) $week->week_num . ' picks are not open.');
	redir('r/index.php');
}

// ---------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------

$deadline = $week->getFirstGameAt();
$games = $week->games()->with(['awayTeam', 'homeTeam'])->get()->all();
$game_ids = array_map(function ($g) {
	return (int) $g->id;
}, $games);

$bets = [];
if (sizeof($game_ids)) {
	$q = Bet::whereIn('football_game_id', $game_ids)
		->where('user_id', '=', $me->id)
		->orderBy('multiplier', 'DESC');
	foreach ($q->get() as $bet) {
		$gid = (int) $bet->football_game_id;
		if (!isset($bets[$gid])) {
			$bets[$gid] = ['option' => (int) $bet->option, 'mult' => (int) $bet->multiplier];
		}
	}
}

$guarantee = Guarantee::where('week_id', '=', $week->id)
	->where('user_id', '=', $me->id)
	->first();
$guarantee_lt = $guarantee ? (int) $guarantee->multipliers_less_than : 0;

// Order: saved point values descending, then saved zeros, then games with
// no bet yet (by kickoff). A first visit is shuffled, as the classic page
// does; picks.js saves it at once, so the order holds on reload.
$kick = function (Game $g) {
	return (string) $g->date . ' ' . (string) $g->time;
};
if (!sizeof($bets)) {
	shuffle($games);
}
else {
	usort($games, function ($a, $b) use ($bets, $kick) {
		$ba = isset($bets[(int) $a->id]) ? $bets[(int) $a->id] : null;
		$bb = isset($bets[(int) $b->id]) ? $bets[(int) $b->id] : null;
		$ka = [$ba ? 0 : 1, $ba ? -$ba['mult'] : 0, $kick($a), (int) $a->id];
		$kb = [$bb ? 0 : 1, $bb ? -$bb['mult'] : 0, $kick($b), (int) $b->id];
		return $ka <=> $kb;
	});
}

/** '#rrggbb' from a stored colour, or the fallback. */
$hex = function ($value, $fallback) {
	$value = ltrim(trim((string) $value), '#');
	if (preg_match('/^[0-9a-fA-F]{6}$/', $value)) {
		return '#' . strtolower($value);
	}
	if (preg_match('/^[0-9a-fA-F]{3}$/', $value)) {
		return '#' . strtolower($value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2]);
	}
	return '#' . $fallback;
};

/** WCAG relative luminance of '#rrggbb'. */
$luminance = function ($color) {
	$l = [];
	foreach ([1, 3, 5] as $at) {
		$c = hexdec(substr($color, $at, 2)) / 255;
		$l[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
	}
	return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
};
$contrast = function ($a, $b) use ($luminance) {
	$la = $luminance($a);
	$lb = $luminance($b);
	return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
};

/** Inline custom properties for a team chip; text falls back to white or ink when color_2 is unreadable on color_1. */
$team_style = function ($team) use ($hex, $contrast) {
	$d = Team::getDefaultColors();
	$c1 = $team ? $team->color_1 : null;
	$c2 = $team ? $team->color_2 : null;
	$c3 = $team ? $team->color_3 : null;
	$bg = $hex($c1, $d[0]);
	$fg = $hex($c2, $d[1]);
	if ($contrast($bg, $fg) < 3) {
		$fg = $contrast($bg, '#ffffff') >= $contrast($bg, '#0c1411') ? '#ffffff' : '#0c1411';
	}
	return '--team-bg:' . $bg . ';--team-fg:' . $fg . ';--team-line:' . $hex($c3 ?: $c1, $d[2]);
};

/** "(-2.5)" at the end of an option -> "−2.5"; '' when there is none. */
$option_line = function ($text) {
	if (preg_match('/\(\s*([+-]?[\d.]+)\s*\)\s*$/', (string) $text, $m)) {
		$n = $m[1];
		if ($n[0] === '-') {
			return "\u{2212}" . substr($n, 1);
		}
		if ($n[0] !== '+' && (float) $n != 0) {
			return '+' . $n;
		}
		return $n;
	}
	return '';
};

$rows = [];
foreach ($games as $game) {
	$nfl = $game->type === Game::LEAGUE_NFL;
	$ou = $game->bet_type == Game::BET_TYPE_OVER_UNDER;
	$away = $game->awayTeam;
	$home = $game->homeTeam;
	$away_name = $away ? ($nfl ? $away->nickname : $away->team) : '';
	$home_name = $home ? ($nfl ? $home->nickname : $home->team) : '';
	$matchup = ($away_name !== '' && $home_name !== '') ? $away_name . ' @ ' . $home_name : (string) $game->title;
	$total = rtrim(rtrim(number_format(abs((float) $game->value), 1, '.', ''), '0'), '.');
	$sides = [];
	foreach ([1, 2] as $opt) {
		$text = $opt === 1 ? $game->option_1 : $game->option_2;
		if ($ou) {
			$sides[$opt] = [
				'name' => $opt === 1 ? 'Over' : 'Under',
				'line' => $total,
				'style' => '',
				'icon' => $opt === 1 ? 'arrow-up' : 'arrow-down',
			];
		}
		else {
			$label = GameScore::optionLabel($text);
			$sides[$opt] = [
				'name' => $label !== '' ? $label : ($opt === 1 ? $away_name : $home_name),
				'line' => $option_line($text),
				'style' => $team_style($opt === 1 ? $away : $home),
				'icon' => '',
			];
		}
	}
	if ($ou) {
		$line = 'Total ' . $total;
	}
	else {
		$line = '';
		foreach ([1, 2] as $opt) {
			if (strpos($sides[$opt]['line'], "\u{2212}") === 0) {
				$line = $sides[$opt]['name'] . ' ' . $sides[$opt]['line'];
			}
		}
		if ($line === '') {
			$line = 'Pick ' . "\u{2019}" . 'em';
		}
	}
	$bet = isset($bets[(int) $game->id]) ? $bets[(int) $game->id] : null;
	$rows[] = [
		'id' => (int) $game->id,
		'league' => $nfl ? 'NFL' : 'NCAA',
		'ou' => $ou,
		'title' => (string) $game->title,
		'matchup' => $matchup,
		'kickoff' => Fmt::kickoff($game->date, $game->time, $ctx->now),
		'kickoff_at' => Fmt::iso($kick($game)),
		'line' => $line,
		'sides' => $sides,
		'option' => $bet ? $bet['option'] : 0,
	];
}

$n = sizeof($rows);
$values_needed = min(10, $n);
$mult_at = function ($i) {
	return $i < 10 ? 10 - $i : 0;
};
$guaranteed_at = function ($i) use ($mult_at, $guarantee_lt) {
	return $guarantee_lt > 0 && $mult_at($i) < $guarantee_lt;
};

// Progress as the page will show it (point values come from position).
// A point value counts as placed when it sits on a game with a side chosen.
$sides_done = 0;
$values_done = 0;
foreach ($rows as $i => $row) {
	if (in_array($row['option'], [1, 2, 3], true) || $guaranteed_at($i)) {
		$sides_done++;
		if ($mult_at($i) > 0) {
			$values_done++;
		}
	}
}

$saved = new stdClass;
foreach ($rows as $row) {
	$b = isset($bets[$row['id']]) ? $bets[$row['id']] : null;
	$saved->{$row['id']} = $b ? [$b['option'], $b['mult']] : null;
}

$week_num = (int) $week->week_num;
$week_name = $week->getName();
$results_url = $shell->link('season/week/results.php?id=' . (int) $week->id);

$shell->setTitle('Picks ' . "\u{00B7}" . ' Week ' . $week_num);
$shell->addStyle('css/pages/picks.css');
$shell->addScript('js/pages/picks.js');
$shell->setModule('picks', [
	'week_id' => (int) $week->id,
	'week_num' => $week_num,
	'api' => $shell->link('api/save-picks.php'),
	'results_url' => $results_url,
	'login_url' => $shell->link('auth/login.php?r=' . urlencode('season/week/pick.php' . ($id > 0 ? '?id=' . $id : ''))),
	'deadline' => Fmt::iso($deadline),
	'guarantee_lt' => $guarantee_lt,
	'values_needed' => $values_needed,
	'saved' => $saved,
]);

ob_start();
?>
<div class="pk" data-pk>
	<header class="page-head pk-head enter">
		<div class="pk-head-text">
			<span class="eyebrow"><?=h('Week ' . $week_num)?> &middot; <?=h($season->name)?></span>
			<h1 class="page-title">Make your picks</h1>
			<p class="page-sub">Tap a side on every game, then drag your surest pick to the top. The first ten are worth 10 down to 1 point.</p>
		</div>
		<div class="cluster pk-head-pills">
			<?php if ($week_name !== 'Week ' . $week_num): ?>
				<span class="pill pill-outline"><?=Icons::svg('trophy')?><?=h($week_name)?></span>
			<?php endif; ?>
			<?php if ($week->isPlayoffs()): ?>
				<span class="pill pill-accent"><?=Icons::svg('star')?>Playoffs</span>
			<?php endif; ?>
		</div>
	</header>

	<div class="pk-locked card" data-pk-locked hidden>
		<span class="pk-locked-icon"><?=Icons::svg('lock')?></span>
		<div class="pk-locked-text">
			<strong>Picks are locked.</strong>
			<span class="muted">The first game has kicked off. Your last saved picks stand.</span>
		</div>
		<a class="btn btn-primary" href="<?=h($results_url)?>">See results<?=Icons::svg('chevron-right')?></a>
	</div>

	<div class="pk-layout">
		<aside class="pk-summary" aria-label="Pick progress">
			<div class="card pk-summary-card">
				<div class="pk-sum-top">
					<div class="pk-ring" data-pk-ring>
						<?=Shell::ring(
							[[$n ? $sides_done / $n : 0, 'brand'], [$values_needed ? $values_done / $values_needed : 0, 'accent']],
							'<div class="pk-ring-num num"><span data-pk="sides">' . (int) $sides_done . '</span><small>/' . (int) $n . '</small></div>',
							96,
							$sides_done . ' of ' . $n . ' sides chosen, ' . $values_done . ' of ' . $values_needed . ' point values placed'
						)?>
					</div>
					<div class="pk-sum-main">
						<div class="pk-due">
							<?=Icons::svg('clock')?>
							<span><span class="num" data-countdown="<?=h(Fmt::iso($deadline))?>" data-pk-countdown><?=h(Fmt::countdown($deadline, $ctx->now))?></span> <span class="pk-due-label">to lock</span></span>
						</div>
						<div class="pk-counts">
							<span><span class="legend-dot pk-dot-brand"></span><b class="num" data-pk="sides-text"><?=(int) $sides_done?>/<?=(int) $n?></b> sides</span>
							<span><span class="legend-dot pk-dot-accent"></span><b class="num" data-pk="values-text"><?=(int) $values_done?>/<?=(int) $values_needed?></b> points</span>
						</div>
						<div class="pk-status" data-pk-status>
							<span class="pill" data-state="idle"><?=Icons::svg('check')?><span data-pk-status-text>Ready</span></span>
						</div>
					</div>
				</div>
				<div class="pk-sum-more hide-phone">
					<p class="pk-sum-deadline small muted mb-0">Locks <strong><?=h(Fmt::kickoff($deadline, null, $ctx->now))?></strong>, when the first game kicks off. Every change saves as you go.</p>
					<?php if ($guarantee_lt > 0): ?>
						<p class="pk-sum-guarantee small mb-0"><?=Icons::svg('shield')?><span>Guaranteed this week: picks worth <?=(int) ($guarantee_lt - 1)?> points or less count as right automatically.</span></p>
					<?php endif; ?>
					<div class="pk-sum-actions">
						<button type="button" class="btn btn-ghost btn-sm" data-pk-next><?=Icons::svg('target')?>Next open pick</button>
						<button type="button" class="btn btn-quiet btn-sm" data-pk-undo disabled><?=Icons::svg('undo')?>Undo</button>
					</div>
					<p class="small faint mb-0">Keyboard: focus a handle and press <kbd>&uarr;</kbd> <kbd>&darr;</kbd> to move a game, <kbd>Home</kbd>/<kbd>End</kbd> for the top or bottom.</p>
				</div>
			</div>
		</aside>

		<section class="pk-main" aria-labelledby="pk-list-title">
			<h2 class="sr-only" id="pk-list-title">Your picks, most confident first</h2>
			<?php if ($guarantee_lt > 0): ?>
				<p class="pk-guarantee-note hide-desktop small"><?=Icons::svg('shield')?><span>Picks worth <?=(int) ($guarantee_lt - 1)?> points or less are guaranteed this week.</span></p>
			<?php endif; ?>
			<p class="sr-only" id="pk-help">To reorder, drag a game's handle, or focus it and use the arrow keys. The first ten games get 10 to 1 points; the rest get none.</p>
			<div class="pk-board enter" style="--i: 1">
				<ol class="pk-rail" aria-hidden="true">
					<?php for ($i = 0; $i < $n; $i++): ?>
						<?php $v = $mult_at($i); ?>
						<li class="pk-slot<?=$v === 0 ? ' is-zero' : ''?><?=$guaranteed_at($i) ? ' is-guaranteed' : ''?><?=$i === 10 ? ' is-first-zero' : ''?>" data-slot="<?=(int) $i?>">
							<?php if ($guaranteed_at($i)): ?>
								<span class="pk-slot-check"><?=Icons::svg('check')?></span>
								<span class="pk-slot-sub num"><?=(int) $v?></span>
							<?php else: ?>
								<span class="pk-slot-num num"><?=(int) $v?></span>
								<span class="pk-slot-sub"><?=$v === 1 ? 'pt' : 'pts'?></span>
							<?php endif; ?>
						</li>
					<?php endfor; ?>
				</ol>
				<ol class="pk-list" data-pk-list aria-describedby="pk-help">
					<?php foreach ($rows as $i => $row): ?>
						<?php $v = $mult_at($i); ?>
						<li class="pk-card<?=$v === 0 ? ' is-zero' : ''?><?=$guaranteed_at($i) ? ' is-guaranteed' : ''?><?=in_array($row['option'], [1, 2, 3], true) ? ' is-picked' : ''?>" data-game="<?=(int) $row['id']?>" data-option="<?=(int) $row['option']?>">
							<div class="pk-card-body">
								<div class="pk-meta">
									<span class="tag tag-<?=$row['league'] === 'NFL' ? 'nfl' : 'ncaa'?>"><?=h($row['league'])?></span>
									<span class="tag <?=$row['ou'] ? 'tag-ou' : 'tag-spread'?>"><?=$row['ou'] ? 'O/U' : 'Spread'?></span>
									<span class="pk-kick truncate"><?=h($row['kickoff'])?></span>
									<span class="pk-flag-g pill pill-accent"><?=Icons::svg('check')?>Guaranteed</span>
									<span class="pk-flag-zero">0 pts</span>
								</div>
								<div class="pk-title truncate" title="<?=h($row['title'])?>"><?=h($row['matchup'])?> <span class="pk-line"><?=h($row['line'])?></span></div>
								<div class="pk-sides" role="group" aria-label="<?=h('Your pick for ' . $row['title'])?>">
									<?php foreach ($row['sides'] as $opt => $side): ?>
										<button
											type="button"
											class="chip-team pk-side<?=$row['ou'] ? ' pk-side-ou' : ''?>"
											data-pick="<?=(int) $opt?>"
											aria-pressed="<?=$row['option'] === $opt ? 'true' : 'false'?>"
											<?=$side['style'] !== '' ? 'style="' . h($side['style']) . '"' : ''?>
											><?php if ($side['icon'] !== ''): ?><?=Icons::svg($side['icon'], 'pk-side-icon')?><?php else: ?><span class="pk-swatch" aria-hidden="true"></span><?php endif; ?><span class="pk-side-name truncate"><?=h($side['name'])?></span><?php if ($side['line'] !== ''): ?><span class="pk-side-line num"><?=h($side['line'])?></span><?php endif; ?></button>
									<?php endforeach; ?>
								</div>
								<div class="pk-consensus" data-pk-consensus hidden></div>
								<span class="sr-only" data-pk-sr><?=(int) $v?> points</span>
							</div>
							<div class="pk-handle">
								<button type="button" class="pk-move" data-move="-1" aria-label="<?=h('Move ' . $row['matchup'] . ' up')?>"<?=$i === 0 ? ' disabled' : ''?>><?=Icons::svg('chevron-up')?></button>
								<button type="button" class="pk-grip" data-grip aria-describedby="pk-help" aria-label="<?=h('Reorder ' . $row['matchup'] . ', ' . $v . ' points, position ' . ($i + 1) . ' of ' . $n)?>"><?=Icons::svg('grip-vertical')?></button>
								<button type="button" class="pk-move" data-move="1" aria-label="<?=h('Move ' . $row['matchup'] . ' down')?>"<?=$i === $n - 1 ? ' disabled' : ''?>><?=Icons::svg('chevron-down')?></button>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
			<div class="pk-foot hide-desktop">
				<button type="button" class="btn btn-ghost btn-sm" data-pk-next><?=Icons::svg('target')?>Next open pick</button>
				<button type="button" class="btn btn-quiet btn-sm" data-pk-undo disabled><?=Icons::svg('undo')?>Undo</button>
			</div>
		</section>
	</div>
	<div class="sr-only" aria-live="polite" data-pk-live></div>
	<template data-pk-icon="lock"><?=Icons::svg('lock')?></template>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
