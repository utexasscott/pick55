<?php

require_once __DIR__ . '/_shared.php';

use Pick55\AllTimeStats;
use Pick55\R\Fmt;
use Pick55\R\Icons;
use Pick55\R\Shell;

$shell->setTitle('Wall of Fame');
$shell->setModule('stats', ['page' => 'fame']);

$t = $stats['totals'];
$fame = $stats['fame'];
$honor = $stats['honor'];
$real_weeks = array_sum($stats['distribution']);
$rarity = $t['perfect'] ? round($real_weeks / $t['perfect']) : 0;

// Honor roll in the classic on-load order: points, then the latest week first
$honor = rs_stable_sort($honor, function ($a, $b) {
	if ($a['points'] != $b['points']) return $b['points'] - $a['points'];
	return ($b['season_id'] * 100 + $b['week_num']) - ($a['season_id'] * 100 + $a['week_num']);
});

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
<div class="stats-page">
	<?=rs_head('fame', 'The perfect week: ' . AllTimeStats::PERFECT . ' points, every point value cashed.')?>

	<section class="card wall wall-fame enter" style="--i: 2" aria-labelledby="wall-title">
		<div class="wall-head">
			<span class="wall-icon"><?=Icons::svg('star')?></span>
			<div>
				<h2 class="wall-title" id="wall-title">Wall of Fame</h2>
				<?php if (sizeof($fame)): ?>
					<p class="wall-sub">
						It has happened <strong><?=(int) $t['perfect']?></strong> time<?=$t['perfect'] == 1 ? '' : 's'?>
						in <strong><?=h(Fmt::num($real_weeks))?></strong> player-weeks.
						<?php if ($rarity): ?>
							That is one perfect week in every <strong><?=h(Fmt::num($rarity))?></strong>.
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php if (sizeof($fame)): ?>
			<div class="plaques">
				<?php foreach ($fame as $i => $row): ?>
					<?=rs_plaque($row, 'fame', $i + 3)?>
				<?php endforeach; ?>
			</div>
		<?php else: ?>
			<?=Shell::empty('The wall is waiting', 'No one has scored a perfect week yet. Fifty-five points, every value cashed: it could be you.', [], 'star')?>
		<?php endif; ?>
		<p class="wall-foot">Real picks only: a Semifinals week in which a player held guaranteed points does not qualify. Weeks count once every game is final.</p>
	</section>

	<section class="card card-flush enter" style="--i: 4" aria-labelledby="honor-title">
		<div class="card-head">
			<div>
				<h2 class="card-title" id="honor-title">Honor Roll</h2>
				<p class="card-sub"><?=AllTimeStats::HONOR_MIN?> to <?=AllTimeStats::PERFECT - 1?> points, <?=sizeof($honor)?> time<?=sizeof($honor) == 1 ? '' : 's'?></p>
			</div>
		</div>
		<?php if (sizeof($honor)): ?>
			<div class="table-wrap">
				<table class="table table-compact table-sticky ranked" id="t-honor" data-ranked data-limit="25">
					<thead>
						<tr>
							<th data-sort="string">Player</th>
							<th class="num" data-sort="int" data-sort-default="desc" data-sort-primary data-sort-tiebreak="2" aria-sort="descending">Pts</th>
							<th data-sort="int" data-sort-default="desc">Week</th>
							<th class="num" data-sort="int" data-sort-default="desc">Record</th>
							<th data-sort="int">Place</th>
							<th class="num" data-sort="float" data-sort-default="desc">Won</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($honor as $row): ?>
							<tr class="<?=rs_row_class($row['user_id'])?>">
								<?=rs_player_cell($row['user_id'])?>
								<td class="num cell-big tone-fame"><?=(int) $row['points']?></td>
								<td class="nowrap" data-sort-value="<?=(int) ($row['season_id'] * 100 + $row['week_num'])?>"><?=rs_week_label($row['week_id'])?></td>
								<td class="num nowrap" data-sort-value="<?=(int) $row['right']?>"><?=h(Fmt::record($row['right'], $row['wrong']))?></td>
								<td class="nowrap" data-sort-value="<?=(int) $row['rank']?>"><?=h(rs_place($row['rank'], $row['field']))?></td>
								<td class="num" data-sort-value="<?=(float) $row['winnings']?>"><?=h(Fmt::money($row['winnings'], true))?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if (sizeof($honor) > 25): ?>
				<div class="card-foot table-more"><?=rs_show_all('t-honor', sizeof($honor))?></div>
			<?php endif; ?>
		<?php else: ?>
			<?=Shell::empty('No honor roll yet', 'Nobody has scored ' . AllTimeStats::HONOR_MIN . ' or more in a completed week.', [], 'medal')?>
		<?php endif; ?>
	</section>

	<?php if (sizeof($regulars)): ?>
		<section class="card card-flush enter" style="--i: 5" aria-labelledby="reg-title">
			<div class="card-head">
				<div>
					<h2 class="card-title" id="reg-title">Wall regulars</h2>
					<p class="card-sub">Most <?=AllTimeStats::HONOR_MIN?>+ point weeks</p>
				</div>
			</div>
			<ol class="list regulars">
				<?php foreach ($regulars as $i => $p): ?>
					<li class="<?=rs_row_class($p['user_id'])?>">
						<span class="rank<?=$i < 3 ? ' rank-' . ($i + 1) : ''?>"><?=$i + 1?></span>
						<span class="regular-name truncate"><?=rs_name_html($p['user_id'])?></span>
						<span class="regular-stats">
							<?php if ($p['perfect']): ?>
								<span class="pill pill-fame" title="Perfect weeks"><?=Icons::svg('star')?><?=(int) $p['perfect']?></span>
							<?php endif; ?>
							<span class="regular-count num" title="<?=AllTimeStats::HONOR_MIN?>+ point weeks"><b><?=(int) ($p['perfect'] + $p['honor'])?></b> <small>of <?=(int) $p['weeks']?></small></span>
							<span class="regular-every faint small">one every <?=(int) round($p['weeks'] / ($p['perfect'] + $p['honor']))?> weeks</span>
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
