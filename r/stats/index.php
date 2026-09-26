<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\R\Fmt;
use Pick55\R\Icons;

$shell->setTitle('Record Book');

$t = $stats['totals'];
$players = $stats['players'];
$player_seasons = $stats['player_seasons'];
$seasons = $stats['seasons'];

// Players with enough weeks for percentage records
$qualified = array_filter($players, function ($p) {
	return $p['weeks'] >= RS_MIN_WEEKS;
});
// Player-seasons in a finished full season, and those where the player also picked every week
$full_rows = array_filter($player_seasons, function ($ps) use ($seasons) {
	$s = $seasons[$ps['season_id']];
	return $s['full'] && $s['regular_done'];
});
$full_seasons = array_filter($full_rows, function ($ps) use ($seasons) {
	return $ps['weeks'] >= $seasons[$ps['season_id']]['num_weeks'];
});

$pts = function ($v) {
	return (int) $v . '<small>pts</small>';
};
$rec_of = function (array $rows) {
	return $rows ? ' <span class="faint nowrap">(' . h(Fmt::record($rows[0]['right'], $rows[0]['wrong'])) . ')</span>' : '';
};

// ---- The record book (the classic page's list, grouped)
$records = [];

$fame_holders = [];
foreach ($stats['fame'] as $row) {
	$class = rs_row_class($row['user_id']);
	$fame_holders[] = '<span class="holder' . ($class ? ' is-' . substr($class, 4) : '') . '">' . h(rs_name($row['user_id'])) . '</span> <span class="faint">' . h(rs_season_short($row['season_id'])) . ' Wk ' . (int) $row['week_num'] . '</span>';
}
$records[] = [
	'group' => 'Weeks',
	'label' => 'Perfect week',
	'value' => $t['perfect'] ? $pts(AllTimeStats::PERFECT) : 'never',
	'holders' => $t['perfect'] ? implode('; ', $fame_holders) : 'No one has done it yet.',
	'link' => ['stats/fame.php', 'Wall of Fame'],
	'tone' => 'fame',
];
$e = rs_extreme($players, function ($p) { return $p['perfect'] + $p['honor'] ?: null; });
$records[] = [
	'group' => 'Weeks',
	'label' => 'Most 50+ point weeks',
	'value' => $e['value'],
	'holders' => rs_holders($e['rows']),
	'link' => ['stats/fame.php', 'Honor Roll'],
];
$e = rs_extreme($players, function ($p) { return $p['weeks_won'] ?: null; });
$records[] = [
	'group' => 'Weeks',
	'label' => 'Most weeks won',
	'value' => $e['value'],
	'holders' => rs_holders($e['rows']),
	'sub' => 'regular-season weeks, ties count',
];
$e = rs_extreme($players, function ($p) { return $p['weeks_last'] ?: null; });
$records[] = [
	'group' => 'Weeks',
	'label' => 'Most last-place weeks',
	'value' => $e['value'],
	'holders' => rs_holders($e['rows']),
	'tone' => 'shame',
];
$e = rs_extreme($players, function ($p) { return $p['zero'] + $p['dishonor'] ?: null; });
$records[] = [
	'group' => 'Weeks',
	'label' => 'Most single-digit weeks',
	'value' => $e['value'],
	'holders' => rs_holders($e['rows']),
	'link' => ['stats/shame.php', 'Wall of Shame'],
	'tone' => 'shame',
];

$e = rs_extreme($full_seasons, function ($ps) { return $ps['points']; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Best season',
	'value' => $e['value'] !== null ? $pts($e['value']) : '',
	'holders' => rs_ps_holders($e['rows']),
	'sub' => 'of 550, regular season',
	'link' => ['stats/seasons.php', 'Best Seasons'],
	'tone' => 'fame',
];
$e = rs_extreme($full_seasons, function ($ps) { return $ps['pick_pct']; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Best season pick %',
	'value' => $e['value'] !== null ? h(rs_pct($e['value'])) : '',
	'holders' => rs_ps_holders($e['rows']) . $rec_of($e['rows']),
];
$e = rs_extreme($full_rows, function ($ps) { return $ps['weeks_won'] ?: null; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Most weeks won in a season',
	'value' => $e['value'],
	'holders' => rs_ps_holders($e['rows']),
];
$e = rs_extreme($full_seasons, function ($ps) { return $ps['points']; }, false);
$records[] = [
	'group' => 'Seasons',
	'label' => 'Worst full season',
	'value' => $e['value'] !== null ? $pts($e['value']) : '',
	'holders' => rs_ps_holders($e['rows']),
	'sub' => 'picked all 10 weeks',
	'tone' => 'shame',
];
$e = rs_extreme($players, function ($p) { return $p['titles'] ?: null; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Most titles',
	'value' => $e['value'],
	'holders' => rs_holders($e['rows']),
	'sub' => 'Finals winners',
	'tone' => 'fame',
];
$e = rs_extreme($players, function ($p) { return $p['seasons'] ?: null; });
$records[] = [
	'group' => 'Seasons',
	'label' => 'Most seasons played',
	'value' => $e['value'],
	'holders' => rs_holders($e['rows']),
];

$e = rs_extreme($qualified, function ($p) { return $p['pick_pct'] ?: null; });
$records[] = [
	'group' => 'All time',
	'label' => 'Best pick %',
	'value' => $e['value'] !== null ? h(rs_pct($e['value'])) : '',
	'holders' => rs_holders($e['rows']) . $rec_of($e['rows']),
	'sub' => RS_MIN_WEEKS . '+ weeks',
	'link' => ['stats/leaderboard.php', 'Leaderboards'],
];
$e = rs_extreme($qualified, function ($p) { return $p['pick_pct'] ?: null; }, false);
$records[] = [
	'group' => 'All time',
	'label' => 'Worst pick %',
	'value' => $e['value'] !== null ? h(rs_pct($e['value'])) : '',
	'holders' => rs_holders($e['rows']) . $rec_of($e['rows']),
	'sub' => RS_MIN_WEEKS . '+ weeks',
	'tone' => 'shame',
];
$e = rs_extreme($qualified, function ($p) { return $p['points_pct'] ?: null; });
$records[] = [
	'group' => 'All time',
	'label' => 'Best points %',
	'value' => $e['value'] !== null ? h(rs_pct($e['value'])) : '',
	'holders' => rs_holders($e['rows']),
	'sub' => 'points won of points risked, ' . RS_MIN_WEEKS . '+ weeks',
];
$e = rs_extreme($players, function ($p) { return $p['winnings'] ?: null; });
$records[] = [
	'group' => 'Money',
	'label' => 'Most money won',
	'value' => $e['value'] !== null ? h(Fmt::money($e['value'])) : '',
	'holders' => rs_holders($e['rows']),
	'tone' => 'money',
];
$e = rs_extreme($players, function ($p) { return $p['net']; });
$records[] = [
	'group' => 'Money',
	'label' => 'Best net',
	'value' => $e['value'] !== null ? h(Fmt::money($e['value'], false, true)) : '',
	'holders' => rs_holders($e['rows']),
	'sub' => 'winnings minus entry fees',
	'tone' => 'money',
];
$e = rs_extreme($players, function ($p) { return $p['net']; }, false);
$records[] = [
	'group' => 'Money',
	'label' => 'Worst net',
	'value' => $e['value'] !== null ? h(Fmt::money($e['value'], false, true)) : '',
	'holders' => rs_holders($e['rows']),
	'sub' => 'winnings minus entry fees',
	'tone' => 'shame',
];
if ($t['top_payout'] > 0) {
	$class = rs_row_class($t['top_payout_user_id']);
	$records[] = [
		'group' => 'Money',
		'label' => 'Biggest single payout',
		'value' => h(Fmt::money($t['top_payout'])),
		'holders' => '<span class="holder' . ($class ? ' is-' . substr($class, 4) : '') . '">' . h(rs_name($t['top_payout_user_id'])) . '</span> <span class="faint">' . rs_week_label($t['top_payout_week_id']) . '</span>',
		'tone' => 'money',
	];
}

$groups = [];
foreach ($records as $r) {
	$groups[$r['group']][] = $r;
}
$group_icons = ['Weeks' => 'calendar', 'Seasons' => 'trophy', 'All time' => 'target', 'Money' => 'dollar-sign'];

// ---- Regular-season leaders per season
$leaders_by_season = [];
foreach ($player_seasons as $ps) {
	if ($ps['finish'] == 1) {
		$leaders_by_season[$ps['season_id']][] = $ps;
	}
}

// ---- Weekly score histogram (tones become token colours in stats.js)
$max_score = max(AllTimeStats::PERFECT, $stats['distribution'] ? max(array_keys($stats['distribution'])) : 0);
$labels = [];
$counts = [];
$tones = [];
foreach (range(0, $max_score) as $score) {
	$labels[] = $score;
	$counts[] = isset($stats['distribution'][$score]) ? (int) $stats['distribution'][$score] : 0;
	if ($score == 0) {
		$tones[] = 'zero';
	}
	elseif ($score <= AllTimeStats::DISHONOR_MAX) {
		$tones[] = 'dishonor';
	}
	elseif ($score >= AllTimeStats::PERFECT) {
		$tones[] = 'perfect';
	}
	elseif ($score >= AllTimeStats::HONOR_MIN) {
		$tones[] = 'honor';
	}
	else {
		$tones[] = 'base';
	}
}
$first_season = reset($seasons);

$shell->setModule('stats', [
	'page' => 'book',
	'histogram' => ['labels' => $labels, 'counts' => $counts, 'tones' => $tones],
]);

ob_start();
?>
<div class="stats-page">
	<?=rs_head('book', 'Every week ever scored, since ' . ($first_season ? $first_season['name'] : 'the start') . '.')?>

	<section class="stats tiles" aria-label="Totals">
		<?=rs_tile('Seasons', h(Fmt::num($t['seasons'])), '', '', 2)?>
		<?=rs_tile('Players', h(Fmt::num($t['players'])), '', '', 3)?>
		<?=rs_tile('Player-weeks', h(Fmt::num($t['player_weeks'])), '', '', 4)?>
		<?=rs_tile('Picks made', h(Fmt::num($t['right'] + $t['wrong'])), h(rs_pct($t['pick_pct'])) . ' correct', '', 5)?>
		<?=rs_tile('Paid out', h(Fmt::money($t['paid_out'])), '', 'money', 6)?>
		<?=rs_tile('Perfect weeks', '<a href="' . h($shell->link('stats/fame.php')) . '">' . (int) $t['perfect'] . '</a>', AllTimeStats::PERFECT . ' points', 'fame', 7)?>
		<?=rs_tile('Zero-point weeks', '<a href="' . h($shell->link('stats/shame.php')) . '">' . (int) $t['zero'] . '</a>', 'goose eggs', 'shame', 8)?>
	</section>

	<section class="card chart-card enter" style="--i: 4" aria-labelledby="hist-title">
		<div class="card-head">
			<div>
				<h2 class="card-title" id="hist-title">Every week ever scored</h2>
				<p class="card-sub">Average <b class="num"><?=h($t['avg_score'])?></b> &middot; most common <b class="num"><?=h($t['mode_score'])?></b></p>
			</div>
		</div>
		<div class="chart-box" data-chart="histogram" role="img" aria-label="Histogram of weekly scores from 0 to <?=(int) $max_score?>: <?=h(Fmt::num(array_sum($counts)))?> player-weeks, average <?=h($t['avg_score'])?>, most common <?=h($t['mode_score'])?>.">
			<canvas></canvas>
			<div class="chart-loading skeleton" aria-hidden="true"></div>
		</div>
		<ul class="chart-legend" aria-label="Legend">
			<li><span class="swatch sw-perfect"></span>Perfect (<?=AllTimeStats::PERFECT?>)</li>
			<li><span class="swatch sw-honor"></span>Honor roll (<?=AllTimeStats::HONOR_MIN?>&ndash;<?=AllTimeStats::PERFECT - 1?>)</li>
			<li><span class="swatch sw-dishonor"></span>Single digits (1&ndash;<?=AllTimeStats::DISHONOR_MAX?>)</li>
			<li><span class="swatch sw-zero"></span>Goose egg (0)</li>
		</ul>
		<div class="card-foot">
			<?=h(Fmt::num(array_sum($counts)))?> player-weeks of real picks, <?=(int) $t['weeks']?> completed weeks since <?=h($first_season ? $first_season['name'] : '')?>.
			Gold is the <a href="<?=h($shell->link('stats/fame.php'))?>">Wall of Fame</a> and its Honor Roll, red the <a href="<?=h($shell->link('stats/shame.php'))?>">Wall of Shame</a> and its single digits.
		</div>
	</section>

	<h2 class="section-title">The record book</h2>
	<div class="records">
		<?php $gi = 0; ?>
		<?php foreach ($groups as $group => $rows): ?>
			<section class="card card-flush record-group enter" style="--i: <?=5 + $gi++?>" aria-labelledby="rg-<?=h(preg_replace('/[^a-z]/', '', strtolower($group)))?>">
				<div class="card-head">
					<h3 class="card-title" id="rg-<?=h(preg_replace('/[^a-z]/', '', strtolower($group)))?>"><?=Icons::svg($group_icons[$group])?><?=h($group)?></h3>
				</div>
				<ul class="list record-list">
					<?php foreach ($rows as $r): ?>
						<li class="record<?=!empty($r['tone']) ? ' tone-' . h($r['tone']) : ''?>" data-record="<?=h($r['label'])?>">
							<div class="record-main">
								<div class="record-label"><?=h($r['label'])?></div>
								<?php if (!empty($r['sub'])): ?>
									<div class="record-sub"><?=h($r['sub'])?></div>
								<?php endif; ?>
								<div class="record-holders"><?=$r['holders']?></div>
								<?php if (!empty($r['link'])): ?>
									<a class="record-link" href="<?=h($shell->link($r['link'][0]))?>"><?=h($r['link'][1])?><?=Icons::svg('chevron-right')?></a>
								<?php endif; ?>
							</div>
							<div class="record-value num"><?=$r['value'] !== null && $r['value'] !== '' ? $r['value'] : '&ndash;'?></div>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	</div>

	<section class="card card-flush enter" style="--i: 9" aria-labelledby="sbs-title">
		<div class="card-head">
			<h2 class="card-title" id="sbs-title">Season by season</h2>
		</div>
		<div class="table-wrap">
			<table class="table table-compact table-sticky seasons-table">
				<thead>
					<tr>
						<th>Season</th>
						<th class="num">Players</th>
						<th class="num">Weeks</th>
						<th>Regular-season leader</th>
						<th>Champion</th>
						<th class="num">Paid out</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach (array_reverse($seasons, true) as $season_id => $s): ?>
						<?php $played = in_array((int) $season_id, $my_season_ids, true); ?>
						<tr class="<?=$played ? 'is-mine' : 'is-other'?>">
							<td class="cell-season"><?=rs_season_link($season_id)?></td>
							<td class="num"><?=(int) $s['players']?></td>
							<td class="num nowrap"><?=(int) $s['regular_complete']?>/<?=(int) $s['regular_total']?><?php if ($s['is_active']): ?> <span class="faint small">so far</span><?php endif; ?></td>
							<td class="nowrap">
								<?php if (!empty($leaders_by_season[$season_id])): ?>
									<?php $l = $leaders_by_season[$season_id][0]; ?>
									<?=rs_name_html($l['user_id'])?>
									<span class="faint small num"><?=(int) $l['points']?> pts<?=$s['is_active'] ? ', so far' : ''?></span>
								<?php endif; ?>
							</td>
							<td class="nowrap">
								<?php if ($s['champion_user_id']): ?>
									<span class="champ"><?=Icons::svg('trophy')?><?=rs_name_html($s['champion_user_id'])?></span>
								<?php elseif (!$s['is_active'] && $s['playoff_weeks'] == 0): ?>
									<span class="faint small">no playoffs</span>
								<?php endif; ?>
							</td>
							<td class="num"><?=h(Fmt::money($s['paid_out'], true))?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="card-foot">
			The regular-season leader is 1st on the standings page. The champion is the biggest payout of the Finals week.
			Seasons you played link to their standings.
		</div>
	</section>

	<p class="stats-note">A week counts once every game in it is final. Guaranteed Semifinals picks are never counted as picks.</p>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
