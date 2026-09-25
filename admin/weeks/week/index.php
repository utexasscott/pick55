<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Week;
use Pick55\Models\User;
use Pick55\Models\UsersSeasonsLink;

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
			$week->weekly_bonus = post('weekly_bonus') - post('pool_winner');
			$week->pool_winner = post('pool_winner');
			$week->num_winners = post('num_winners');
			if (is_numeric(trim(post('min_score_threshold')))) {
				$week->min_score_threshold = post('min_score_threshold');
			}
			else {
				$week->min_score_threshold = 0;
			}
			$week->description = trim(post('description'));
			$week->description_long = trim(post('description_long'));
			$ts = strtotime(post('games_finalized_at_date') . ' ' . post('games_finalized_at_time'));
			if (!$ts) {
				$week->picks_due_date = null;
			}
			else {
				$week->picks_due_date = date("Y-m-d H:i:s", $ts);
			}
			$week->save();
			Alert::success("Saved changes.");
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
					<dd><a href="../../seasons/season/index.php?id=<?=$week->season->id?>"><?=$week->season->name?></a></dd>

					<dt><a href="pools.php?id=<?=$week->id?>">Pools</a></dt>
					<dd><?=$week->num_pools ? $week->num_pools : 'N/A'?></dd>
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
				<div class="row mb-3">
					<div class="col-lg-3 col-sm-4">
						<label for="num_winners" class="form-label">Winners Per Pool</label>
						<input type="text" class="form-control" name="num_winners" id="num_winners" value="<?=$week->num_winners?>">
					</div>
					<div class="col-lg-3 col-sm-4">
						<label for="min_score_threshold" class="form-label">Score Threshold</label>
						<input type="text" class="form-control" name="min_score_threshold" id="min_score_threshold" value="<?=$week->min_score_threshold?>">
					</div>
				</div>
					<div class="col-lg-3 col-sm-4">
						<label for="pool_winner" class="form-label">Pool Winner</label>
						<div class="input-group">
							<span class="input-group-text">$</span>
							<input type="text" class="form-control" name="pool_winner" id="pool_winner" value="<?=$week->pool_winner?>">
						</div>
					</div>
					<div class="col-lg-3 col-sm-4">
						<label for="weekly_bonus" class="form-label">Overall Winner</label>
						<div class="input-group">
							<span class="input-group-text">$</span>
							<input type="text" class="form-control" name="weekly_bonus" id="weekly_bonus" value="<?=$week->weekly_bonus+$week->pool_winner?>">
						</div>
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
				<div class="mb-3">
					<label for="description" class="form-label">Description</label>
					<input type="text" class="form-control" name="description" id="description" value="<?=$week->description?>">
				</div>
				<div class="mb-3">
					<label for="description_long" class="form-label">Extended Description</label>
					<input type="text" class="form-control" name="description_long" id="description_long" value="<?=$week->description_long?>">
				</div>
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
