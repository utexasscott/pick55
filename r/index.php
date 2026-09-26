<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Auth;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\Shell;
use Pick55\R\Today;

$shell = new Shell;
$ctx = $shell->ctx;

// ---------------------------------------------------------------------
// Guest: the landing page
// ---------------------------------------------------------------------

if ($ctx->mode === 'guest') {
	$shell->setTitle('Football confidence pool');
	$shell->setNav('none');
	$shell->setPageClass('page-landing');
	$shell->addStyle('css/pages/landing.css');
	ob_start();
	?>
	<section class="landing-hero">
		<div class="landing-copy enter">
			<span class="pill pill-brand"><?=Icons::svg('football')?>NFL + college football</span>
			<h1 class="landing-title">Pick the lines.<br>Rank your confidence.<br><span>Beat the pool.</span></h1>
			<p class="landing-lead">Every week, fourteen games against the spread and the total. Put 10 points on the pick you are surest of, 1 on your tenth, and every point you get right counts. Early September through the playoffs.</p>
			<div class="landing-cta">
				<a class="btn btn-primary btn-lg" href="<?=h($shell->link('auth/signup.php'))?>">Join the pool</a>
				<a class="btn btn-ghost btn-lg" href="<?=h($shell->link('auth/login.php'))?>"><?=Icons::svg('log-in')?>Sign in</a>
			</div>
		</div>
		<div class="landing-art enter" style="--i: 2" aria-hidden="true">
			<div class="art-panel">
				<img class="art-helmet" src="<?=h($shell->classicLink('static/img/helmet-seafoam.png'))?>" alt="" width="48" height="48">
				<div class="art-card art-card-a">
					<div class="art-row"><span class="tag tag-ncaa">NCAA</span><span class="art-muted">Sat 2:30 PM</span></div>
					<div class="art-pick"><span class="art-mult">10</span><span>Ole Miss +3.5</span><span class="pill pill-good"><?=Icons::svg('check')?>Covering</span></div>
				</div>
				<div class="art-card art-card-b">
					<div class="art-row"><span class="badge-live">LIVE</span><span class="art-muted">Q3 4:12</span></div>
					<div class="art-score"><span>Bengals</span><b>24</b></div>
					<div class="art-score"><span>Steelers</span><b>17</b></div>
				</div>
				<div class="art-card art-card-c">
					<div class="art-muted">Week 3</div>
					<div class="art-big">46<small> pts</small></div>
					<div class="art-muted"><span class="delta-up">3</span> to 2nd</div>
				</div>
			</div>
		</div>
	</section>

	<section class="landing-explain" aria-labelledby="how-title">
		<h2 id="how-title" class="landing-h2">Three ideas, one weekly score</h2>
		<div class="grid grid-3">
			<article class="card explain enter" style="--i: 3">
				<div class="explain-art art-spread" aria-hidden="true">
					<div class="spread-bar">
						<span class="spread-team" style="--team-bg:#bf5700;--team-fg:#fff">Texas</span>
						<span class="spread-line">&minus;4.5</span>
						<span class="spread-team" style="--team-bg:#ff8200;--team-fg:#fff">Tennessee</span>
					</div>
					<div class="spread-scale"><span></span></div>
				</div>
				<h3 class="explain-title">Lines and spreads</h3>
				<p class="explain-text">Some games look decided before kickoff. So the favorite carries a handicap: pick Texas at &minus;4.5 and Texas has to win by five or more. The question stops being who wins and becomes by how much.</p>
			</article>
			<article class="card explain enter" style="--i: 4">
				<div class="explain-art art-total" aria-hidden="true">
					<svg viewBox="0 0 200 90" class="total-gauge">
						<path class="gauge-track" d="M20 80 A80 80 0 0 1 180 80"/>
						<path class="gauge-under" d="M20 80 A80 80 0 0 1 100 0"/>
						<line class="gauge-needle" x1="100" y1="80" x2="148" y2="28"/>
						<circle class="gauge-hub" cx="100" cy="80" r="5"/>
					</svg>
					<div class="total-labels"><span>Under</span><b>55.5</b><span>Over</span></div>
				</div>
				<h3 class="explain-title">Over / under</h3>
				<p class="explain-text">Forget the winner: will both teams together score more or fewer points than the total? Every total ends in a half point, so there are no pushes, only right and wrong.</p>
			</article>
			<article class="card explain enter" style="--i: 5">
				<div class="explain-art art-confidence" aria-hidden="true">
					<ol class="conf-rail">
						<li style="--w: 100%"><b>10</b><span>Surest thing</span></li>
						<li style="--w: 80%"><b>9</b><span></span></li>
						<li style="--w: 62%"><b>8</b><span></span></li>
						<li style="--w: 30%"><b>1</b><span>Coin flip</span></li>
					</ol>
				</div>
				<h3 class="explain-title">Confidence</h3>
				<p class="explain-text">Rank your picks. The one you would bet the house on gets 10 points, your tenth gets 1, and the games you do not care about get none. A perfect week is 55.</p>
			</article>
		</div>
	</section>

	<section class="landing-close card enter" style="--i: 6">
		<div>
			<h2 class="landing-h2 mb-0">Ready for Saturday?</h2>
			<p class="muted mb-0">You will need the league passcode from the commissioner.</p>
		</div>
		<a class="btn btn-primary btn-lg" href="<?=h($shell->link('auth/signup.php'))?>">Create an account</a>
	</section>
	<?php
	$shell->setContent(ob_get_clean());
	print $shell->render();
	exit();
}

// ---------------------------------------------------------------------
// Signed in: Today
// ---------------------------------------------------------------------

$me = $ctx->user;
$mode = $ctx->mode;
$shell->setTitle('Today');
$shell->setNav('today');
$shell->addStyle('css/pages/today.css');
$shell->addScript('js/pages/today.js');

$board = null;
$walls = [];
$strip = [];
$recap = null;
if ($ctx->is_player) {
	$board = Today::board($ctx, 5);
	$walls = Today::walls($ctx, 4);
	if ($ctx->live_week) {
		$strip = Today::strip($ctx, $ctx->live_week);
	}
	if ($mode === 'recap' && $ctx->recap_week) {
		$recap = Today::recap($ctx, $ctx->recap_week, $board);
	}
}

$shell->setModule('today', [
	'api' => $shell->link('api/today.php'),
	'mode' => $mode,
	'live_week_id' => $ctx->live_week ? (int) $ctx->live_week->id : null,
	'pick_week_id' => $ctx->pick_week ? (int) $ctx->pick_week->id : null,
	'poll' => $ctx->live_week && $ctx->is_player ? 60000 : 0,
]);

$hour = (int) date('G', $ctx->now);
$greeting = $hour < 5 ? 'Up late' : ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'));

/** "Week 4 · Top 8" */
$week_label = function ($week) use ($ctx) {
	$info = $ctx->info($week);
	$label = 'Week ' . $info['num'];
	if ($info['name'] !== $label) {
		$label .= " \u{00B7} " . $info['name'];
	}
	return $label;
};

$status_text = [
	'covering' => 'Winning',
	'trailing' => 'Losing',
	'even' => 'Even',
	'pending' => '',
	'right' => 'Right',
	'wrong' => 'Wrong',
	'auto' => 'Guaranteed',
	'none' => 'No pick',
];

/** One game in the live strip. */
$game_card = function (array $g) use ($status_text) {
	ob_start();
	?>
	<div class="gcard is-<?=h($g['state'])?> st-<?=h($g['status'])?>" data-game="<?=(int) $g['id']?>" data-state="<?=h($g['state'])?>" role="listitem">
		<div class="gcard-top">
			<span class="tag tag-<?=$g['league'] === 'NFL' ? 'nfl' : 'ncaa'?>"><?=h($g['league'])?></span>
			<span class="gcard-label" data-f="label"><?php if ($g['state'] === 'in'): ?><span class="live-dot"></span><?php endif; ?><?=h($g['label'] !== '' ? $g['label'] : $g['kickoff'])?></span>
		</div>
		<div class="gcard-teams">
			<div class="gcard-team">
				<span class="gcard-swatch" style="--c:<?=h($g['away_color'])?>"></span>
				<span class="truncate"><?=h($g['away'])?></span>
				<b class="gcard-score num" data-f="away"><?=$g['away_score'] === null ? '' : (int) $g['away_score']?></b>
			</div>
			<div class="gcard-team">
				<span class="gcard-swatch" style="--c:<?=h($g['home_color'])?>"></span>
				<span class="truncate"><?=h($g['home'])?></span>
				<b class="gcard-score num" data-f="home"><?=$g['home_score'] === null ? '' : (int) $g['home_score']?></b>
			</div>
		</div>
		<div class="gcard-pick">
			<?php if ($g['my_option'] === '1' || $g['my_option'] === '2' || $g['my_option'] === '3'): ?>
				<span class="gcard-mult num" title="<?=(int) $g['my_mult']?> points"><?=(int) $g['my_mult']?></span>
				<span class="truncate"><?=h($g['my_option'] === '3' ? 'Guaranteed' : $g['my_label'])?></span>
			<?php else: ?>
				<span class="gcard-mult num is-empty">&ndash;</span>
				<span class="truncate faint"><?=h($g['line'])?></span>
			<?php endif; ?>
			<span class="gcard-status" data-f="status"><?=h($status_text[$g['status']])?></span>
		</div>
	</div>
	<?php
	return ob_get_clean();
};

ob_start();
?>
<div class="today">
	<header class="today-head enter">
		<div>
			<span class="eyebrow"><?=h(Fmt::dateLong($ctx->now))?></span>
			<h1 class="page-title"><?=h($greeting)?>, <?=h($me->first_name !== '' ? $me->first_name : Fmt::name($me))?></h1>
		</div>
		<?php if ($ctx->season): ?>
			<span class="pill pill-outline"><?=Icons::svg('calendar')?><?=h($ctx->season->name)?></span>
		<?php endif; ?>
	</header>

	<?php if ($mode === 'pick'): ?>
		<?php
		$pw = $ctx->pick_week;
		$p = $ctx->my_picks_progress;
		$due = $p['due_at'];
		$done = $p['complete'];
		$sides_f = $p['games'] ? $p['sides'] / $p['games'] : 0;
		$values_f = $p['values_needed'] ? min(1, $p['values'] / $p['values_needed']) : 0;
		?>
		<section class="card hero hero-pick enter" data-hero="pick" style="--i: 1">
			<div class="hero-main">
				<span class="eyebrow"><?=h($week_label($pw))?></span>
				<?php if ($done): ?>
					<h2 class="hero-title">Your picks are in.</h2>
					<p class="hero-sub">You can change them until the first kickoff, <strong class="nowrap"><?=h(Fmt::kickoff($due, null, $ctx->now))?></strong>.</p>
				<?php else: ?>
					<h2 class="hero-title">Your picks are due <span class="nowrap"><?=h(Fmt::kickoff($due, null, $ctx->now))?></span></h2>
					<p class="hero-sub">
						<?php if ($p['sides'] == 0 && $p['values'] == 0): ?>
							Fourteen games are waiting. Choose a side on each, then rank your ten surest.
						<?php else: ?>
							<?=(int) $p['sides']?> of <?=(int) $p['games']?> sides chosen and <?=(int) $p['values']?> of <?=(int) $p['values_needed']?> point values placed.
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<div class="hero-meta">
					<span class="pill <?=$done ? 'pill-good' : 'pill-warn'?>"><?=Icons::svg($done ? 'check' : 'clock')?><span><span data-countdown="<?=h(Fmt::iso($due))?>"><?=h(Fmt::countdown($due, $ctx->now))?></span> to kickoff</span></span>
				</div>
				<div class="hero-actions">
					<?php if ($done): ?>
						<a class="btn btn-ghost btn-lg" href="<?=h($shell->link('season/week/pick.php'))?>">Review picks<?=Icons::svg('chevron-right')?></a>
					<?php else: ?>
						<a class="btn btn-primary btn-lg" href="<?=h($shell->link('season/week/pick.php'))?>"><?=$p['sides'] || $p['values'] ? 'Finish your picks' : 'Make your picks'?><?=Icons::svg('chevron-right')?></a>
					<?php endif; ?>
				</div>
			</div>
			<div class="hero-side">
				<?=Shell::ring(
					[[$sides_f, 'brand'], [$values_f, 'accent']],
					'<div class="ring-num num">' . (int) $p['sides'] . '<small>/' . (int) $p['games'] . '</small></div><div class="ring-cap">sides</div>',
					132,
					$p['sides'] . ' of ' . $p['games'] . ' sides chosen, ' . $p['values'] . ' of ' . $p['values_needed'] . ' point values placed'
				)?>
				<ul class="ring-legend">
					<li><span class="legend-dot" style="background: var(--brand)"></span>Sides <b class="num"><?=(int) $p['sides']?>/<?=(int) $p['games']?></b></li>
					<li><span class="legend-dot" style="background: var(--accent)"></span>Points <b class="num"><?=(int) $p['values']?>/<?=(int) $p['values_needed']?></b></li>
				</ul>
			</div>
		</section>

		<?php if ($ctx->live_week && $ctx->my_live): ?>
			<?php $l = $ctx->my_live; ?>
			<a class="card also also-live enter" style="--i: 2" href="<?=h($shell->link('season/week/results.php?id=' . (int) $ctx->live_week->id))?>">
				<span class="badge-live">LIVE</span>
				<span class="also-text"><strong><?=h($week_label($ctx->live_week))?></strong> <span class="muted">&middot; rank <span data-live="rank"><?=h($l['rank_label'])?></span> &middot; <span data-live="points"><?=(int) $l['points']?></span> pts</span></span>
				<?=Icons::svg('chevron-right', 'also-chev')?>
			</a>
		<?php endif; ?>

	<?php elseif ($mode === 'live'): ?>
		<?php
		$lw = $ctx->live_week;
		$l = $ctx->my_live;
		?>
		<section class="card hero hero-live enter" data-hero="live" style="--i: 1">
			<div class="hero-top">
				<div class="cluster">
					<span class="badge-live">LIVE</span>
					<span class="eyebrow mb-0"><?=h($week_label($lw))?></span>
				</div>
				<a class="card-link" href="<?=h($shell->link('season/week/results.php?id=' . (int) $lw->id))?>">Full results<?=Icons::svg('chevron-right')?></a>
			</div>
			<?php if ($l): ?>
				<div class="scoreboard">
					<div class="stat stat-lg sb-points">
						<span class="stat-label">Your points</span>
						<span class="stat-value" data-live="points"><?=(int) $l['points']?></span>
						<span class="stat-sub"><span data-live="right"><?=(int) $l['right']?></span> right &middot; <span data-live="wrong"><?=(int) $l['wrong']?></span> wrong</span>
					</div>
					<div class="stat">
						<span class="stat-label">Rank</span>
						<span class="stat-value"><span data-live="rank"><?=h($l['rank_label'])?></span></span>
						<span class="stat-sub">of <span data-live="players"><?=(int) $l['players']?></span> &middot; <span data-live="behind-text"><?=h($l['behind_label'])?></span></span>
					</div>
					<?php if ($l['has_payouts']): ?>
						<div class="stat">
							<span class="stat-label">Expected</span>
							<span class="stat-value" data-live="expected"><?=h(Fmt::money($l['expected']))?></span>
							<span class="stat-sub">averaged over every outcome</span>
						</div>
					<?php endif; ?>
					<div class="stat">
						<span class="stat-label">Games left</span>
						<span class="stat-value" data-live="games_left"><?=(int) $l['games_left']?></span>
						<span class="stat-sub"><span data-live="in_play"><?=(int) $l['in_play']?></span> in play</span>
					</div>
				</div>
			<?php endif; ?>
			<?php if (sizeof($strip)): ?>
				<div class="strip" role="list" aria-label="This week's games">
					<?php foreach ($strip as $g): ?>
						<?=$game_card($g)?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="hero-foot faint small">Updates every minute while games are on &middot; <span data-live="fetched" data-reltime="<?=h(Fmt::iso(time()))?>">just now</span></div>
		</section>

		<?php if ($ctx->pick_week && $ctx->my_picks_progress): ?>
			<?php $p = $ctx->my_picks_progress; ?>
			<a class="card also enter" style="--i: 2" href="<?=h($shell->link('season/week/pick.php'))?>">
				<span class="also-icon also-good"><?=Icons::svg('check')?></span>
				<span class="also-text"><strong><?=h($week_label($ctx->pick_week))?> picks are in.</strong> <span class="muted">Change them until <?=h(Fmt::kickoff($p['due_at'], null, $ctx->now))?>.</span></span>
				<?=Icons::svg('chevron-right', 'also-chev')?>
			</a>
		<?php endif; ?>

	<?php elseif ($mode === 'recap' && $recap): ?>
		<?php $rw = $ctx->recap_week; ?>
		<section class="card hero hero-recap enter" data-hero="recap" style="--i: 1">
			<div class="hero-top">
				<span class="eyebrow mb-0"><?=h($week_label($rw))?> &middot; Final</span>
				<a class="card-link" href="<?=h($shell->link('season/week/results.php?id=' . (int) $rw->id))?>">Results<?=Icons::svg('chevron-right')?></a>
			</div>
			<h2 class="hero-title">
				<?php if ($recap['rank'] === 1): ?>
					You won Week <?=(int) $ctx->info($rw)['num']?>.
				<?php else: ?>
					You finished <?=h(Fmt::ordinal($recap['rank']))?> of <?=(int) $recap['players']?>.
				<?php endif; ?>
			</h2>
			<div class="scoreboard">
				<div class="stat stat-lg">
					<span class="stat-label">Points</span>
					<span class="stat-value"><?=(int) $recap['points']?></span>
					<span class="stat-sub"><?=h(Fmt::record($recap['right'], $recap['wrong']))?> on picks</span>
				</div>
				<div class="stat">
					<span class="stat-label">Winnings</span>
					<span class="stat-value <?=$recap['winnings'] > 0 ? 'text-good' : ''?>"><?=h(Fmt::money($recap['winnings']))?></span>
					<span class="stat-sub"><?=$recap['winnings'] > 0 ? 'nice work' : 'not this week'?></span>
				</div>
				<?php if ($recap['season_rank']): ?>
					<div class="stat">
						<span class="stat-label">Season</span>
						<span class="stat-value"><?=h(Fmt::ordinal($recap['season_rank']))?></span>
						<span class="stat-sub">
							<?php if ($recap['season_delta'] > 0): ?>
								<span class="delta-up"><?=(int) $recap['season_delta']?></span> this week
							<?php elseif ($recap['season_delta'] < 0): ?>
								<span class="delta-down"><?=abs((int) $recap['season_delta'])?></span> this week
							<?php else: ?>
								<span class="delta-flat">no change</span>
							<?php endif; ?>
						</span>
					</div>
				<?php endif; ?>
			</div>
			<?php if (sizeof($recap['winners'])): ?>
				<p class="hero-sub mb-0">
					<?=Icons::svg('trophy', 'text-brand')?>
					<?=sizeof($recap['winners']) > 1 ? 'Shared by ' : 'Won by '?><strong><?=h(implode(', ', $recap['winners']))?></strong> with <?=(int) $recap['top_points']?> points.
				</p>
			<?php endif; ?>
			<?php if ($ctx->next_week && $ctx->next_opens_at): ?>
				<div class="hero-next">
					<?=Icons::svg('calendar')?>
					<span>Picks for <strong>Week <?=(int) $ctx->info($ctx->next_week)['num']?></strong> open <?=h(Fmt::kickoff($ctx->next_opens_at, null, $ctx->now))?></span>
					<span class="pill"><span data-countdown="<?=h(Fmt::iso($ctx->next_opens_at))?>"><?=h(Fmt::countdown($ctx->next_opens_at, $ctx->now))?></span></span>
				</div>
			<?php endif; ?>
		</section>

	<?php elseif ($mode === 'waiting' || ($mode === 'recap' && !$recap)): ?>
		<?php
		$nw = $ctx->next_week;
		$opens = $ctx->next_opens_at;
		?>
		<section class="card hero hero-waiting enter" data-hero="waiting" style="--i: 1">
			<?php if ($nw && $opens): ?>
				<span class="eyebrow"><?=h($week_label($nw))?></span>
				<h2 class="hero-title">Picks open in <span class="num" data-countdown="<?=h(Fmt::iso($opens))?>"><?=h(Fmt::countdown($opens, $ctx->now))?></span></h2>
				<p class="hero-sub"><?=h(Fmt::dateLong($opens, true))?>. Enjoy the breather.</p>
			<?php else: ?>
				<h2 class="hero-title">Nothing to pick right now.</h2>
				<p class="hero-sub">The next week has not been scheduled yet.</p>
			<?php endif; ?>
			<?php if ($ctx->recap_week): ?>
				<div class="hero-actions">
					<a class="btn btn-ghost" href="<?=h($shell->link('season/week/results.php?id=' . (int) $ctx->recap_week->id))?>">Week <?=(int) $ctx->info($ctx->recap_week)['num']?> results<?=Icons::svg('chevron-right')?></a>
				</div>
			<?php endif; ?>
		</section>

	<?php else: ?>
		<?php
		// offseason
		if ($ctx->season && !$ctx->is_player) {
			print Shell::empty(
				'You are not in the ' . $ctx->season->name . ' yet',
				'Ask the commissioner to add you to this season. Until then, the all-time stats are open to everyone.',
				[
					['label' => 'All-time stats', 'href' => $shell->link('stats/index.php'), 'kind' => 'primary'],
					['label' => 'Rules', 'href' => $shell->link('rules.php')],
				],
				'users'
			);
		}
		elseif ($ctx->season) {
			print Shell::empty(
				"That's a wrap on the " . $ctx->season->name,
				'Every week is in the books. See how it finished, then come back in September.',
				[
					['label' => 'Final standings', 'href' => $shell->link('season/standings.php'), 'kind' => 'primary'],
					['label' => 'All-time stats', 'href' => $shell->link('stats/index.php')],
				],
				'trophy'
			);
		}
		else {
			print Shell::empty(
				'See you in September',
				'There is no active season right now. The record book is always open.',
				[
					['label' => 'All-time stats', 'href' => $shell->link('stats/index.php'), 'kind' => 'primary'],
					['label' => 'My seasons', 'href' => $shell->link('season/index.php')],
				],
				'calendar'
			);
		}
		?>
	<?php endif; ?>

	<?php if ($board && sizeof($board['rows'])): ?>
		<div class="grid grid-main-side today-below">
			<section class="card card-flush enter" style="--i: 3" aria-labelledby="board-title">
				<div class="card-head">
					<h2 class="card-title" id="board-title">Standings</h2>
					<a class="card-link" href="<?=h($shell->link('season/standings.php'))?>">Full standings<?=Icons::svg('chevron-right')?></a>
				</div>
				<ol class="list board">
					<?php $prev_rank = 0; ?>
					<?php foreach ($board['rows'] as $row): ?>
						<?php if ($row['rank'] > $prev_rank + 1 && $prev_rank > 0): ?>
							<li class="board-gap" aria-hidden="true"><span>&middot;&middot;&middot;</span></li>
						<?php endif; ?>
						<?php $prev_rank = $row['rank']; ?>
						<li class="board-row<?=$row['me'] ? ' row-me' : ($row['friend'] ? ' row-friend' : '')?>">
							<span class="rank<?=$row['rank'] <= 3 ? ' rank-' . (int) $row['rank'] : ''?>"><?=(int) $row['rank']?></span>
							<span class="board-name truncate player-name"><?=h($row['name'])?><?=$row['me'] ? ' <span class="faint">(you)</span>' : ''?><?=$row['friend'] ? '<span class="friend-mark" title="Friend"></span>' : ''?></span>
							<?php if ($row['delta'] > 0): ?>
								<span class="delta-up" title="Up <?=(int) $row['delta']?> since last week"><?=(int) $row['delta']?></span>
							<?php elseif ($row['delta'] < 0): ?>
								<span class="delta-down" title="Down <?=abs((int) $row['delta'])?> since last week"><?=abs((int) $row['delta'])?></span>
							<?php endif; ?>
							<span class="board-pts num"><?=(int) $row['points']?><small> pts</small></span>
						</li>
					<?php endforeach; ?>
				</ol>
				<div class="card-foot"><?=(int) $board['players']?> players &middot; regular season<?=$board['last_week_num'] ? ', through Week ' . (int) $board['last_week_num'] : ''?></div>
			</section>

			<div class="stack">
				<?php if (sizeof($board['my_weeks'])): ?>
					<?php
					$pts = array_map(function ($w) {
						return (int) $w['points'];
					}, $board['my_weeks']);
					$best = max($pts);
					$best_num = $board['my_weeks'][array_search($best, $pts)]['num'];
					?>
					<section class="card enter" style="--i: 4" aria-labelledby="season-title">
						<div class="card-head">
							<h2 class="card-title" id="season-title">Your season</h2>
							<a class="card-link" href="<?=h($shell->link('season/index.php'))?>">My season<?=Icons::svg('chevron-right')?></a>
						</div>
						<div class="stats season-stats">
							<div class="stat">
								<span class="stat-label">Total</span>
								<span class="stat-value"><?=(int) array_sum($pts)?></span>
							</div>
							<div class="stat">
								<span class="stat-label">Average</span>
								<span class="stat-value"><?=h(number_format(array_sum($pts) / max(1, sizeof($pts)), 1))?></span>
							</div>
							<div class="stat">
								<span class="stat-label">Best</span>
								<span class="stat-value"><?=(int) $best?></span>
								<span class="stat-sub">Week <?=(int) $best_num?></span>
							</div>
						</div>
						<div class="spark-wrap">
							<?=Today::sparkline($board['my_weeks'], 300, 70)?>
							<div class="spark-labels">
								<?php foreach ($board['my_weeks'] as $w): ?>
									<span>W<?=(int) $w['num']?></span>
								<?php endforeach; ?>
							</div>
						</div>
					</section>
				<?php endif; ?>

				<?php if (sizeof($walls)): ?>
					<section class="card enter" style="--i: 5" aria-labelledby="walls-title">
						<div class="card-head">
							<h2 class="card-title" id="walls-title">On the walls</h2>
							<a class="card-link" href="<?=h($shell->link('stats/fame.php'))?>">Walls<?=Icons::svg('chevron-right')?></a>
						</div>
						<ul class="list walls">
							<?php foreach ($walls as $w): ?>
								<?php $fame = in_array($w['kind'], ['perfect', 'honor'], true); ?>
								<li class="<?=$w['me'] ? 'row-me' : ($w['friend'] ? 'row-friend' : '')?>">
									<span class="wall-icon <?=$fame ? 'is-fame' : 'is-shame'?>"><?=Icons::svg($fame ? 'star' : 'egg')?></span>
									<span class="wall-text">
										<strong><?=h($w['name'])?></strong>
										<span class="muted">scored <?=(int) $w['points']?> in Week <?=(int) $w['week_num']?></span>
									</span>
									<span class="pill <?=$fame ? 'pill-good' : 'pill-bad'?>"><?=h(['perfect' => 'Perfect', 'honor' => 'Honor roll', 'zero' => 'Goose egg', 'dishonor' => 'Rough week'][$w['kind']])?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
