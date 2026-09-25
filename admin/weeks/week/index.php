<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Week;
use Pick55\Models\WeekFormat;
use Pick55\Models\WeekFormatPayout;
use Pick55\Snippets\WeekFormatPayouts;

Auth::guardAdmin();

$week = Week::find(get('id'));
if (!$week) {
	$week = Week::getActive();
	if (get('next')) {
		$week = Week::getNext();
	}
}

$page = new Page;
$page->setTitle('Week #' . $week->id . ' - Weeks - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Week #' . $week->id;
$page->options['admin_bar']['sub_bar']['type'] = 'week';
$page->options['admin_bar']['sub_bar']['obj'] = $week;

$num_players = $week->season->getNumPlayers();

if (is_post()) {
	try {
		if (post('action') == 'save') {
			$new_week_num = intval(post('week_num', 0));
			if ($new_week_num < 1 || $new_week_num > 12) {
				throw new Exception("Invalid week num.");
			}
			$existing_week = Week::where('football_season_id', '=', $week->football_season_id)
				->where('week_num', '=', $new_week_num)
				->where('id', '!=', $week->id)
				->first();
			if ($existing_week) {
				throw new Exception("Week num #" . $new_week_num . " already exists for that season.");
			}
			$week->week_num = $new_week_num;

			$format_id = intval(post('football_week_format_id', 0));
			$format = null;
			if ($format_id) {
				$format = WeekFormat::find($format_id);
				if (!$format) {
					throw new Exception("Unknown format.");
				}
			}
			$format_changed = intval($week->football_week_format_id) != $format_id;
			$week->football_week_format_id = $format_id ? $format_id : null;
			$week->setRelation('format', $format);

			$ts = strtotime(post('games_finalized_at_date') . ' ' . post('games_finalized_at_time'));
			if (!$ts) {
				$week->picks_due_date = null;
			}
			else {
				$week->picks_due_date = date("Y-m-d H:i:s", $ts);
			}
			$week->save();
			Alert::success("Saved changes.");

			// Keep the football_pools rows in step with the new format, but
			// only when pools were already set up for this week.
			if ($format_changed) {
				$num_before = $week->pools()->count();
				if ($num_before) {
					$week->setNumPools();
					$num_after = $week->pools()->count();
					if ($num_after != $num_before) {
						Alert::info("Pools changed from " . $num_before . " to " . $num_after . " to match the format.");
					}
				}
			}
		}
		elseif (post('action') == 'set-guaranteed-points') {
			$week->setGuaranteedPoints();
			Alert::success("Guaranteed Points Set.");
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir();
}

$format = $week->getFormat();
$format_id = $format ? (int) $format->id : 0;

// Formats for the select: the season's player count first, then the rest
// grouped by their own player count.
$format_groups = [];
foreach (WeekFormat::getListForSelect($num_players) as $f) {
	if ((int) $f->num_players == (int) $num_players) {
		$label = 'For ' . $num_players . ' players';
	}
	else {
		$label = 'Other: ' . $f->num_players . ' players';
	}
	$format_groups[$label][] = $f;
}

$num_pools_created = $week->pools()->count();

ob_start();
?>
<script>
$(document).ready(function() {
	$('#football_week_format_id').change(function() {
		var id = $(this).val();
		$('.format-details').addClass('d-none');
		$('.format-details[data-format-id="' + id + '"]').removeClass('d-none');
		$('#format-none').toggleClass('d-none', id !== '');
	});
});
</script>
<?php
$page->setScripts(ob_get_clean());

ob_start();
?>
<div class="container py-4">
	<form action="" method="post">
		<input type="hidden" name="action" value="save">
		<div class="card">
			<h4 class="card-header">Week #<?=$week->id?></h4>
			<div class="card-body">

				<?php if (!$week->canPick() && !$week->canSeeResults()): ?>
					<div class="mb-3">
						<a class="btn btn-success" href="bulk-games.php?week_id=<?=$week->id?>">Games from Scrape</a>
					</div>
				<?php endif; ?>

				<dl>
					<dt>Season</dt>
					<dd><a href="../../seasons/season/index.php?id=<?=$week->season->id?>"><?=$week->season->name?></a> (<?=$num_players?> players)</dd>

					<dt><a href="pools.php?id=<?=$week->id?>">Pools</a></dt>
					<dd>
						<?=$week->getNumPools() ? $week->getNumPools() : 'N/A'?>
						<?php if ($num_pools_created != $week->getNumPools()): ?>
							<span class="text-danger">(<?=$num_pools_created?> created)</span>
						<?php endif; ?>
					</dd>
				</dl>

				<hr>

				<div class="row mb-3">
					<div class="col-lg-3 col-sm-4">
						<label for="week_num" class="form-label">Week Number</label>
						<select id="week_num" name="week_num" class="form-select">
							<?php foreach (range(1, 12) as $num): ?>
								<option <?=sel($num, $week->week_num)?> value="<?=$num?>"><?=$num?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="row mb-3">
					<div class="col-lg-6 col-mg-8 col-sm-10">
						<label>Games Finalized At</label>
						<div class="input-group">
							<input type="date" class="form-control" name="games_finalized_at_date" value="<?=$week->picks_due_date ? date("Y-m-d", strtotime($week->picks_due_date)) : ''?>">
							<input type="time" class="form-control" name="games_finalized_at_time" value="<?=$week->picks_due_date ? date("H:i:s", strtotime($week->picks_due_date)) : ''?>">
						</div>
					</div>
				</div>
				<div class="row mb-3">
					<div class="col-lg-6 col-md-8 col-sm-10">
						<label for="football_week_format_id" class="form-label">Format</label>
						<select id="football_week_format_id" name="football_week_format_id" class="form-select">
							<option value="">&mdash; no format &mdash;</option>
							<?php foreach ($format_groups as $label => $formats): ?>
								<optgroup label="<?=htmlspecialchars($label)?>">
									<?php foreach ($formats as $f): ?>
										<option <?=sel($f->id, $format_id)?> value="<?=$f->id?>"><?=htmlspecialchars($f->name)?> &mdash; <?=$f->num_players?> players, $<?=WeekFormatPayout::money($f->total_payout)?><?=$f->is_playoffs ? ' (playoffs)' : ''?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
						<div class="form-text">
							<a href="<?=$page->link('admin/formats/index.php')?>">All formats</a>
							&middot;
							<a href="<?=$page->link('admin/formats/format/index.php?num_players=' . $num_players)?>">New format</a>
						</div>
					</div>
				</div>

				<div id="format-none" class="text-muted fst-italic <?=$format_id ? 'd-none' : ''?>">No format assigned.</div>
				<?php foreach ($format_groups as $label => $formats): ?>
					<?php foreach ($formats as $f): ?>
						<div class="format-details <?=$f->id == $format_id ? '' : 'd-none'?>" data-format-id="<?=$f->id?>">
							<?php if (strlen($f->description_long)): ?>
								<p><?=$f->description_long?></p>
							<?php endif; ?>
							<?=WeekFormatPayouts::b($f)?>
							<?php if ($f->is_playoffs && $f->advance): ?>
								<p class="mb-0">Top <?=$f->advance?> advance.</p>
							<?php endif; ?>
							<p class="mb-0"><a href="<?=$page->link('admin/formats/format/index.php?id=' . $f->id)?>">Edit this format</a></p>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
			<div class="card-footer">
				<button type="submit" class="btn btn-primary btn-block">Save Changes</button>
			</div>
		</div>
	</form>
</div>
<?php if ($week->guarantees->count()) : ?>
	<div class="container py-4">
		<form action="" method="post">
			<input type="hidden" name="action" value="set-guaranteed-points">
			<div class="card">
				<h4 class="card-header">Guaranteed Points</h4>
				<div class="card-footer">
					<button type="submit" class="btn btn-primary btn-block">Set Guaranteed Points</button>
				</div>
			</div>
		</form>
	</div>
<?php endif; ?>
<?php
$page->setContent(ob_get_clean());
print $page->render();
