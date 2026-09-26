<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\App;
use Pick55\Auth;
use Pick55\SeasonHistory;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\SeasonStandings;
use Pick55\R\Shell;

Shell::guard();

$shell = new Shell;
$shell->setNav('today');
$shell->setPageClass('page-season');
$shell->addStyle('css/pages/season.css');
$me = Auth::user();
$me_id = (int) $me->id;
$season = App::get()->getSeason();

if (!$season || !$season->hasPlayer($me_id)) {
	$shell->setTitle('My season');
	$shell->setContent($season
		? Shell::empty(
			'You are not in the ' . $season->name,
			'Ask the commissioner to add you to this season.',
			[
				['label' => 'My current season', 'href' => $shell->link('season/index.php'), 'kind' => 'primary'],
				['label' => 'All-time stats', 'href' => $shell->link('stats/index.php')],
			],
			'users'
		)
		: Shell::empty('No season yet', 'There is no season to show.', [], 'calendar'));
	print $shell->render();
	exit();
}

$shell->setTitle('My season ' . "\u{00B7} " . $season->name);
$shell->addScript('../../static/js/chart.js');
$shell->addScript('js/pages/season.js');

$now = time();
$standings = SeasonStandings::get($season);
$mine = isset($standings['players'][$me_id]) ? $standings['players'][$me_id] : null;
$timeline = SeasonStandings::timeline($season, $me_id, $now);
$breakdown = SeasonStandings::breakdown($season, $me_id);
$history = SeasonHistory::forUser($me_id);
$total_winnings = 0;
foreach ($history as $h) {
	$total_winnings += $h['winnings'];
}

$best_pts = null;
$best_num = null;
if ($mine) {
	foreach ($mine['score_by_week_num'] as $num => $pts) {
		if ($best_pts === null || $pts > $best_pts) {
			$best_pts = $pts;
			$best_num = $num;
		}
	}
}

$shell->setModule('season', [
	'season_id' => (int) $season->id,
	'breakdown' => $breakdown,
	'chart_src' => $shell->classicLink('static/js/chart.js'),
]);

$result_meta = [
	1 => ['good', 'check', 'right'],
	-1 => ['bad', 'x', 'wrong'],
	3 => ['auto', 'circle-dot', 'guaranteed'],
	0 => ['open', null, 'undecided'],
];

ob_start();
?>
<header class="page-head season-head enter">
	<div>
		<span class="eyebrow"><?=h($season->name)?><?=$season->is_active ? '' : ' · Final'?></span>
		<h1 class="page-title">My season</h1>
	</div>
	<?=SeasonStandings::picker($shell, $season, $me_id, 'season/index.php')?>
</header>

<div class="stats season-stats enter" style="--i: 1">
	<a class="stat stat-link" href="<?=h($shell->link('season/standings.php?id=' . (int) $season->id))?>">
		<span class="stat-label">Place<?=$season->is_active ? ' so far' : ''?></span>
		<span class="stat-value"><?=$mine ? h(Fmt::ordinal($mine['rank'])) : '&ndash;'?></span>
		<span class="stat-sub"><?=$mine ? 'of ' . (int) sizeof($standings['players']) : 'not ranked yet'?></span>
	</a>
	<div class="stat">
		<span class="stat-label">Points</span>
		<span class="stat-value"><?=$mine ? (int) $mine['points'] : '&ndash;'?></span>
		<span class="stat-sub"><?=$mine ? h(Fmt::record($mine['right'], $mine['wrong'])) . ' on picks' : 'regular season'?></span>
	</div>
	<div class="stat">
		<span class="stat-label">Best week</span>
		<span class="stat-value"><?=$best_pts !== null ? (int) $best_pts : '&ndash;'?></span>
		<span class="stat-sub"><?=$best_num !== null ? 'Week ' . (int) $best_num : 'none yet'?></span>
	</div>
	<div class="stat">
		<span class="stat-label">Winnings</span>
		<span class="stat-value<?=$mine && $mine['winnings'] > 0 ? ' text-good' : ''?>"><?=h(Fmt::money($mine ? $mine['winnings'] : 0))?></span>
		<span class="stat-sub">this season</span>
	</div>
</div>

<section class="card card-flush enter" style="--i: 2" aria-labelledby="tl-title">
	<div class="card-head">
		<h2 class="card-title" id="tl-title">Weeks</h2>
		<span class="tl-key small muted" aria-hidden="true">
			<span class="pdot pdot-good"><?=Icons::svg('check')?></span>right
			<span class="pdot pdot-bad"><?=Icons::svg('x')?></span>wrong
			<span class="pdot pdot-auto"><?=Icons::svg('circle-dot')?></span>guaranteed
		</span>
	</div>
	<?php if (!sizeof($timeline)): ?>
		<p class="panel-empty muted">No weeks have been scheduled yet.</p>
	<?php else: ?>
		<ol class="timeline">
			<?php foreach ($timeline as $i => $w): ?>
				<?php
				$played = in_array($w['state'], ['live', 'complete'], true);
				$href = null;
				if ($w['state'] === 'pick') {
					$href = $shell->link('season/week/pick.php?id=' . (int) $w['id']);
				}
				elseif ($played) {
					$href = $shell->link('season/week/results.php?id=' . (int) $w['id']);
				}
				$tag = $href ? 'a' : 'div';
				$title_extra = $w['name'] !== 'Week ' . $w['num'] ? $w['name'] : '';
				?>
				<li class="tl-item is-<?=h($w['state'])?>">
					<<?=$tag?> class="tl-row"<?=$href ? ' href="' . h($href) . '"' : ''?> data-state="<?=h($w['state'])?>">
						<span class="tl-num num" aria-hidden="true"><?=(int) $w['num']?></span>
						<span class="tl-main">
							<span class="tl-name">
								<span class="sr-only">Week <?=(int) $w['num']?></span>
								<strong><?=h($title_extra !== '' ? $title_extra : 'Week ' . $w['num'])?></strong>
								<?php if ($w['is_playoffs']): ?><span class="pill pill-accent tl-pill">Playoffs</span><?php endif; ?>
							</span>
							<span class="tl-state small">
								<?php if ($w['state'] === 'pick'): ?>
									<span class="text-warn">Picks due <?=h(Fmt::kickoff($w['first_game_at'], null, $now))?></span>
								<?php elseif ($w['state'] === 'live'): ?>
									<span class="badge-live">LIVE</span> <span class="muted"><?=(int) ($w['games'] - $w['undecided'])?> of <?=(int) $w['games']?> games decided</span>
								<?php elseif ($w['state'] === 'complete'): ?>
									<span class="muted">Final &middot; <?=h(Fmt::record($w['right'], $w['wrong']))?></span>
								<?php elseif ($w['state'] === 'upcoming'): ?>
									<?php if (strtotime($w['opens_at']) > $now): ?>
										<span class="muted">Picks open <?=h(Fmt::kickoff($w['opens_at'], null, $now))?></span>
									<?php else: ?>
										<span class="muted">Games coming soon</span>
									<?php endif; ?>
								<?php else: ?>
									<span class="faint">TBD</span>
								<?php endif; ?>
							</span>
						</span>
						<span class="tl-end">
							<?php if ($w['state'] === 'pick'): ?>
								<span class="btn btn-primary btn-sm">Make picks<?=Icons::svg('chevron-right')?></span>
							<?php elseif ($played): ?>
								<?php if ($w['rank']): ?>
									<span class="rank<?=$w['rank'] <= 3 ? ' rank-' . (int) $w['rank'] : ''?>" title="Rank this week"><?=h(Fmt::ordinal($w['rank']))?></span>
								<?php endif; ?>
								<span class="tl-pts num"><b><?=(int) $w['points']?></b><small> pts</small></span>
							<?php endif; ?>
						</span>
						<?php if ($played): ?>
							<?php
							$summary = [];
							foreach ($w['results'] as $m => $r) {
								$summary[] = $m . ' ' . $result_meta[$r][2];
							}
							?>
							<span class="tl-dots" role="img" aria-label="<?=h('Picks by value: ' . implode(', ', $summary))?>">
								<?php foreach ($w['results'] as $m => $r): ?>
									<?php $meta = $result_meta[$r]; ?>
									<span class="pdot pdot-<?=h($meta[0])?>" title="<?=h($m . ' pts: ' . $meta[2])?>"><?=$meta[1] ? Icons::svg($meta[1]) : '?'?><span class="pdot-val num"><?=(int) $m?></span></span>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
					</<?=$tag?>>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</section>

<?php
$league_total = $breakdown['nfl'] + $breakdown['ncaa'];
$type_total = $breakdown['ou'] + $breakdown['spread'];
?>
<section class="card enter" style="--i: 3" aria-labelledby="bd-title">
	<div class="card-head">
		<h2 class="card-title" id="bd-title">Where your points came from</h2>
	</div>
	<?php if (!$league_total && !$type_total): ?>
		<p class="muted mb-0">No points yet this season.</p>
	<?php else: ?>
		<div class="donuts">
			<?php foreach ([
				'league' => ['By league', $league_total, [['nfl', 'NFL'], ['ncaa', 'NCAA']]],
				'type' => ['By bet type', $type_total, [['ou', 'Over/Under'], ['spread', 'Spread']]],
			] as $key => $d): ?>
				<figure class="donut">
					<div class="donut-chart">
						<canvas data-chart="<?=h($key)?>" role="img" aria-label="<?=h($d[0] . ': ' . implode(', ', array_map(function ($p) use ($breakdown) {
							return $p[1] . ' ' . $breakdown[$p[0]];
						}, $d[2])))?>"></canvas>
						<div class="donut-center"><b class="num"><?=(int) $d[1]?></b><small>pts</small></div>
					</div>
					<figcaption>
						<span class="donut-title"><?=h($d[0])?></span>
						<ul class="donut-legend">
							<?php foreach ($d[2] as $p): ?>
								<li>
									<span class="legend-swatch sw-<?=h($p[0])?>"></span>
									<span><?=h($p[1])?></span>
									<b class="num"><?=(int) $breakdown[$p[0]]?></b>
									<span class="faint num"><?=h(Fmt::pct($breakdown[$p[0]], $d[1], 0))?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
		<p class="small faint mb-0">Points from right picks in every week, playoffs included; guaranteed picks are not counted.</p>
	<?php endif; ?>
</section>

<section class="card card-flush enter" style="--i: 4" aria-labelledby="hist-title">
	<div class="card-head">
		<h2 class="card-title" id="hist-title">Seasons</h2>
	</div>
	<div class="table-wrap table-sticky">
		<table class="table table-compact history">
			<thead>
				<tr>
					<th scope="col">Season</th>
					<th class="num" scope="col">Players</th>
					<th class="num" scope="col">Finish</th>
					<th class="num" scope="col">Best week</th>
					<th class="num" scope="col">Winnings</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($history as $season_id => $h): ?>
					<tr class="<?=(int) $season_id === (int) $season->id ? 'row-me' : ''?>">
						<td><a class="player-name" href="<?=h($shell->link('season/index.php?id=' . (int) $season_id))?>"><?=h($h['season']->name)?></a></td>
						<td class="num"><?=(int) $h['players']?></td>
						<td class="num nowrap">
							<?php if ($h['finish']): ?>
								<a href="<?=h($shell->link('season/standings.php?id=' . (int) $season_id))?>"><span class="rank<?=$h['finish'] <= 3 ? ' rank-' . (int) $h['finish'] : ''?>"><?=h(Fmt::ordinal($h['finish']))?></span></a>
								<?php if ($h['season']->is_active): ?><span class="faint small">so far</span><?php endif; ?>
							<?php endif; ?>
						</td>
						<td class="num nowrap">
							<?php if ($h['best_week_rank']): ?>
								<?=h(Fmt::ordinal($h['best_week_rank']))?> <span class="faint small">Week <?=(int) $h['best_week_num']?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?=h(Fmt::money($h['winnings'], true))?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr class="lb-total">
					<td>Totals</td>
					<td class="num" colspan="3"><?=(int) sizeof($history)?> season<?=sizeof($history) == 1 ? '' : 's'?></td>
					<td class="num"><?=h(Fmt::money($total_winnings))?></td>
				</tr>
			</tfoot>
		</table>
	</div>
	<div class="card-foot">Finish and Best week count regular-season weeks only (no playoffs).</div>
</section>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
