<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\Snippets\Money;

$page->setTitle('Wall of Fame - All-Time Stats');
$page->options['stats_bar']['active'] = 'fame';

$t = $stats['totals'];
$fame = $stats['fame'];
$honor = $stats['honor'];
$real_weeks = array_sum($stats['distribution']);
$rarity = $t['perfect'] ? round($real_weeks / $t['perfect']) : 0;

// Most 50+ point weeks
$regulars = [];
foreach ($stats['players'] as $p) {
	if ($p['perfect'] + $p['honor'] > 0) {
		$regulars[] = $p;
	}
}
usort($regulars, function ($a, $b) {
	if ($a['perfect'] != $b['perfect']) return $b['perfect'] - $a['perfect'];
	if ($a['perfect'] + $a['honor'] != $b['perfect'] + $b['honor']) return ($b['perfect'] + $b['honor']) - ($a['perfect'] + $a['honor']);
	return $a['weeks'] - $b['weeks'];
});
$regulars = array_slice($regulars, 0, 10);

ob_start();
?>
<div class="container py-4">

	<div class="card mb-3 card-wall">
		<div class="card-header header-fame">
			<div class="wall-title"><i class="fas fa-trophy me-2"></i>Wall of Fame</div>
			<div class="wall-subtitle">The perfect week: <?=AllTimeStats::PERFECT?> points, every point value cashed.</div>
		</div>
		<div class="card-body">
			<?php if (sizeof($fame)): ?>
				<p class="text-center mb-4">
					It has happened <strong><?=$t['perfect']?></strong> time<?=$t['perfect'] == 1 ? '' : 's'?>
					in <strong><?=number_format($real_weeks)?></strong> player-weeks.
					<?php if ($rarity): ?>
						That is one perfect week in every <strong><?=number_format($rarity)?></strong>.
					<?php endif; ?>
				</p>
				<div class="plaques">
					<?php foreach ($fame as $row): ?>
						<?=stats_plaque($row, 'fame')?>
					<?php endforeach; ?>
				</div>
			<?php else: ?>
				<p class="text-center my-4 fs-5">No one has scored a perfect week yet. The wall is waiting.</p>
			<?php endif; ?>
		</div>
		<div class="card-footer text-muted small">
			Real picks only: a Semifinals week in which a player held guaranteed points does not qualify.
			Weeks count once every game is final.
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">
			Honor Roll
			<small class="text-muted fs-6"><?=AllTimeStats::HONOR_MIN?> to <?=AllTimeStats::PERFECT - 1?> points, <?=sizeof($honor)?> time<?=sizeof($honor) == 1 ? '' : 's'?></small>
		</h4>
		<div class="table-responsive">
			<table class="table table-sm table-sortable table-ranked mb-0" data-limit="25">
				<thead>
					<tr class="text-center">
						<th>#</th>
						<th data-sort="int" data-sort-default="desc" data-sort-onload="yes" data-sort-primary data-sort-multicolumn="th-honor-week">Pts</th>
						<th data-sort="string" class="text-start">Player</th>
						<th data-sort="int" data-sort-default="desc" class="text-start" id="th-honor-week">Week</th>
						<th data-sort="int" data-sort-default="desc">Record</th>
						<th data-sort="int">Place</th>
						<th data-sort="int" data-sort-default="desc">Won</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($honor as $row): ?>
						<tr class="text-center <?=stats_me_class($row['user_id'])?>">
							<td class="rank-cell"></td>
							<td class="fw-bold text-fame fs-5"><?=$row['points']?></td>
							<td class="text-start fw-bold"><?=stats_name($row['user_id'])?></td>
							<td class="text-start" data-sort-value="<?=$row['season_id'] * 100 + $row['week_num']?>"><?=stats_week_label($row['week_id'])?></td>
							<td data-sort-value="<?=$row['right']?>"><?=stats_record($row['right'], $row['wrong'])?></td>
							<td data-sort-value="<?=$row['rank']?>"><?=stats_place($row['rank'], $row['field'])?></td>
							<td data-sort-value="<?=$row['winnings']?>"><?=Money::b($row['winnings'], true)?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if (sizeof($honor) > 25): ?>
			<div class="card-footer text-center">
				<button type="button" class="btn btn-sm btn-outline-secondary" data-show-all>Show all <?=sizeof($honor)?></button>
			</div>
		<?php endif; ?>
	</div>

	<?php if (sizeof($regulars)): ?>
		<div class="card mb-3">
			<h4 class="card-header">Wall Regulars <small class="text-muted fs-6">most <?=AllTimeStats::HONOR_MIN?>+ point weeks</small></h4>
			<div class="table-responsive">
				<table class="table table-sm table-striped mb-0">
					<thead>
						<tr class="text-center">
							<th>#</th>
							<th class="text-start">Player</th>
							<th>Perfect</th>
							<th><?=AllTimeStats::HONOR_MIN?>+</th>
							<th>Weeks Played</th>
							<th>One Every</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($regulars as $i => $p): ?>
							<tr class="text-center <?=stats_me_class($p['user_id'])?>">
								<td><?=$i + 1?></td>
								<td class="text-start fw-bold"><?=stats_name($p['user_id'])?></td>
								<td class="text-fame fw-bold"><?=$p['perfect'] ?: ''?></td>
								<td><?=$p['perfect'] + $p['honor']?></td>
								<td><?=$p['weeks']?></td>
								<td><?=round($p['weeks'] / ($p['perfect'] + $p['honor']))?> weeks</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
