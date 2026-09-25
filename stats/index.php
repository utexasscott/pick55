<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\Snippets\Money;

$page->setTitle('Record Book - All-Time Stats');
$page->options['stats_bar']['active'] = 'overview';

$t = $stats['totals'];
$players = $stats['players'];
$player_seasons = $stats['player_seasons'];
$seasons = $stats['seasons'];

// Players with enough weeks for percentage records
$qualified = array_filter($players, function ($p) {
	return $p['weeks'] >= STATS_MIN_WEEKS;
});
// Player-seasons in a finished full season, and those where the player also picked every week
$full_rows = array_filter($player_seasons, function ($ps) use ($seasons) {
	$s = $seasons[$ps['season_id']];
	return $s['full'] && $s['regular_done'];
});
$full_seasons = array_filter($full_rows, function ($ps) use ($seasons) {
	return $ps['weeks'] >= $seasons[$ps['season_id']]['num_weeks'];
});

/**
 * @param array $ps  player-season row
 * @return string "Name, 2018"
 */
$ps_holder = function (array $ps) {
	return stats_name($ps['user_id']) . ', <span class="text-muted">' . stats_season_short($ps['season_id']) . '</span>';
};
$ps_holders = function (array $rows) use ($ps_holder) {
	$out = [];
	foreach (array_slice($rows, 0, 3) as $ps) {
		$out[] = $ps_holder($ps);
	}
	if (sizeof($rows) > 3) {
		$out[] = '<span class="text-muted">+' . (sizeof($rows) - 3) . ' more</span>';
	}
	return implode('; ', $out);
};

// ---- The record book
$records = [];

$fame_holders = [];
foreach ($stats['fame'] as $row) {
	$fame_holders[] = stats_name($row['user_id']) . ', <span class="text-muted">' . stats_season_short($row['season_id']) . ' Wk ' . $row['week_num'] . '</span>';
}
$records[] = [
	'group' => 'Weeks',
	'label' => 'Perfect week',
	'value' => $t['perfect'] ? AllTimeStats::PERFECT . ' pts' : 'never',
	'holders' => $t['perfect'] ? implode('; ', $fame_holders) : 'No one has done it yet.',
	'link' => ['stats/fame.php', 'Wall of Fame'],
	'class' => 'text-fame',
];
$e = stats_extreme($players, function ($p) { return $p['perfect'] + $p['honor'] ?: null; });
$records[] = [
	'group' => 'Weeks',
	'label' => 'Most 50+ point weeks',
	'value' => $e['value'],
	'holders' => stats_holders($e['rows']),
	'link' => ['stats/fame.php', 'Honor Roll'],
];
$e = stats_extreme($players, function ($p) { return $p['weeks_won'] ?: null; });
$records[] = [
	'group' => 'Weeks',
	'label' => 'Most weeks won',
	'value' => $e['value'],
	'holders' => stats_holders($e['rows']),
	'sub' => 'regular-season weeks, ties count',
];
$e = stats_extreme($players, function ($p) { return $p['weeks_last'] ?: null; });
$records[] = [
	'group' => 'Weeks',
	'label' => 'Most last-place weeks',
	'value' => $e['value'],
	'holders' => stats_holders($e['rows']),
	'class' => 'text-shame',
];
$e = stats_extreme($players, function ($p) { return $p['zero'] + $p['dishonor'] ?: null; });
$records[] = [
	'group' => 'Weeks',
	'label' => 'Most single-digit weeks',
	'value' => $e['value'],
	'holders' => stats_holders($e['rows']),
	'link' => ['stats/shame.php', 'Wall of Shame'],
	'class' => 'text-shame',
];

$e = stats_extreme($full_seasons, function ($ps) { return $ps['points']; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Best season',
	'value' => $e['value'] !== null ? $e['value'] . ' pts' : '',
	'holders' => $ps_holders($e['rows']),
	'sub' => 'of 550, regular season',
	'link' => ['stats/seasons.php', 'Best Seasons'],
	'class' => 'text-fame',
];
$e = stats_extreme($full_seasons, function ($ps) { return $ps['pick_pct']; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Best season pick %',
	'value' => $e['value'] !== null ? stats_pct($e['value']) : '',
	'holders' => $ps_holders($e['rows']) . ($e['rows'] ? ' <span class="text-muted">(' . stats_record($e['rows'][0]['right'], $e['rows'][0]['wrong']) . ')</span>' : ''),
];
$e = stats_extreme($full_rows, function ($ps) { return $ps['weeks_won'] ?: null; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Most weeks won in a season',
	'value' => $e['value'],
	'holders' => $ps_holders($e['rows']),
];
$e = stats_extreme($full_seasons, function ($ps) { return $ps['points']; }, false);
$records[] = [
	'group' => 'Seasons',
	'label' => 'Worst full season',
	'value' => $e['value'] !== null ? $e['value'] . ' pts' : '',
	'holders' => $ps_holders($e['rows']),
	'sub' => 'picked all 10 weeks',
	'class' => 'text-shame',
];
$e = stats_extreme($players, function ($p) { return $p['titles'] ?: null; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Most titles',
	'value' => $e['value'],
	'holders' => stats_holders($e['rows']),
	'sub' => 'Finals winners',
	'class' => 'text-fame',
];
$e = stats_extreme($players, function ($p) { return $p['seasons'] ?: null; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Most seasons played',
	'value' => $e['value'],
	'holders' => stats_holders($e['rows']),
];

$e = stats_extreme($qualified, function ($p) { return $p['pick_pct'] ?: null; });
$records[] = [
	'group' => 'All time',
	'label' => 'Best pick %',
	'value' => $e['value'] !== null ? stats_pct($e['value']) : '',
	'holders' => stats_holders($e['rows']) . ($e['rows'] ? ' <span class="text-muted">(' . stats_record($e['rows'][0]['right'], $e['rows'][0]['wrong']) . ')</span>' : ''),
	'sub' => STATS_MIN_WEEKS . '+ weeks',
	'link' => ['stats/leaderboard.php', 'Leaderboards'],
];
$e = stats_extreme($qualified, function ($p) { return $p['pick_pct'] ?: null; }, false);
$records[] = [
	'group' => 'All time',
	'label' => 'Worst pick %',
	'value' => $e['value'] !== null ? stats_pct($e['value']) : '',
	'holders' => stats_holders($e['rows']) . ($e['rows'] ? ' <span class="text-muted">(' . stats_record($e['rows'][0]['right'], $e['rows'][0]['wrong']) . ')</span>' : ''),
	'sub' => STATS_MIN_WEEKS . '+ weeks',
	'class' => 'text-shame',
];
$e = stats_extreme($qualified, function ($p) { return $p['points_pct'] ?: null; });
$records[] = [
	'group' => 'All time',
	'label' => 'Best points %',
	'value' => $e['value'] !== null ? stats_pct($e['value']) : '',
	'holders' => stats_holders($e['rows']),
	'sub' => 'points won of points risked, ' . STATS_MIN_WEEKS . '+ weeks',
];
$e = stats_extreme($players, function ($p) { return $p['winnings'] ?: null; });
$records[] = [
	'group' => 'Money',
	'label' => 'Most money won',
	'value' => $e['value'] !== null ? Money::b($e['value']) : '',
	'holders' => stats_holders($e['rows']),
	'class' => 'text-money',
];
$e = stats_extreme($players, function ($p) { return $p['net']; });
$records[] = [
	'group' => 'Money',
	'label' => 'Best net',
	'value' => $e['value'] !== null ? Money::b($e['value'], false, true) : '',
	'holders' => stats_holders($e['rows']),
	'sub' => 'winnings minus entry fees',
	'class' => 'text-money',
];
$e = stats_extreme($players, function ($p) { return $p['net']; }, false);
$records[] = [
	'group' => 'Money',
	'label' => 'Worst net',
	'value' => $e['value'] !== null ? Money::b($e['value'], false, true) : '',
	'holders' => stats_holders($e['rows']),
	'sub' => 'winnings minus entry fees',
	'class' => 'text-shame',
];
if ($t['top_payout'] > 0) {
	$records[] = [
		'group' => 'Money',
		'label' => 'Biggest single payout',
		'value' => Money::b($t['top_payout']),
		'holders' => stats_name($t['top_payout_user_id']) . ', <span class="text-muted">' . stats_week_label($t['top_payout_week_id']) . '</span>',
		'class' => 'text-money',
	];
}

// ---- Regular-season leaders per season
$leaders_by_season = [];
foreach ($player_seasons as $ps) {
	if ($ps['finish'] == 1) {
		$leaders_by_season[$ps['season_id']][] = $ps;
	}
}

// ---- Weekly score histogram
$max_score = max(AllTimeStats::PERFECT, $stats['distribution'] ? max(array_keys($stats['distribution'])) : 0);
$labels = [];
$counts = [];
$colors = [];
foreach (range(0, $max_score) as $score) {
	$labels[] = $score;
	$counts[] = isset($stats['distribution'][$score]) ? $stats['distribution'][$score] : 0;
	if ($score == 0) {
		$colors[] = '#c0392b';
	}
	elseif ($score <= AllTimeStats::DISHONOR_MAX) {
		$colors[] = '#e6a4a4';
	}
	elseif ($score >= AllTimeStats::PERFECT) {
		$colors[] = '#d4af37';
	}
	elseif ($score >= AllTimeStats::HONOR_MIN) {
		$colors[] = '#ecd98a';
	}
	else {
		$colors[] = 'rgb(75, 192, 192)';
	}
}

ob_start();
?>
<script>
const scoreLabels = <?=json_encode($labels)?>;
const scoreCounts = <?=json_encode($counts)?>;
const scoreColors = <?=json_encode($colors)?>;
new Chart(document.getElementById('chart_all_time_scores'), {
	type: 'bar',
	data: {
		labels: scoreLabels,
		datasets: [{
			label: 'player-weeks',
			backgroundColor: scoreColors,
			data: scoreCounts,
		}]
	},
	options: {
		aspectRatio: 3.5,
		scales: {
			x: { title: { text: 'Weekly score', display: true } },
			y: { min: 0, title: { text: 'Player-weeks', display: true } }
		},
		plugins: {
			legend: { display: false },
			tooltip: {
				callbacks: {
					title: function (items) { return items[0].label + ' points'; },
					label: function (item) { return item.raw + ' player-week' + (item.raw == 1 ? '' : 's'); }
				}
			}
		}
	}
});
</script>
<?php
$page->setScripts(ob_get_clean());

ob_start();
?>
<div class="container py-4">

	<div class="stat-tiles mb-3">
		<?=stats_tile('Seasons', number_format($t['seasons']))?>
		<?=stats_tile('Players', number_format($t['players']))?>
		<?=stats_tile('Player-weeks', number_format($t['player_weeks']))?>
		<?=stats_tile('Picks made', number_format($t['right'] + $t['wrong']), stats_pct($t['pick_pct']) . ' correct')?>
		<?=stats_tile('Paid out', Money::b($t['paid_out']), '', 'tile-money')?>
		<?=stats_tile('Perfect weeks', '<a href="' . $page->link('stats/fame.php') . '">' . $t['perfect'] . '</a>', AllTimeStats::PERFECT . ' points', 'tile-fame')?>
		<?=stats_tile('Zero-point weeks', '<a href="' . $page->link('stats/shame.php') . '">' . $t['zero'] . '</a>', 'goose eggs', 'tile-shame')?>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">
			Every Week Ever Scored
			<small class="text-muted fs-6">average <?=$t['avg_score']?>, most common <?=$t['mode_score']?></small>
		</h4>
		<div class="card-body">
			<canvas id="chart_all_time_scores"></canvas>
		</div>
		<div class="card-footer text-muted small">
			<?=number_format(array_sum($counts))?> player-weeks of real picks, <?=$t['weeks']?> completed weeks since <?=h(reset($seasons)['name'])?>.
			Gold bars are the <a href="<?=$page->link('stats/fame.php')?>">Wall of Fame</a> and its Honor Roll, red bars the <a href="<?=$page->link('stats/shame.php')?>">Wall of Shame</a> and its single digits.
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Record Book</h4>
		<div class="table-responsive">
			<table class="table table-sm table-records mb-0">
				<tbody>
					<?php $last_group = null; ?>
					<?php foreach ($records as $r): ?>
						<?php if ($r['group'] !== $last_group): ?>
							<tr class="bg-light2">
								<th colspan="3" class="text-uppercase small"><?=$r['group']?></th>
							</tr>
							<?php $last_group = $r['group']; ?>
						<?php endif; ?>
						<tr>
							<td class="record-label">
								<?=$r['label']?>
								<?php if (!empty($r['sub'])): ?>
									<div class="text-muted small"><?=$r['sub']?></div>
								<?php endif; ?>
							</td>
							<td class="record-value <?=$r['class'] ?? ''?>"><?=$r['value']?></td>
							<td class="record-holders">
								<?=$r['holders']?>
								<?php if (!empty($r['link'])): ?>
									<div><a class="small" href="<?=$page->link($r['link'][0])?>"><?=$r['link'][1]?> <i class="fas fa-angle-right"></i></a></div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Season by Season</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped mb-0">
				<thead>
					<tr class="text-center">
						<th class="text-start">Season</th>
						<th>Players</th>
						<th>Weeks</th>
						<th class="text-start">Regular-Season Leader</th>
						<th class="text-start">Champion</th>
						<th>Paid Out</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach (array_reverse($seasons, true) as $season_id => $s): ?>
						<tr class="text-center <?=in_array($season_id, $my_season_ids) ? '' : 'text-muted'?>">
							<td class="text-start fw-bold">
								<?php if (in_array($season_id, $my_season_ids)): ?>
									<a href="<?=$page->link('season/standings.php?id=' . $season_id)?>"><?=h($s['name'])?></a>
								<?php else: ?>
									<?=h($s['name'])?>
								<?php endif; ?>
							</td>
							<td><?=$s['players']?></td>
							<td>
								<?=$s['regular_complete']?>/<?=$s['regular_total']?>
								<?php if ($s['is_active']): ?>
									<span class="text-muted small">so far</span>
								<?php endif; ?>
							</td>
							<td class="text-start">
								<?php if (!empty($leaders_by_season[$season_id])): ?>
									<?php $l = $leaders_by_season[$season_id][0]; ?>
									<?=stats_name($l['user_id'])?>
									<span class="text-muted small"><?=$l['points']?> pts<?=$s['is_active'] ? ', so far' : ''?></span>
								<?php endif; ?>
							</td>
							<td class="text-start">
								<?php if ($s['champion_user_id']): ?>
									<i class="fas fa-trophy text-fame me-1"></i><?=stats_name($s['champion_user_id'])?>
								<?php elseif (!$s['is_active'] && $s['playoff_weeks'] == 0): ?>
									<span class="text-muted small">no playoffs</span>
								<?php endif; ?>
							</td>
							<td><?=Money::b($s['paid_out'], true)?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="card-footer text-muted small">
			The regular-season leader is 1st on the standings page. The champion is the biggest payout of the Finals week.
			Seasons you played link to their standings.
		</div>
	</div>

	<p class="text-muted small text-center">
		A week counts once every game in it is final. Guaranteed Semifinals picks are never counted as picks.
	</p>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
