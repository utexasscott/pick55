<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\Snippets\Money;

$page->setTitle('Wall of Shame - All-Time Stats');
$page->options['stats_bar']['active'] = 'shame';

$t = $stats['totals'];
$shame = $stats['shame'];
$dishonor = $stats['dishonor'];
$real_weeks = array_sum($stats['distribution']);
$rarity = $t['zero'] ? round($real_weeks / $t['zero']) : 0;

// Most single-digit weeks
$regulars = [];
foreach ($stats['players'] as $p) {
	if ($p['zero'] + $p['dishonor'] > 0) {
		$regulars[] = $p;
	}
}
usort($regulars, function ($a, $b) {
	if ($a['zero'] != $b['zero']) return $b['zero'] - $a['zero'];
	if ($a['zero'] + $a['dishonor'] != $b['zero'] + $b['dishonor']) return ($b['zero'] + $b['dishonor']) - ($a['zero'] + $a['dishonor']);
	return $a['weeks'] - $b['weeks'];
});
$regulars = array_slice($regulars, 0, 10);

ob_start();
?>
<div class="container py-4">

	<div class="card mb-3 card-wall">
		<div class="card-header header-shame">
			<div class="wall-title"><i class="fas fa-egg me-2"></i>Wall of Shame</div>
			<div class="wall-subtitle">The goose egg: zero points, not one pick worth anything came in.</div>
		</div>
		<div class="card-body">
			<?php if (sizeof($shame)): ?>
				<p class="text-center mb-4">
					It has happened <strong><?=$t['zero']?></strong> time<?=$t['zero'] == 1 ? '' : 's'?>
					in <strong><?=number_format($real_weeks)?></strong> player-weeks.
					<?php if ($rarity): ?>
						That is one goose egg in every <strong><?=number_format($rarity)?></strong>, which makes it
						<?php if ($t['perfect'] && $t['zero'] > $t['perfect']): ?>
							<?=round($t['zero'] / $t['perfect'], 1)?>&times; as common as a perfect week.
						<?php elseif ($t['perfect'] && $t['zero'] < $t['perfect']): ?>
							rarer than a perfect week.
						<?php else: ?>
							exactly as rare as a perfect week.
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<div class="plaques">
					<?php foreach ($shame as $row): ?>
						<?=stats_plaque($row, 'shame')?>
					<?php endforeach; ?>
				</div>
			<?php else: ?>
				<p class="text-center my-4 fs-5">No one has ever scored zero. Somebody will.</p>
			<?php endif; ?>
		</div>
		<div class="card-footer text-muted small">
			Only weeks in which the player actually made picks, and only real picks: a Semifinals week with guaranteed points does not count.
			Weeks count once every game is final.
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">
			Single Digits
			<small class="text-muted fs-6">1 to <?=AllTimeStats::DISHONOR_MAX?> points, <?=sizeof($dishonor)?> time<?=sizeof($dishonor) == 1 ? '' : 's'?></small>
		</h4>
		<div class="table-responsive">
			<table class="table table-sm table-sortable table-ranked mb-0" data-limit="25">
				<thead>
					<tr class="text-center">
						<th>#</th>
						<th data-sort="int" data-sort-default="asc" data-sort-onload="yes" data-sort-primary data-lower-is-better>Pts</th>
						<th data-sort="string" class="text-start">Player</th>
						<th data-sort="int" data-sort-default="desc" class="text-start">Week</th>
						<th data-sort="int">Record</th>
						<th data-sort="int" data-sort-default="desc">Place</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($dishonor as $row): ?>
						<tr class="text-center <?=stats_me_class($row['user_id'])?>">
							<td class="rank-cell"></td>
							<td class="fw-bold text-shame fs-5"><?=$row['points']?></td>
							<td class="text-start fw-bold"><?=stats_name($row['user_id'])?></td>
							<td class="text-start" data-sort-value="<?=$row['season_id'] * 100 + $row['week_num']?>"><?=stats_week_label($row['week_id'])?></td>
							<td data-sort-value="<?=$row['right']?>"><?=stats_record($row['right'], $row['wrong'])?></td>
							<td data-sort-value="<?=$row['rank']?>"><?=stats_place($row['rank'], $row['field'])?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if (sizeof($dishonor) > 25): ?>
			<div class="card-footer text-center">
				<button type="button" class="btn btn-sm btn-outline-secondary" data-show-all>Show all <?=sizeof($dishonor)?></button>
			</div>
		<?php endif; ?>
	</div>

	<?php if (sizeof($regulars)): ?>
		<div class="card mb-3">
			<h4 class="card-header">Wall Regulars <small class="text-muted fs-6">most single-digit weeks</small></h4>
			<div class="table-responsive">
				<table class="table table-sm table-striped mb-0">
					<thead>
						<tr class="text-center">
							<th>#</th>
							<th class="text-start">Player</th>
							<th>Zeros</th>
							<th>Single Digits</th>
							<th>Weeks Played</th>
							<th>One Every</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($regulars as $i => $p): ?>
							<tr class="text-center <?=stats_me_class($p['user_id'])?>">
								<td><?=$i + 1?></td>
								<td class="text-start fw-bold"><?=stats_name($p['user_id'])?></td>
								<td class="text-shame fw-bold"><?=$p['zero'] ?: ''?></td>
								<td><?=$p['zero'] + $p['dishonor']?></td>
								<td><?=$p['weeks']?></td>
								<td><?=round($p['weeks'] / ($p['zero'] + $p['dishonor']))?> weeks</td>
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
