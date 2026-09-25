<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\Snippets\Money;

$page->setTitle('Best Seasons - All-Time Stats');
$page->options['stats_bar']['active'] = 'seasons';

$seasons = $stats['seasons'];

// Full seasons (10 weeks of 55) whose regular season is over
$rows = array_filter($stats['player_seasons'], function ($ps) use ($seasons) {
	$s = $seasons[$ps['season_id']];
	return $s['full'] && $s['regular_done'];
});
usort($rows, function ($a, $b) {
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

$ps_holders = function (array $rows) {
	$out = [];
	foreach (array_slice($rows, 0, 2) as $ps) {
		$out[] = stats_name($ps['user_id']) . ' <span class="text-muted">' . stats_season_short($ps['season_id']) . '</span>';
	}
	if (sizeof($rows) > 2) {
		$out[] = '<span class="text-muted">+' . (sizeof($rows) - 2) . ' more</span>';
	}
	return implode('<br>', $out);
};

$tiles = ['best' => [], 'worst' => []];
foreach ([true, false] as $highest) {
	$side = $highest ? 'best' : 'worst';
	$e = stats_extreme($complete, function ($ps) { return $ps['points']; }, $highest);
	$tiles[$side][] = stats_tile(($highest ? 'Best' : 'Worst') . ' season', $e['value'] !== null ? $e['value'] . ' <small>pts</small>' : '',
		$ps_holders($e['rows']), $highest ? 'tile-fame' : 'tile-shame');
	$e = stats_extreme($complete, function ($ps) { return $ps['pick_pct'] ?: null; }, $highest);
	$tiles[$side][] = stats_tile(($highest ? 'Best' : 'Worst') . ' pick %', $e['value'] !== null ? stats_pct($e['value']) : '',
		$ps_holders($e['rows']), $highest ? 'tile-fame' : 'tile-shame');
	$e = stats_extreme($complete, function ($ps) { return $ps['points_pct'] ?: null; }, $highest);
	$tiles[$side][] = stats_tile(($highest ? 'Best' : 'Worst') . ' points %', $e['value'] !== null ? stats_pct($e['value']) : '',
		$ps_holders($e['rows']), $highest ? 'tile-fame' : 'tile-shame');
	if ($highest) {
		$e = stats_extreme($rows, function ($ps) { return $ps['weeks_won'] ?: null; });
		$tiles[$side][] = stats_tile('Most weeks won', $e['value'], $ps_holders($e['rows']), 'tile-fame');
		$e = stats_extreme($rows, function ($ps) { return $ps['winnings'] ?: null; });
		$tiles[$side][] = stats_tile('Biggest season', $e['value'] !== null ? Money::b($e['value']) : '', $ps_holders($e['rows']), 'tile-money');
	}
	else {
		$e = stats_extreme($complete, function ($ps) { return $ps['best_week'] ? $ps['best_week']['points'] : null; }, false);
		$tiles[$side][] = stats_tile('Lowest best week', $e['value'], $ps_holders($e['rows']) . '<br><span class="text-muted">never topped it all season</span>', 'tile-shame');
		$e = stats_extreme($complete, function ($ps) { return $ps['finish'] == $ps['players'] && $ps['players'] > 1 ? $ps['players'] : null; });
		$tiles[$side][] = stats_tile('Last in the biggest field', $e['value'] !== null ? 'Last of ' . $e['value'] : '', $ps_holders($e['rows']), 'tile-shame');
	}
}

ob_start();
?>
<div class="container py-4">

	<div class="card mb-3">
		<div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 stats-toolbar">
			<div class="d-flex align-items-center gap-2 flex-wrap">
				<div class="btn-group btn-group-sm" role="group" aria-label="Sort direction">
					<button type="button" class="btn btn-outline-dark active" data-sort-toggle="best"><i class="fas fa-arrow-up me-1"></i>Best first</button>
					<button type="button" class="btn btn-outline-dark" data-sort-toggle="worst"><i class="fas fa-arrow-down me-1"></i>Worst first</button>
				</div>
				<label class="d-flex align-items-center gap-1 small text-muted">
					Show
					<select class="form-select form-select-sm w-auto" data-min-filter="weeks">
						<option value="10" selected>players who picked all 10 weeks</option>
						<option value="1">everyone</option>
					</select>
				</label>
			</div>
			<div class="small text-muted">Click any column to sort. Your seasons are highlighted.</div>
		</div>
	</div>

	<div class="stat-tiles mb-3 tiles-best"><?=implode('', $tiles['best'])?></div>
	<div class="stat-tiles mb-3 tiles-worst hidden"><?=implode('', $tiles['worst'])?></div>

	<div class="card mb-3">
		<h4 class="card-header">
			Seasons
			<small class="text-muted fs-6">regular season, <?=$max_points?> points possible</small>
		</h4>
		<div class="table-responsive">
			<table class="table table-sm table-sortable table-ranked mb-0" data-limit="25">
				<thead>
					<tr class="text-center">
						<th>#</th>
						<th data-sort="string" class="text-start">Player</th>
						<th data-sort="int" data-sort-default="desc">Season</th>
						<th data-sort="int" data-sort-default="desc">Weeks</th>
						<th data-sort="int" data-sort-default="desc" id="th-season-record">Record</th>
						<th data-sort="float" data-sort-default="desc">Pick %</th>
						<th data-sort="int" data-sort-default="desc" data-sort-onload="yes" data-sort-primary data-sort-multicolumn="th-season-record">Points</th>
						<th data-sort="float" data-sort-default="desc">Pts %</th>
						<th data-sort="int" data-sort-default="desc">Weeks Won</th>
						<th data-sort="int">Finish</th>
						<th data-sort="float" data-sort-default="desc">Won</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($rows as $ps): ?>
						<?php $s = $seasons[$ps['season_id']]; ?>
						<tr class="text-center <?=stats_me_class($ps['user_id'])?>" data-weeks="<?=$ps['weeks']?>">
							<td class="rank-cell"></td>
							<td class="text-start fw-bold">
								<?=stats_name($ps['user_id'])?>
								<?php if ($ps['champion']): ?>
									<i class="fas fa-trophy text-fame ms-1" title="Champion"></i>
								<?php endif; ?>
							</td>
							<td data-sort-value="<?=$ps['season_id']?>">
								<?php if (in_array($ps['season_id'], $my_season_ids)): ?>
									<a href="<?=$page->link('season/standings.php?id=' . $ps['season_id'])?>"><?=stats_season_short($ps['season_id'])?></a>
								<?php else: ?>
									<?=stats_season_short($ps['season_id'])?>
								<?php endif; ?>
							</td>
							<td class="<?=$ps['weeks'] < $s['num_weeks'] ? 'text-shame' : ''?>"><?=$ps['weeks']?></td>
							<td data-sort-value="<?=$ps['right']?>"><?=stats_record($ps['right'], $ps['wrong'])?></td>
							<td data-sort-value="<?=$ps['pick_pct']?>"><?=stats_pct($ps['pick_pct'])?></td>
							<td class="fw-bold fs-6"><?=$ps['points']?></td>
							<td data-sort-value="<?=$ps['points_pct']?>"><?=stats_pct($ps['points_pct'])?></td>
							<td><?=$ps['weeks_won'] ?: ''?></td>
							<td data-sort-value="<?=$ps['finish'] ?: 999?>">
								<?php if ($ps['finish']): ?>
									<?=stats_place($ps['finish'], $ps['players'])?>
								<?php endif; ?>
							</td>
							<td data-sort-value="<?=$ps['winnings']?>" class="text-money"><?=Money::b($ps['winnings'], true)?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
			<div class="text-muted small">
				Full seasons only: 10 regular-season weeks worth 55 points each, so 2010 (13-point weeks) and the 2024 College Football Playoffs (4 weeks) are left out,
				and the season in progress appears once its regular season is over. Won includes the playoffs. The trophy marks the season's champion.
			</div>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-show-all>Show all <?=sizeof($rows)?></button>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
