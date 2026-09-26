<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\App;
use Pick55\Auth;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\SeasonStandings;
use Pick55\R\Shell;

Shell::guard();

$shell = new Shell;
$shell->setNav('standings');
$shell->addStyle('css/pages/season.css');
$me = Auth::user();
$season = App::get()->getSeason();

if (!$season || !$season->hasPlayer($me->id)) {
	$shell->setTitle('Standings');
	$shell->setContent($season
		? Shell::empty(
			'You are not in the ' . $season->name,
			'Standings are shown to the players of a season. Ask the commissioner to add you.',
			[['label' => 'All-time stats', 'href' => $shell->link('stats/index.php'), 'kind' => 'primary']],
			'users'
		)
		: Shell::empty('No season yet', 'There is no season to show.', [], 'calendar'));
	print $shell->render();
	exit();
}

$shell->setTitle('Standings ' . "\u{00B7} " . $season->name);
$shell->addScript('../../static/js/chart.js');
$shell->addScript('js/pages/season.js');

$data = SeasonStandings::get($season);
$me_id = (int) $me->id;
$friends = $shell->ctx->friendIds();

// Names at render time (never cached): every linked player.
$names = [];
foreach ($season->getPlayers() as $user) {
	$names[(int) $user->id] = $user->getDisplayName();
}
$name = function ($uid) use ($names) {
	return isset($names[$uid]) ? $names[$uid] : 'Player #' . $uid;
};
$row_class = function ($uid) use ($me_id, $friends) {
	return $uid === $me_id ? 'row-me' : (isset($friends[$uid]) ? 'row-friend' : '');
};

$players = $data['players'];
$totals = $data['totals'];
$weeks = $data['weeks'];
$mine = isset($players[$me_id]) ? $players[$me_id] : null;
$num_players = sizeof($players);
$last_week = sizeof($weeks) ? end($weeks) : null;
$pct = function ($num, $den) {
	return sprintf('%01.1f', $num / max(1, $den) * 100);
};
$record_pct = function ($right, $wrong) {
	return (int) floor($right / max(1, $right + $wrong) * 100);
};
$week_max = 55;
foreach ($players as $s) {
	foreach ($s['score_by_week_num'] as $v) {
		$week_max = max($week_max, $v);
	}
}

// Rank trajectory: top 8 now, plus me and my friends.
$traj_ids = array_slice($data['order'], 0, 8);
foreach ($data['order'] as $uid) {
	if (($uid === $me_id || isset($friends[$uid])) && !in_array($uid, $traj_ids, true)) {
		$traj_ids[] = $uid;
	}
}
$traj = [];
foreach ($traj_ids as $uid) {
	$s = $players[$uid];
	$series = [];
	foreach ($weeks as $w) {
		$series[] = isset($s['rank_by_week_num'][$w['num']]) ? $s['rank_by_week_num'][$w['num']] : null;
	}
	$traj[] = [
		'id' => $uid,
		'name' => $name($uid),
		'me' => $uid === $me_id,
		'friend' => isset($friends[$uid]),
		'rank' => $s['rank'],
		'ranks' => $series,
	];
}
$my_scores = [];
if ($mine) {
	foreach ($mine['score_by_week_num'] as $v) {
		$my_scores[$v] = true;
	}
}

$shell->setModule('standings', [
	'season_id' => (int) $season->id,
	'players' => $num_players,
	'weeks' => array_map(function ($w) {
		return ['num' => $w['num'], 'complete' => $w['complete']];
	}, $weeks),
	'trajectory' => $traj,
	'distribution' => [
		'labels' => array_keys($data['week_scores_count']),
		'counts' => array_values($data['week_scores_count']),
		'mine' => array_keys($my_scores),
	],
	'chart_src' => $shell->classicLink('static/js/chart.js'),
]);

/** "34–18 (65%)" in a cell, sortable by right. */
$record_cell = function ($right, $wrong) use ($record_pct) {
	return '<td class="num nowrap" data-v="' . (int) $right . '">' . h(Fmt::record($right, $wrong)) . ' <span class="faint">' . $record_pct($right, $wrong) . '%</span></td>';
};

/** The first cell: rank medal and name. */
$player_cell = function ($uid, $rank = null) use ($name, $me_id, $friends) {
	$html = '<td class="lb-player" data-v="' . h($rank === null ? $name($uid) : $rank) . '">';
	if ($rank !== null) {
		$html .= '<span class="rank' . ($rank <= 3 ? ' rank-' . (int) $rank : '') . '">' . (int) $rank . '</span>';
	}
	$html .= '<span class="player-name truncate">' . h($name($uid)) . ($uid === $me_id ? ' <span class="faint">(you)</span>' : '') . '</span>';
	if (isset($friends[$uid])) {
		$html .= '<span class="friend-mark" title="Friend"></span>';
	}
	return $html . '</td>';
};

$panels = [
	'trajectory' => ['Rank trajectory', 'trending-up'],
	'weekly' => ['Weekly scores', 'bar-chart'],
	'league' => ['NFL vs NCAA', 'football'],
	'type' => ['O/U vs Spread', 'target'],
	'values' => ['Pick values', 'list-ordered'],
];

ob_start();
?>
<header class="page-head season-head enter">
	<div>
		<span class="eyebrow"><?=h($season->name)?><?=$season->is_active ? '' : ' · Final'?></span>
		<h1 class="page-title">Standings</h1>
		<p class="page-sub"><?=(int) $num_players?> players &middot; regular season<?=$last_week ? ', through Week ' . (int) $last_week['num'] . ($last_week['complete'] ? '' : ' (in progress)') : ''?></p>
	</div>
	<?=SeasonStandings::picker($shell, $season, $me_id, 'season/standings.php')?>
</header>

<?php if (!$num_players): ?>
	<?=Shell::empty('No standings yet', 'Standings appear once players are confirmed and games are decided.', [], 'medal')?>
<?php else: ?>

<div class="stats season-stats enter" style="--i: 1">
	<div class="stat">
		<span class="stat-label">Place</span>
		<span class="stat-value"><?=$mine ? h(Fmt::ordinal($mine['rank'])) : '&ndash;'?></span>
		<span class="stat-sub">of <?=(int) $num_players?><?=$season->is_active ? ' so far' : ''?></span>
	</div>
	<div class="stat">
		<span class="stat-label">Points</span>
		<span class="stat-value"><?=$mine ? (int) $mine['points'] : '&ndash;'?></span>
		<span class="stat-sub"><?=$mine ? h($pct($mine['points'], $mine['max'])) . '% of possible' : 'not ranked'?></span>
	</div>
	<div class="stat">
		<span class="stat-label">Behind leader</span>
		<?php $behind = $mine ? $data['leader_points'] - $mine['points'] : null; ?>
		<span class="stat-value"><?=$mine ? ($behind > 0 ? (int) $behind : '0') : '&ndash;'?></span>
		<span class="stat-sub"><?=$mine ? ($behind > 0 ? 'points back' : ($mine['rank'] === 1 ? 'you lead' : 'tied for the lead')) : ''?></span>
	</div>
	<div class="stat">
		<span class="stat-label">Winnings</span>
		<span class="stat-value<?=$mine && $mine['winnings'] > 0 ? ' text-good' : ''?>"><?=$mine ? h(Fmt::money($mine['winnings'])) : '&ndash;'?></span>
		<span class="stat-sub">this season</span>
	</div>
</div>

<section class="card card-flush enter" style="--i: 2" aria-labelledby="lb-title">
	<div class="card-head">
		<h2 class="card-title" id="lb-title">Leaderboard</h2>
	</div>
	<div class="table-wrap table-sticky">
		<table class="table table-compact lb" data-sortable>
			<thead>
				<tr>
					<th data-sort="num" aria-sort="ascending" scope="col">Player</th>
					<th class="num" data-sort="num" data-desc scope="col">Pts</th>
					<th class="num" data-sort="num" data-desc scope="col">Pct</th>
					<th class="num" data-sort="num" data-desc scope="col">Record</th>
					<th class="lb-weeks-h" scope="col">Weeks</th>
					<th class="num" data-sort="num" data-desc scope="col">Won</th>
					<?php if ($data['has_playoffs']): ?>
						<th class="num" data-sort="num" data-desc scope="col" title="Free points in the playoffs for your regular-season finish">Playoff freebies</th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($players as $uid => $s): ?>
					<tr class="<?=$row_class($uid)?>" data-user="<?=(int) $uid?>">
						<?=$player_cell($uid, $s['rank'])?>
						<td class="num lb-pts" data-v="<?=(int) $s['points']?>"><?=(int) $s['points']?></td>
						<td class="num" data-v="<?=h($pct($s['points'], $s['max']))?>"><?=h($pct($s['points'], $s['max']))?>%</td>
						<?=$record_cell($s['right'], $s['wrong'])?>
						<td class="lb-weeks">
							<?php
							$label = [];
							foreach ($weeks as $w) {
								$label[] = 'Week ' . $w['num'] . ': ' . (isset($s['score_by_week_num'][$w['num']]) ? $s['score_by_week_num'][$w['num']] : 0);
							}
							?>
							<span class="wk-strip" role="img" aria-label="<?=h(implode(', ', $label))?>">
								<?php foreach ($weeks as $w): ?>
									<?php
									$v = isset($s['score_by_week_num'][$w['num']]) ? (int) $s['score_by_week_num'][$w['num']] : 0;
									$tip = 'Week ' . $w['num'] . ': ' . $v . ($w['complete'] ? '' : ' (in progress)');
									?>
									<span class="wk-bar<?=$w['complete'] ? '' : ' is-partial'?>" style="--h: <?=round(max(0.04, $v / $week_max), 3)?>" title="<?=h($tip)?>" data-tip="<?=h($tip)?>"></span>
								<?php endforeach; ?>
							</span>
						</td>
						<td class="num" data-v="<?=h((float) $s['winnings'])?>"><?=h(Fmt::money($s['winnings'], true))?></td>
						<?php if ($data['has_playoffs']): ?>
							<td class="num" data-v="<?=(int) $s['free_points']?>"><?=$s['free_points'] ? (int) $s['free_points'] : ''?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr class="lb-total">
					<td>Totals</td>
					<td class="num" colspan="2"><?=(int) $totals['points']?> / <?=(int) $totals['max']?></td>
					<?=$record_cell($totals['right'], $totals['wrong'])?>
					<td></td>
					<td class="num"><?=h(Fmt::money($totals['winnings']))?></td>
					<?php if ($data['has_playoffs']): ?>
						<td></td>
					<?php endif; ?>
				</tr>
			</tfoot>
		</table>
	</div>
	<div class="card-foot">Regular-season weeks only; guaranteed picks count as right. Pct is points of the points possible.</div>
</section>

<section class="card card-flush analysis enter" style="--i: 3" aria-label="Season analysis">
	<div class="card-head analysis-head">
		<div class="seg seg-sm analysis-seg" role="tablist" aria-label="Analysis">
			<?php $first = true; ?>
			<?php foreach ($panels as $key => $p): ?>
				<button type="button" role="tab" id="tab-<?=h($key)?>" aria-controls="panel-<?=h($key)?>" aria-selected="<?=$first ? 'true' : 'false'?>" tabindex="<?=$first ? '0' : '-1'?>" data-panel="<?=h($key)?>"><?=Icons::svg($p[1])?><span><?=h($p[0])?></span></button>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="panel" id="panel-trajectory" role="tabpanel" aria-labelledby="tab-trajectory" data-panel-body="trajectory">
		<?php if (sizeof($weeks) < 1): ?>
			<p class="panel-empty muted">The trajectory starts after the first decided game.</p>
		<?php else: ?>
			<p class="panel-note muted small">Season rank after each week: the top 8 now, plus you<?=sizeof($friends) ? ' and your friends' : ''?>.</p>
			<div class="chart-box chart-tall"><canvas data-chart="trajectory" role="img" aria-label="Season rank after each week"></canvas></div>
			<ol class="traj-legend">
				<?php foreach ($traj as $t): ?>
					<li class="<?=$t['me'] ? 'is-me' : ($t['friend'] ? 'is-friend' : '')?>"><span class="traj-swatch"></span><span class="num"><?=(int) $t['rank']?>.</span> <?=h($t['name'])?></li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</div>

	<div class="panel" id="panel-weekly" role="tabpanel" aria-labelledby="tab-weekly" data-panel-body="weekly" hidden>
		<p class="panel-note muted small">How often each weekly score happened across every player and week<?=sizeof($my_scores) ? '; your weeks are highlighted' : ''?>.</p>
		<div class="chart-box"><canvas data-chart="weekly" role="img" aria-label="Weekly score distribution"></canvas></div>
	</div>

	<?php foreach (['league' => [['ncaa', 'NCAA'], ['nfl', 'NFL']], 'type' => [['ou', 'O/U'], ['spread', 'Spread']]] as $key => $groups): ?>
		<div class="panel" id="panel-<?=h($key)?>" role="tabpanel" aria-labelledby="tab-<?=h($key)?>" data-panel-body="<?=h($key)?>" hidden>
			<div class="table-wrap table-sticky">
				<table class="table table-compact split" data-sortable>
					<thead>
						<tr class="split-groups">
							<th></th>
							<?php foreach ($groups as $g): ?>
								<th colspan="3" class="split-<?=h($g[0])?>"><span class="tag tag-<?=h($g[0])?>"><?=h($g[1])?></span></th>
							<?php endforeach; ?>
						</tr>
						<tr>
							<th data-sort="str" scope="col">Player</th>
							<?php foreach ($groups as $g): ?>
								<th class="num split-<?=h($g[0])?>" data-sort="num" data-desc scope="col">Picks</th>
								<th class="num" data-sort="num" data-desc scope="col">Pts</th>
								<th class="num" data-sort="num" data-desc scope="col" title="Points won of the points placed">Pts / Max</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($players as $uid => $s): ?>
							<tr class="<?=$row_class($uid)?>">
								<?=$player_cell($uid)?>
								<?php foreach ($groups as $g): ?>
									<?php $k = $g[0]; $eff = $pct($s['points_' . $k], $s['points_' . $k] + $s['points_wrong_' . $k]); ?>
									<?=$record_cell($s['right_' . $k], $s['wrong_' . $k])?>
									<td class="num" data-v="<?=(int) $s['points_' . $k]?>"><?=(int) $s['points_' . $k]?></td>
									<td class="num" data-v="<?=h($eff)?>"><?=h($eff)?>%</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr class="lb-total">
							<td>Totals</td>
							<?php foreach ($groups as $g): ?>
								<?php $k = $g[0]; ?>
								<?=$record_cell($totals['right_' . $k], $totals['wrong_' . $k])?>
								<td class="num"><?=(int) $totals['points_' . $k]?></td>
								<td class="num"><?=h($pct($totals['points_' . $k], $totals['points_' . $k] + $totals['points_wrong_' . $k]))?>%</td>
							<?php endforeach; ?>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
	<?php endforeach; ?>

	<div class="panel" id="panel-values" role="tabpanel" aria-labelledby="tab-values" data-panel-body="values" hidden>
		<p class="panel-note muted small">Right picks at each point value (guaranteed picks aside). Efficiency compares points per right pick with 220/48, the average value of a right pick.</p>
		<div class="table-wrap table-sticky">
			<table class="table table-compact values" data-sortable>
				<thead>
					<tr>
						<th data-sort="str" scope="col">Player</th>
						<?php foreach (range(10, 0) as $i): ?>
							<th class="num" data-sort="num" data-desc scope="col"><?=$i?></th>
						<?php endforeach; ?>
						<th class="num" data-sort="num" data-desc scope="col" title="Points per right pick">Pts / right</th>
						<th class="num" data-sort="num" data-desc scope="col" title="Points per decided pick">Pts / pick</th>
						<th class="num" data-sort="num" data-desc scope="col">Efficiency</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($players as $uid => $s): ?>
						<?php
						$ppr = sprintf('%01.1f', $s['points'] / max(1, $s['right']));
						$ppp = sprintf('%01.1f', $s['points'] / max(1, $s['right'] + $s['wrong']));
						$eff = sprintf('%01.1f', $s['points'] / max(1, $s['right']) / (220 / 48) * 100);
						?>
						<tr class="<?=$row_class($uid)?>">
							<?=$player_cell($uid)?>
							<?php foreach (range(10, 0) as $i): ?>
								<?php $c = isset($s['multipliers'][$i]) ? (int) $s['multipliers'][$i] : 0; ?>
								<td class="num<?=$c ? '' : ' faint'?>" data-v="<?=$c?>"><?=$c?></td>
							<?php endforeach; ?>
							<td class="num" data-v="<?=h($ppr)?>"><?=h($ppr)?></td>
							<td class="num" data-v="<?=h($ppp)?>"><?=h($ppp)?></td>
							<td class="num" data-v="<?=h($eff)?>"><?=h($eff)?>%</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="lb-total">
						<td>Totals</td>
						<?php foreach (range(10, 0) as $i): ?>
							<td class="num"><?=isset($totals['multipliers'][$i]) ? (int) $totals['multipliers'][$i] : 0?></td>
						<?php endforeach; ?>
						<td class="num"><?=h(sprintf('%01.1f', $totals['points'] / max(1, $totals['right'])))?></td>
						<td class="num"><?=h(sprintf('%01.1f', $totals['points'] / max(1, $totals['right'] + $totals['wrong'])))?></td>
						<td class="num"><?=h(sprintf('%01.1f', $totals['points'] / max(1, $totals['right']) / (220 / 48) * 100))?>%</td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>
</section>
<?php endif; ?>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
