<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\Shell;

$shell->setTitle('Wall of Shame');
$shell->setModule('stats', ['page' => 'shame']);

$t = $stats['totals'];
$shame = $stats['shame'];
$dishonor = $stats['dishonor'];
$real_weeks = array_sum($stats['distribution']);
$rarity = $t['zero'] ? round($real_weeks / $t['zero']) : 0;

// Single digits in the classic on-load order: fewest points first, ties in stored order
$dishonor = rs_stable_sort($dishonor, function ($a, $b) {
	return $a['points'] - $b['points'];
});

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
<div class="stats-page">
	<?=rs_head('shame', 'The goose egg: zero points, not one pick worth anything came in.')?>

	<section class="card wall wall-shame enter" style="--i: 2" aria-labelledby="wall-title">
		<div class="wall-head">
			<span class="wall-icon"><?=Icons::svg('egg')?></span>
			<div>
				<h2 class="wall-title" id="wall-title">Wall of Shame</h2>
				<?php if (sizeof($shame)): ?>
					<p class="wall-sub">
						It has happened <strong><?=(int) $t['zero']?></strong> time<?=$t['zero'] == 1 ? '' : 's'?>
						in <strong><?=h(Fmt::num($real_weeks))?></strong> player-weeks.
						<?php if ($rarity): ?>
							That is one goose egg in every <strong><?=h(Fmt::num($rarity))?></strong>, which makes it
							<?php if ($t['perfect'] && $t['zero'] > $t['perfect']): ?>
								<?=h(round($t['zero'] / $t['perfect'], 1))?>&times; as common as a perfect week.
							<?php elseif ($t['perfect'] && $t['zero'] < $t['perfect']): ?>
								rarer than a perfect week.
							<?php else: ?>
								exactly as rare as a perfect week.
							<?php endif; ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php if (sizeof($shame)): ?>
			<div class="plaques">
				<?php foreach ($shame as $i => $row): ?>
					<?=rs_plaque($row, 'shame', $i + 3)?>
				<?php endforeach; ?>
			</div>
		<?php else: ?>
			<?=Shell::empty('A clean wall', 'No one has ever scored zero. Somebody will.', [], 'egg')?>
		<?php endif; ?>
		<p class="wall-foot">Only weeks in which the player actually made picks, and only real picks: a Semifinals week with guaranteed points does not count. Weeks count once every game is final.</p>
	</section>

	<section class="card card-flush enter" style="--i: 4" aria-labelledby="digits-title">
		<div class="card-head">
			<div>
				<h2 class="card-title" id="digits-title">Single digits</h2>
				<p class="card-sub">1 to <?=AllTimeStats::DISHONOR_MAX?> points, <?=sizeof($dishonor)?> time<?=sizeof($dishonor) == 1 ? '' : 's'?></p>
			</div>
		</div>
		<?php if (sizeof($dishonor)): ?>
			<div class="table-wrap">
				<table class="table table-compact table-sticky ranked" id="t-digits" data-ranked data-limit="25">
					<thead>
						<tr>
							<th data-sort="string">Player</th>
							<th class="num" data-sort="int" data-sort-default="asc" data-sort-primary data-lower-is-better aria-sort="ascending">Pts</th>
							<th data-sort="int" data-sort-default="desc">Week</th>
							<th class="num" data-sort="int">Record</th>
							<th data-sort="int" data-sort-default="desc">Place</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($dishonor as $row): ?>
							<tr class="<?=rs_row_class($row['user_id'])?>">
								<?=rs_player_cell($row['user_id'])?>
								<td class="num cell-big tone-shame"><?=(int) $row['points']?></td>
								<td class="nowrap" data-sort-value="<?=(int) ($row['season_id'] * 100 + $row['week_num'])?>"><?=rs_week_label($row['week_id'])?></td>
								<td class="num nowrap" data-sort-value="<?=(int) $row['right']?>"><?=h(Fmt::record($row['right'], $row['wrong']))?></td>
								<td class="nowrap" data-sort-value="<?=(int) $row['rank']?>"><?=h(rs_place($row['rank'], $row['field']))?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if (sizeof($dishonor) > 25): ?>
				<div class="card-foot table-more"><?=rs_show_all('t-digits', sizeof($dishonor))?></div>
			<?php endif; ?>
		<?php else: ?>
			<?=Shell::empty('No single digits', 'Nobody has scored 1 to ' . AllTimeStats::DISHONOR_MAX . ' in a completed week.', [], 'egg')?>
		<?php endif; ?>
	</section>

	<?php if (sizeof($regulars)): ?>
		<section class="card card-flush enter" style="--i: 5" aria-labelledby="reg-title">
			<div class="card-head">
				<div>
					<h2 class="card-title" id="reg-title">Wall regulars</h2>
					<p class="card-sub">Most single-digit weeks</p>
				</div>
			</div>
			<ol class="list regulars">
				<?php foreach ($regulars as $i => $p): ?>
					<li class="<?=rs_row_class($p['user_id'])?>">
						<span class="rank"><?=$i + 1?></span>
						<span class="regular-name truncate"><?=rs_name_html($p['user_id'])?></span>
						<span class="regular-stats">
							<?php if ($p['zero']): ?>
								<span class="pill pill-shame" title="Zero-point weeks"><?=Icons::svg('egg')?><?=(int) $p['zero']?></span>
							<?php endif; ?>
							<span class="regular-count num" title="Single-digit weeks"><b><?=(int) ($p['zero'] + $p['dishonor'])?></b> <small>of <?=(int) $p['weeks']?></small></span>
							<span class="regular-every faint small">one every <?=(int) round($p['weeks'] / ($p['zero'] + $p['dishonor']))?> weeks</span>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endif; ?>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
