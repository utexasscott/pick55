<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\Shell;

$shell->setTitle('Best Seasons');
$shell->setModule('stats', ['page' => 'seasons', 'min' => 10]);

$seasons = $stats['seasons'];

// Full seasons (10 weeks of 55) whose regular season is over
$rows = array_filter($stats['player_seasons'], function ($ps) use ($seasons) {
	$s = $seasons[$ps['season_id']];
	return $s['full'] && $s['regular_done'];
});
$rows = rs_stable_sort($rows, function ($a, $b) {
	if ($a['points'] != $b['points']) return $b['points'] - $a['points'];
	if ($a['right'] != $b['right']) return $b['right'] - $a['right'];
	return $b['season_id'] - $a['season_id'];
});
$complete = array_filter($rows, function ($ps) use ($seasons) {
	return $ps['weeks'] >= $seasons[$ps['season_id']]['num_weeks'];
});
$max_points = 0;
foreach ($seasons as $s) {
	if ($s['full']) {
		$max_points = $s['num_weeks'] * AllTimeStats::PERFECT;
	}
}

$tiles = ['best' => [], 'worst' => []];
foreach ([true, false] as $highest) {
	$side = $highest ? 'best' : 'worst';
	$tone = $highest ? 'fame' : 'shame';
	$i = 3;
	$e = rs_extreme($complete, function ($ps) { return $ps['points']; }, $highest);
	$tiles[$side][] = rs_tile(($highest ? 'Best' : 'Worst') . ' season', $e['value'] !== null ? (int) $e['value'] . '<small>pts</small>' : '',
		rs_ps_holders($e['rows'], 2, '<br>'), $tone, $i++);
	$e = rs_extreme($complete, function ($ps) { return $ps['pick_pct'] ?: null; }, $highest);
	$tiles[$side][] = rs_tile(($highest ? 'Best' : 'Worst') . ' pick %', $e['value'] !== null ? h(rs_pct($e['value'])) : '',
		rs_ps_holders($e['rows'], 2, '<br>'), $tone, $i++);
	$e = rs_extreme($complete, function ($ps) { return $ps['points_pct'] ?: null; }, $highest);
	$tiles[$side][] = rs_tile(($highest ? 'Best' : 'Worst') . ' points %', $e['value'] !== null ? h(rs_pct($e['value'])) : '',
		rs_ps_holders($e['rows'], 2, '<br>'), $tone, $i++);
	if ($highest) {
		$e = rs_extreme($rows, function ($ps) { return $ps['weeks_won'] ?: null; });
		$tiles[$side][] = rs_tile('Most weeks won', $e['value'] !== null ? (int) $e['value'] : '', rs_ps_holders($e['rows'], 2, '<br>'), 'fame', $i++);
		$e = rs_extreme($rows, function ($ps) { return $ps['winnings'] ?: null; });
		$tiles[$side][] = rs_tile('Biggest season', $e['value'] !== null ? h(Fmt::money($e['value'])) : '', rs_ps_holders($e['rows'], 2, '<br>'), 'money', $i++);
	}
	else {
		$e = rs_extreme($complete, function ($ps) { return $ps['best_week'] ? $ps['best_week']['points'] : null; }, false);
		$tiles[$side][] = rs_tile('Lowest best week', $e['value'] !== null ? (int) $e['value'] : '', rs_ps_holders($e['rows'], 2, '<br>') . ($e['rows'] ? '<br><span class="faint">never topped it all season</span>' : ''), 'shame', $i++);
		$e = rs_extreme($complete, function ($ps) { return $ps['finish'] == $ps['players'] && $ps['players'] > 1 ? $ps['players'] : null; });
		$tiles[$side][] = rs_tile('Last in the biggest field', $e['value'] !== null ? 'Last of ' . (int) $e['value'] : '', rs_ps_holders($e['rows'], 2, '<br>'), 'shame', $i++);
	}
}

ob_start();
?>
<div class="stats-page">
	<?=rs_head('seasons', 'Every full regular season, ranked. ' . $max_points . ' points possible.')?>

	<?php if (!sizeof($rows)): ?>
		<?=Shell::empty('No full seasons yet', 'A season appears here once its regular season is over.', [], 'calendar')?>
	<?php else: ?>
		<?=rs_toolbar([[10, 'Picked all 10 weeks'], [1, 'Everyone']], 10, 'Show')?>

		<section class="stats tiles tiles-best" data-tiles="best" aria-label="Best seasons"><?=implode('', $tiles['best'])?></section>
		<section class="stats tiles tiles-worst" data-tiles="worst" aria-label="Worst seasons" hidden><?=implode('', $tiles['worst'])?></section>

		<section class="card card-flush enter" style="--i: 8" aria-labelledby="seasons-title">
			<div class="card-head">
				<div>
					<h2 class="card-title" id="seasons-title">Seasons</h2>
					<p class="card-sub">Regular season, <?=(int) $max_points?> points possible</p>
				</div>
			</div>
			<div class="table-wrap">
				<table class="table table-compact table-sticky ranked" id="t-seasons" data-ranked data-limit="25">
					<thead>
						<tr>
							<th data-sort="string" data-col="0">Player</th>
							<th class="num" data-sort="int" data-sort-default="desc" data-col="1">Season</th>
							<th class="num" data-sort="int" data-sort-default="desc" data-col="2">Weeks</th>
							<th class="num" data-sort="int" data-sort-default="desc" data-col="3">Record</th>
							<th class="num" data-sort="float" data-sort-default="desc" data-col="4">Pick %</th>
							<th class="num" data-sort="int" data-sort-default="desc" data-sort-primary data-sort-tiebreak="3" data-col="5" aria-sort="descending">Points</th>
							<th class="num" data-sort="float" data-sort-default="desc" data-col="6">Pts %</th>
							<th class="num" data-sort="int" data-sort-default="desc" data-col="7">Won wks</th>
							<th data-sort="int" data-col="8">Finish</th>
							<th class="num" data-sort="float" data-sort-default="desc" data-col="9">Won</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $ps): ?>
							<?php $s = $seasons[$ps['season_id']]; ?>
							<tr class="<?=rs_row_class($ps['user_id'])?>" data-weeks="<?=(int) $ps['weeks']?>">
								<?=rs_player_cell($ps['user_id'], $ps['champion'] ? '<span class="champ-mark" title="Champion">' . Icons::svg('trophy') . '</span>' : '')?>
								<td class="num" data-sort-value="<?=(int) $ps['season_id']?>"><?=rs_season_link($ps['season_id'], true)?></td>
								<td class="num<?=$ps['weeks'] < $s['num_weeks'] ? ' tone-shame' : ''?>"><?=(int) $ps['weeks']?></td>
								<td class="num nowrap" data-sort-value="<?=(int) $ps['right']?>"><?=h(Fmt::record($ps['right'], $ps['wrong']))?></td>
								<td class="num" data-sort-value="<?=(float) $ps['pick_pct']?>"><?=h(rs_pct($ps['pick_pct']))?></td>
								<td class="num cell-big"><?=(int) $ps['points']?></td>
								<td class="num" data-sort-value="<?=(float) $ps['points_pct']?>"><?=h(rs_pct($ps['points_pct']))?></td>
								<td class="num"><?=$ps['weeks_won'] ? (int) $ps['weeks_won'] : ''?></td>
								<td class="nowrap" data-sort-value="<?=$ps['finish'] ? (int) $ps['finish'] : 999?>"><?=$ps['finish'] ? h(rs_place($ps['finish'], $ps['players'])) : ''?></td>
								<td class="num tone-money" data-sort-value="<?=(float) $ps['winnings']?>"><?=h(Fmt::money($ps['winnings'], true))?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="filter-empty" data-filter-empty hidden>No seasons at this filter.</p>
			<div class="card-foot table-more">
				<?=rs_show_all('t-seasons', sizeof($rows))?>
			</div>
			<div class="card-foot">
				Full seasons only: 10 regular-season weeks worth 55 points each, so 2010 (13-point weeks) and the 2024 College Football Playoffs (4 weeks) are left out,
				and the season in progress appears once its regular season is over. Won includes the playoffs. The trophy marks the season's champion.
			</div>
		</section>
	<?php endif; ?>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
