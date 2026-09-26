<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\R\Fmt;
use Pick55\R\Icons;

$shell->setTitle('Leaderboards');
$shell->setModule('stats', ['page' => 'leaders', 'min' => RS_MIN_WEEKS]);

$players = array_filter($stats['players'], function ($p) {
	return $p['weeks'] > 0;
});
// Default order: net winnings, then points (the Money & Points table's on-load sort)
$players = rs_stable_sort($players, function ($a, $b) {
	if ($a['net'] != $b['net']) return $b['net'] < $a['net'] ? -1 : 1;
	return $b['points'] - $a['points'];
});
$qualified = array_filter($players, function ($p) {
	return $p['weeks'] >= RS_MIN_WEEKS;
});
$by_ou = rs_stable_sort($players, function ($a, $b) {
	if ($a['ou']['pick_pct'] == $b['ou']['pick_pct']) return 0;
	return $b['ou']['pick_pct'] < $a['ou']['pick_pct'] ? -1 : 1;
});
$by_won = rs_stable_sort($players, function ($a, $b) {
	if ($a['weeks_won'] != $b['weeks_won']) return $b['weeks_won'] - $a['weeks_won'];
	return $b['weeks'] - $a['weeks'];
});

/**
 * Category leader tiles, best and worst (the classic page's set).
 */
$tiles = ['best' => [], 'worst' => []];
$rec_of = function (array $rows) {
	return $rows ? ' <span class="faint nowrap">' . h(Fmt::record($rows[0]['right'], $rows[0]['wrong'])) . '</span>' : '';
};
foreach ([true, false] as $highest) {
	$side = $highest ? 'best' : 'worst';
	$tone = $highest ? 'fame' : 'shame';
	$i = 3;
	$e = rs_extreme($qualified, function ($p) { return $p['pick_pct'] ?: null; }, $highest);
	$tiles[$side][] = rs_tile(($highest ? 'Best' : 'Worst') . ' pick %', $e['value'] !== null ? h(rs_pct($e['value'])) : '',
		rs_holders($e['rows'], 2) . $rec_of($e['rows']), $tone, $i++);
	$e = rs_extreme($qualified, function ($p) { return $p['points_pct'] ?: null; }, $highest);
	$tiles[$side][] = rs_tile(($highest ? 'Best' : 'Worst') . ' points %', $e['value'] !== null ? h(rs_pct($e['value'])) : '',
		rs_holders($e['rows'], 2), $tone, $i++);
	$e = rs_extreme($qualified, function ($p) { return $p['points_per_week'] ?: null; }, $highest);
	$tiles[$side][] = rs_tile(($highest ? 'Most' : 'Fewest') . ' points per week', $e['value'] !== null ? h($e['value']) : '',
		rs_holders($e['rows'], 2), $tone, $i++);
	$e = rs_extreme($players, function ($p) { return $p['net']; }, $highest);
	$tiles[$side][] = rs_tile($highest ? 'Biggest net winner' : 'Biggest net loser', $e['value'] !== null ? h(Fmt::money($e['value'], false, true)) : '',
		rs_holders($e['rows'], 2), $highest ? 'money' : 'shame', $i++);
	if ($highest) {
		$e = rs_extreme($players, function ($p) { return $p['weeks_won'] ?: null; });
		$tiles[$side][] = rs_tile('Most weeks won', $e['value'] !== null ? (int) $e['value'] : '', rs_holders($e['rows'], 2), 'fame', $i++);
		$e = rs_extreme($players, function ($p) { return $p['titles'] ?: null; });
		$tiles[$side][] = rs_tile('Most titles', $e['value'] !== null ? (int) $e['value'] : '', rs_holders($e['rows'], 2), 'fame', $i++);
	}
	else {
		$e = rs_extreme($players, function ($p) { return $p['weeks_last'] ?: null; });
		$tiles[$side][] = rs_tile('Most last-place weeks', $e['value'] !== null ? (int) $e['value'] : '', rs_holders($e['rows'], 2), 'shame', $i++);
		$e = rs_extreme($players, function ($p) { return $p['zero'] + $p['dishonor'] ?: null; });
		$tiles[$side][] = rs_tile('Most single-digit weeks', $e['value'] !== null ? (int) $e['value'] : '', rs_holders($e['rows'], 2), 'shame', $i++);
	}
}

/**
 * Three cells for a split ('ou', 'spread', ...) of a player row.
 *
 * @param array $s
 * @param string $key
 * @return string
 */
$split_cells = function (array $s, $key) {
	return '<td class="num nowrap split-' . $key . ' split-first" data-sort-value="' . (int) $s['right'] . '">' . h(Fmt::record($s['right'], $s['wrong'])) . '</td>'
		. '<td class="num split-' . $key . '" data-sort-value="' . (float) $s['pick_pct'] . '">' . h(rs_pct($s['pick_pct'])) . '</td>'
		. '<td class="num split-' . $key . '" data-sort-value="' . (float) $s['points_pct'] . '">' . h(rs_pct($s['points_pct'])) . '</td>';
};
$splits = ['ou' => 'Over/Under', 'spread' => 'Spread', 'nfl' => 'NFL', 'ncaa' => 'NCAA'];

ob_start();
?>
<div class="stats-page">
	<?=rs_head('leaders', 'Every player who has ever picked, ranked any way you like.')?>

	<?=rs_toolbar([[1, '1'], [12, '12'], [24, '24'], [48, '48'], [96, '96']], RS_MIN_WEEKS, 'Min. weeks played')?>

	<section class="stats tiles tiles-best" data-tiles="best" aria-label="Category leaders"><?=implode('', $tiles['best'])?></section>
	<section class="stats tiles tiles-worst" data-tiles="worst" aria-label="Category trailers" hidden><?=implode('', $tiles['worst'])?></section>
	<p class="tiles-note faint small">Percentages and points per week count players with <?=RS_MIN_WEEKS?>+ weeks.</p>

	<section class="card card-flush enter" style="--i: 6" aria-labelledby="money-title">
		<div class="card-head">
			<h2 class="card-title" id="money-title"><?=Icons::svg('dollar-sign')?>Money &amp; points</h2>
		</div>
		<div class="table-wrap">
			<table class="table table-compact table-sticky ranked" id="t-money" data-ranked>
				<thead>
					<tr>
						<th data-sort="string" data-col="0">Player</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="1">Seasons</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="2">Weeks</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="3">Record</th>
						<th class="num" data-sort="float" data-sort-default="desc" data-col="4">Pick %</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="5">Points</th>
						<th class="num" data-sort="float" data-sort-default="desc" data-col="6">Pts %</th>
						<th class="num" data-sort="float" data-sort-default="desc" data-col="7">Pts/wk</th>
						<th class="num" data-sort="float" data-sort-default="desc" data-col="8">Won</th>
						<th class="num" data-sort="float" data-sort-default="desc" data-lower-is-better data-col="9">Paid in</th>
						<th class="num" data-sort="float" data-sort-default="desc" data-sort-primary data-sort-tiebreak="5" data-col="10" aria-sort="descending">Net</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($players as $p): ?>
						<tr class="<?=rs_row_class($p['user_id'])?>" data-weeks="<?=(int) $p['weeks']?>">
							<?=rs_player_cell($p['user_id'])?>
							<td class="num"><?=(int) $p['seasons']?></td>
							<td class="num"><?=(int) $p['weeks']?></td>
							<td class="num nowrap" data-sort-value="<?=(int) $p['right']?>"><?=h(Fmt::record($p['right'], $p['wrong']))?></td>
							<td class="num" data-sort-value="<?=(float) $p['pick_pct']?>"><?=h(rs_pct($p['pick_pct']))?></td>
							<td class="num" data-sort-value="<?=(int) $p['points']?>"><?=h(Fmt::num($p['points']))?></td>
							<td class="num" data-sort-value="<?=(float) $p['points_pct']?>"><?=h(rs_pct($p['points_pct']))?></td>
							<td class="num" data-sort-value="<?=(float) $p['points_per_week']?>"><?=h(Fmt::num($p['points_per_week'], 1))?></td>
							<td class="num tone-money" data-sort-value="<?=(float) $p['winnings']?>"><?=h(Fmt::money($p['winnings'], true))?></td>
							<td class="num faint" data-sort-value="<?=(float) $p['fees']?>"><?=h(Fmt::money($p['fees'], true))?></td>
							<td class="num cell-strong <?=$p['net'] > 0 ? 'tone-money' : ($p['net'] < 0 ? 'tone-shame' : '')?>" data-sort-value="<?=(float) $p['net']?>"><?=h(Fmt::money($p['net'], false, true))?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="filter-empty" data-filter-empty hidden>No one has played that many weeks.</p>
		<div class="card-foot">
			Paid in is the entry fee of every season the player joined; the season in progress counts
			$<?=h(number_format((120 - AllTimeStats::FINALS_ALLOCATION) / 10))?> per completed regular week and $<?=AllTimeStats::FINALS_ALLOCATION?> once the Finals are done.
			Pts % is points won of points risked. Guaranteed Semifinals picks are not counted as picks.
		</div>
	</section>

	<section class="card card-flush enter" style="--i: 7" aria-labelledby="split-title">
		<div class="card-head">
			<h2 class="card-title" id="split-title"><?=Icons::svg('target')?>Over/under vs spread &middot; NFL vs NCAA</h2>
		</div>
		<div class="table-wrap">
			<table class="table table-compact table-sticky ranked splits-table" id="t-splits" data-ranked>
				<thead>
					<tr>
						<th rowspan="2" data-sort="string" data-col="0">Player</th>
						<th rowspan="2" class="num" data-sort="int" data-sort-default="desc" data-col="1">Weeks</th>
						<?php foreach ($splits as $key => $label): ?>
							<th colspan="3" class="center split-head split-<?=h($key)?>"><span class="tag tag-<?=h($key)?>"><?=h($label)?></span></th>
						<?php endforeach; ?>
					</tr>
					<tr>
						<?php $col = 2; ?>
						<?php foreach ($splits as $key => $label): ?>
							<th class="num split-first" data-sort="int" data-sort-default="desc" data-col="<?=$col++?>" title="<?=h($label)?> record">Record</th>
							<th class="num" data-sort="float" data-sort-default="desc" data-col="<?=$col++?>" title="<?=h($label)?> pick %" <?=$key === 'ou' ? 'data-sort-primary aria-sort="descending"' : ''?>>Pick %</th>
							<th class="num" data-sort="float" data-sort-default="desc" data-col="<?=$col++?>" title="<?=h($label)?> points %">Pts %</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($by_ou as $p): ?>
						<tr class="<?=rs_row_class($p['user_id'])?>" data-weeks="<?=(int) $p['weeks']?>">
							<?=rs_player_cell($p['user_id'])?>
							<td class="num"><?=(int) $p['weeks']?></td>
							<?php foreach ($splits as $key => $label): ?>
								<?=$split_cells($p[$key], $key)?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="filter-empty" data-filter-empty hidden>No one has played that many weeks.</p>
	</section>

	<section class="card card-flush enter" style="--i: 8" aria-labelledby="weeks-title">
		<div class="card-head">
			<h2 class="card-title" id="weeks-title"><?=Icons::svg('trophy')?>Weeks &amp; records</h2>
		</div>
		<div class="table-wrap">
			<table class="table table-compact table-sticky ranked" id="t-weeks" data-ranked>
				<thead>
					<tr>
						<th data-sort="string" data-col="0">Player</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="1">Weeks</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-sort-primary data-sort-tiebreak="1" data-col="2" aria-sort="descending">Won</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-lower-is-better data-col="3">Last</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="4">Titles</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="5" title="Top-3 regular-season finishes in full seasons">Top 3</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="6">Perfect</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-col="7"><?=AllTimeStats::HONOR_MIN?>+</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-lower-is-better data-col="8">Zeros</th>
						<th class="num" data-sort="int" data-sort-default="desc" data-lower-is-better data-col="9" title="Single-digit weeks">1&ndash;<?=AllTimeStats::DISHONOR_MAX?></th>
						<th data-sort="int" data-sort-default="desc" data-col="10">Best week</th>
						<th data-sort="int" data-lower-is-better data-col="11">Worst week</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($by_won as $p): ?>
						<tr class="<?=rs_row_class($p['user_id'])?>" data-weeks="<?=(int) $p['weeks']?>">
							<?=rs_player_cell($p['user_id'])?>
							<td class="num"><?=(int) $p['weeks']?></td>
							<td class="num cell-strong" data-sort-value="<?=(int) $p['weeks_won']?>"><?=$p['weeks_won'] ? (int) $p['weeks_won'] : ''?></td>
							<td class="num tone-shame" data-sort-value="<?=(int) $p['weeks_last']?>"><?=$p['weeks_last'] ? (int) $p['weeks_last'] : ''?></td>
							<td class="num tone-fame nowrap" data-sort-value="<?=(int) $p['titles']?>"><?php if ($p['titles']): ?><span class="trophies" title="<?=(int) $p['titles']?> title<?=$p['titles'] == 1 ? '' : 's'?>"><?=str_repeat(Icons::svg('trophy'), (int) $p['titles'])?></span><?php endif; ?></td>
							<td class="num" data-sort-value="<?=(int) $p['podiums']?>"><?=$p['podiums'] ? (int) $p['podiums'] : ''?></td>
							<td class="num tone-fame cell-strong" data-sort-value="<?=(int) $p['perfect']?>"><?=$p['perfect'] ? (int) $p['perfect'] : ''?></td>
							<td class="num" data-sort-value="<?=(int) ($p['honor'] + $p['perfect'])?>"><?=$p['honor'] + $p['perfect'] ? (int) ($p['honor'] + $p['perfect']) : ''?></td>
							<td class="num tone-shame cell-strong" data-sort-value="<?=(int) $p['zero']?>"><?=$p['zero'] ? (int) $p['zero'] : ''?></td>
							<td class="num" data-sort-value="<?=(int) ($p['zero'] + $p['dishonor'])?>"><?=$p['zero'] + $p['dishonor'] ? (int) ($p['zero'] + $p['dishonor']) : ''?></td>
							<td class="nowrap" data-sort-value="<?=$p['best_week'] ? (int) $p['best_week']['points'] : -1?>">
								<?php if ($p['best_week']): ?>
									<b class="num"><?=(int) $p['best_week']['points']?></b>
									<span class="faint small"><?=rs_week_label($p['best_week']['week_id'], false)?></span>
								<?php endif; ?>
							</td>
							<td class="nowrap" data-sort-value="<?=$p['worst_week'] ? (int) $p['worst_week']['points'] : 999?>">
								<?php if ($p['worst_week']): ?>
									<b class="num"><?=(int) $p['worst_week']['points']?></b>
									<span class="faint small"><?=rs_week_label($p['worst_week']['week_id'], false)?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="filter-empty" data-filter-empty hidden>No one has played that many weeks.</p>
		<div class="card-foot">
			Won and Last count regular-season weeks, ties included. Titles are Finals wins (the biggest payout of the Finals week).
			Top 3 counts regular-season finishes of 1st to 3rd in full seasons.
		</div>
	</section>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
