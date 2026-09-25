<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\Snippets\Money;

$page->setTitle('Leaderboards - All-Time Stats');
$page->options['stats_bar']['active'] = 'leaderboard';

$players = array_filter($stats['players'], function ($p) {
	return $p['weeks'] > 0;
});
// Default order: net winnings
uasort($players, function ($a, $b) {
	if ($a['net'] != $b['net']) return $b['net'] < $a['net'] ? -1 : 1;
	return $b['points'] - $a['points'];
});
$qualified = array_filter($players, function ($p) {
	return $p['weeks'] >= STATS_MIN_WEEKS;
});

/**
 * Category leader tiles, best and worst.
 */
$tiles = ['best' => [], 'worst' => []];
$record_sub = function (array $rows) {
	return $rows ? '<span class="text-muted">' . stats_record($rows[0]['right'], $rows[0]['wrong']) . '</span>' : '';
};
foreach ([true, false] as $highest) {
	$side = $highest ? 'best' : 'worst';
	$e = stats_extreme($qualified, function ($p) { return $p['pick_pct'] ?: null; }, $highest);
	$tiles[$side][] = stats_tile(($highest ? 'Best' : 'Worst') . ' pick %', $e['value'] !== null ? stats_pct($e['value']) : '',
		stats_holders($e['rows'], 2) . ' ' . $record_sub($e['rows']), $highest ? 'tile-fame' : 'tile-shame');
	$e = stats_extreme($qualified, function ($p) { return $p['points_pct'] ?: null; }, $highest);
	$tiles[$side][] = stats_tile(($highest ? 'Best' : 'Worst') . ' points %', $e['value'] !== null ? stats_pct($e['value']) : '',
		stats_holders($e['rows'], 2), $highest ? 'tile-fame' : 'tile-shame');
	$e = stats_extreme($qualified, function ($p) { return $p['points_per_week'] ?: null; }, $highest);
	$tiles[$side][] = stats_tile(($highest ? 'Most' : 'Fewest') . ' points per week', $e['value'] !== null ? $e['value'] : '',
		stats_holders($e['rows'], 2), $highest ? 'tile-fame' : 'tile-shame');
	$e = stats_extreme($players, function ($p) { return $p['net']; }, $highest);
	$tiles[$side][] = stats_tile($highest ? 'Biggest net winner' : 'Biggest net loser', $e['value'] !== null ? Money::b($e['value'], false, true) : '',
		stats_holders($e['rows'], 2), $highest ? 'tile-money' : 'tile-shame');
	if ($highest) {
		$e = stats_extreme($players, function ($p) { return $p['weeks_won'] ?: null; });
		$tiles[$side][] = stats_tile('Most weeks won', $e['value'], stats_holders($e['rows'], 2), 'tile-fame');
		$e = stats_extreme($players, function ($p) { return $p['titles'] ?: null; });
		$tiles[$side][] = stats_tile('Most titles', $e['value'], stats_holders($e['rows'], 2), 'tile-fame');
	}
	else {
		$e = stats_extreme($players, function ($p) { return $p['weeks_last'] ?: null; });
		$tiles[$side][] = stats_tile('Most last-place weeks', $e['value'], stats_holders($e['rows'], 2), 'tile-shame');
		$e = stats_extreme($players, function ($p) { return $p['zero'] + $p['dishonor'] ?: null; });
		$tiles[$side][] = stats_tile('Most single-digit weeks', $e['value'], stats_holders($e['rows'], 2), 'tile-shame');
	}
}

/**
 * @param array $s  a split ('ou', 'spread', ...) of a player row
 * @return string record cell
 */
$split_record = function (array $s) {
	return '<td data-sort-value="' . $s['right'] . '">' . stats_record($s['right'], $s['wrong']) . '</td>'
		. '<td data-sort-value="' . $s['pick_pct'] . '">' . stats_pct($s['pick_pct']) . '</td>'
		. '<td data-sort-value="' . $s['points_pct'] . '">' . stats_pct($s['points_pct']) . '</td>';
};

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
					Played at least
					<select class="form-select form-select-sm w-auto" data-min-filter="weeks">
						<option value="1">1 week</option>
						<option value="12">12 weeks</option>
						<option value="24" <?=STATS_MIN_WEEKS == 24 ? 'selected' : ''?>>24 weeks</option>
						<option value="48">48 weeks</option>
						<option value="96">96 weeks</option>
					</select>
				</label>
			</div>
			<div class="small text-muted">Click any column to sort. Your row is highlighted.</div>
		</div>
	</div>

	<div class="stat-tiles mb-3 tiles-best"><?=implode('', $tiles['best'])?></div>
	<div class="stat-tiles mb-3 tiles-worst hidden"><?=implode('', $tiles['worst'])?></div>

	<div class="card mb-3">
		<h4 class="card-header">Money &amp; Points</h4>
		<div class="table-responsive">
			<table class="table table-sm table-sortable table-ranked mb-0">
				<thead>
					<tr class="text-center">
						<th>#</th>
						<th data-sort="string" class="text-start">Player</th>
						<th data-sort="int" data-sort-default="desc">Seasons</th>
						<th data-sort="int" data-sort-default="desc">Weeks</th>
						<th data-sort="int" data-sort-default="desc">Record</th>
						<th data-sort="float" data-sort-default="desc">Pick %</th>
						<th data-sort="int" data-sort-default="desc" id="th-money-points">Points</th>
						<th data-sort="float" data-sort-default="desc">Pts %</th>
						<th data-sort="float" data-sort-default="desc">Pts / Wk</th>
						<th data-sort="float" data-sort-default="desc">Won</th>
						<th data-sort="float" data-sort-default="desc" data-lower-is-better>Paid In</th>
						<th data-sort="float" data-sort-default="desc" data-sort-onload="yes" data-sort-primary data-sort-multicolumn="th-money-points">Net</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($players as $p): ?>
						<tr class="text-center <?=stats_me_class($p['user_id'])?>" data-weeks="<?=$p['weeks']?>">
							<td class="rank-cell"></td>
							<td class="text-start fw-bold"><?=stats_name($p['user_id'])?></td>
							<td><?=$p['seasons']?></td>
							<td><?=$p['weeks']?></td>
							<td data-sort-value="<?=$p['right']?>"><?=stats_record($p['right'], $p['wrong'])?></td>
							<td data-sort-value="<?=$p['pick_pct']?>"><?=stats_pct($p['pick_pct'])?></td>
							<td><?=number_format($p['points'])?></td>
							<td data-sort-value="<?=$p['points_pct']?>"><?=stats_pct($p['points_pct'])?></td>
							<td><?=number_format($p['points_per_week'], 1)?></td>
							<td data-sort-value="<?=$p['winnings']?>" class="text-money"><?=Money::b($p['winnings'], true)?></td>
							<td data-sort-value="<?=$p['fees']?>" class="text-muted"><?=Money::b($p['fees'], true)?></td>
							<td data-sort-value="<?=$p['net']?>" class="fw-bold <?=$p['net'] > 0 ? 'text-money' : ($p['net'] < 0 ? 'text-shame' : '')?>"><?=Money::b($p['net'], false, true)?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="card-footer text-muted small">
			Paid In is the entry fee of every season the player joined; the season in progress counts
			$<?=number_format((120 - AllTimeStats::FINALS_ALLOCATION) / 10)?> per completed regular week and $<?=AllTimeStats::FINALS_ALLOCATION?> once the Finals are done.
			Pts % is points won of points risked. Guaranteed Semifinals picks are not counted as picks.
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Over/Under vs Spread &middot; NFL vs NCAA</h4>
		<div class="table-responsive">
			<table class="table table-sm table-sortable table-ranked mb-0">
				<thead>
					<tr class="text-center">
						<th rowspan="2">#</th>
						<th rowspan="2" data-sort="string" class="text-start">Player</th>
						<th rowspan="2" data-sort="int" data-sort-default="desc">Weeks</th>
						<th colspan="3" class="text-ou">Over/Under</th>
						<th colspan="3" class="text-spread">Spread</th>
						<th colspan="3" class="text-nfl">NFL</th>
						<th colspan="3" class="text-ncaa">NCAA</th>
					</tr>
					<tr class="text-center">
						<?php foreach (['ou', 'spread', 'nfl', 'ncaa'] as $suffix): ?>
							<th class="text-<?=$suffix?>" data-sort="int" data-sort-default="desc">Record</th>
							<th class="text-<?=$suffix?>" data-sort="float" data-sort-default="desc" <?=$suffix == 'ou' ? 'data-sort-onload="yes" data-sort-primary' : ''?>>Pick %</th>
							<th class="text-<?=$suffix?>" data-sort="float" data-sort-default="desc">Pts %</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($players as $p): ?>
						<tr class="text-center <?=stats_me_class($p['user_id'])?>" data-weeks="<?=$p['weeks']?>">
							<td class="rank-cell"></td>
							<td class="text-start fw-bold"><?=stats_name($p['user_id'])?></td>
							<td><?=$p['weeks']?></td>
							<?=$split_record($p['ou'])?>
							<?=$split_record($p['spread'])?>
							<?=$split_record($p['nfl'])?>
							<?=$split_record($p['ncaa'])?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Weeks &amp; Records</h4>
		<div class="table-responsive">
			<table class="table table-sm table-sortable table-ranked mb-0">
				<thead>
					<tr class="text-center">
						<th>#</th>
						<th data-sort="string" class="text-start">Player</th>
						<th data-sort="int" data-sort-default="desc" id="th-weeks-played">Weeks</th>
						<th data-sort="int" data-sort-default="desc" data-sort-onload="yes" data-sort-primary data-sort-multicolumn="th-weeks-played">Weeks Won</th>
						<th data-sort="int" data-sort-default="desc" data-lower-is-better>Last Place</th>
						<th data-sort="int" data-sort-default="desc">Titles</th>
						<th data-sort="int" data-sort-default="desc">Top-3 Seasons</th>
						<th data-sort="int" data-sort-default="desc">Perfect</th>
						<th data-sort="int" data-sort-default="desc"><?=AllTimeStats::HONOR_MIN?>+</th>
						<th data-sort="int" data-sort-default="desc" data-lower-is-better>Zeros</th>
						<th data-sort="int" data-sort-default="desc" data-lower-is-better>Single Digits</th>
						<th data-sort="int" data-sort-default="desc">Best Week</th>
						<th data-sort="int" data-lower-is-better>Worst Week</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($players as $p): ?>
						<tr class="text-center <?=stats_me_class($p['user_id'])?>" data-weeks="<?=$p['weeks']?>">
							<td class="rank-cell"></td>
							<td class="text-start fw-bold"><?=stats_name($p['user_id'])?></td>
							<td><?=$p['weeks']?></td>
							<td class="fw-bold"><?=$p['weeks_won'] ?: ''?></td>
							<td data-sort-value="<?=$p['weeks_last']?>" class="text-shame"><?=$p['weeks_last'] ?: ''?></td>
							<td data-sort-value="<?=$p['titles']?>" class="text-fame"><?=$p['titles'] ? str_repeat('<i class="fas fa-trophy"></i>', $p['titles']) : ''?></td>
							<td data-sort-value="<?=$p['podiums']?>"><?=$p['podiums'] ?: ''?></td>
							<td data-sort-value="<?=$p['perfect']?>" class="text-fame fw-bold"><?=$p['perfect'] ?: ''?></td>
							<td data-sort-value="<?=$p['honor'] + $p['perfect']?>"><?=$p['honor'] + $p['perfect'] ?: ''?></td>
							<td data-sort-value="<?=$p['zero']?>" class="text-shame fw-bold"><?=$p['zero'] ?: ''?></td>
							<td data-sort-value="<?=$p['zero'] + $p['dishonor']?>"><?=$p['zero'] + $p['dishonor'] ?: ''?></td>
							<td data-sort-value="<?=$p['best_week'] ? $p['best_week']['points'] : -1?>">
								<?php if ($p['best_week']): ?>
									<?=$p['best_week']['points']?>
									<span class="text-muted small d-block"><?=stats_week_label($p['best_week']['week_id'], false)?></span>
								<?php endif; ?>
							</td>
							<td data-sort-value="<?=$p['worst_week'] ? $p['worst_week']['points'] : 999?>">
								<?php if ($p['worst_week']): ?>
									<?=$p['worst_week']['points']?>
									<span class="text-muted small d-block"><?=stats_week_label($p['worst_week']['week_id'], false)?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="card-footer text-muted small">
			Weeks Won and Last Place count regular-season weeks, ties included. Titles are Finals wins (the biggest payout of the Finals week).
			Top-3 Seasons counts regular-season finishes of 1st to 3rd in full seasons.
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
