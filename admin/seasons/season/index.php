<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;
use Pick55\Models\User;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\WeekFormatPayout;
use Pick55\Snippets\AdminWeekRow;

Auth::guardAdmin();

$season = Season::find(get('id'));
if (!$season) {
	$season = Season::getActive();
}

$page = new Page;
$page->setTitle($season->name . ' - Seasons - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = $season->name;
$page->options['admin_bar']['sub_bar']['type'] = 'season';
$page->options['admin_bar']['sub_bar']['obj'] = $season;

$weeks = $season->weeks()
	->orderBy('week_num', 'ASC')
	->get();

if (is_post()) {
	try {
		if (post('action') == 'save') {
			$name = trim(post('name'));
			if (!strlen($name)) {
				throw new Exception("Please enter a name.");
			}
			$existing_season = Season::where('name', 'LIKE', $name)
				->where('id', '!=', $season->id)
				->first();
			if ($existing_season) {
				throw new Exception("The name you entered is already being used by another season.");
			}
			$fee = round((float) post('fee'), 2);
			$weekly_pot = round((float) post('weekly_pot'), 2);
			if ($weekly_pot < 0 || $weekly_pot * (int) $season->num_weeks > $fee) {
				throw new Exception("The weekly pot times " . (int) $season->num_weeks . " weeks cannot be more than the fee.");
			}
			$season->name = $name;
			$season->fee = $fee;
			$season->weekly_pot = $weekly_pot;
			$season->is_cfp = post('is_cfp') ? 1 : 0;
			$season->pay_to_name = strlen(trim(post('pay_to_name'))) ? trim(post('pay_to_name')) : null;
			$season->pay_to_venmo = strlen(trim(post('pay_to_venmo'))) ? ltrim(trim(post('pay_to_venmo')), '@') : null;
			$season->save();
			Alert::success("Saved changes.");
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
			<h4 class="card-header">Season #<?=$season->id?>: <?=$season->name?></h4>
			<div class="card-body">
				<div class="table-responsive">
					<table class="table table-sm table-striped">
						<thead>
							<tr class="text-center">
								<th class="text-end">ID</th>
								<th>Week</th>
								<th>Format</th>
								<th class="text-center">Pools</th>
								<th class="text-end">Games Finalized At</th>
								<th class="text-end">Picks Due At</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ($weeks as $week) {
								print AdminWeekRow::build([
									'week' => $week,
									'col_season' => false,
								]);
							}
							?>
						</tbody>
					</table>
				</div>
				<div class="col-sm-4 mb-3">
					<label for="name" class="form-label">Season Name</label>
					<input type="text" class="form-control" name="name" id="name" placeholder="2001 Season" value="<?=$season->name?>" required autofocus>
				</div>
				<div class="row">
					<div class="col-sm-4 mb-3">
						<label for="fee" class="form-label">Fee</label>
						<input type="text" class="form-control" name="fee" id="fee" placeholder="120.00" value="<?=$season->fee?>" required>
					</div>
					<div class="col-sm-4 mb-3">
						<label for="weekly_pot" class="form-label">Weekly pot per player</label>
						<input type="text" class="form-control" name="weekly_pot" id="weekly_pot" placeholder="10.00" value="<?=$season->weekly_pot?>" required>
						<div class="form-text">Of each fee, per regular week. The rest (now $<?=WeekFormatPayout::money($season->getFinalsPot())?>) goes to the <?=$season->playoff_weeks ? 'playoffs' : 'season standings'?>.</div>
					</div>
				</div>
				<div class="row">
					<div class="col-sm-4 mb-3">
						<label for="pay_to_name" class="form-label">Pay entry fees to</label>
						<input type="text" class="form-control" name="pay_to_name" id="pay_to_name" placeholder="Brad" value="<?=h($season->pay_to_name)?>">
					</div>
					<div class="col-sm-4 mb-3">
						<label for="pay_to_venmo" class="form-label">Their Venmo</label>
						<div class="input-group">
							<span class="input-group-text">@</span>
							<input type="text" class="form-control" name="pay_to_venmo" id="pay_to_venmo" placeholder="Brad-North-2" value="<?=h($season->pay_to_venmo)?>">
						</div>
						<div class="form-text">Blank hides "How do I join?" on the rules page.</div>
					</div>
				</div>
				<div class="form-check mb-3">
					<input class="form-check-input" type="checkbox" name="is_cfp" id="is_cfp" value="1"<?=$season->is_cfp ? ' checked' : ''?>>
					<label class="form-check-label" for="is_cfp">College Football Playoff lines only</label>
				</div>

				<h5>On the <a href="<?=$page->link('rules.php?id=' . $season->id)?>">rules page</a>, from elsewhere</h5>
				<?php
				$ko_week = $season->getKnockoutWeek();
				$gg = $season->getGuaranteeGrid();
				$starts_at = $season->getStartsAt();
				?>
				<dl class="row small mb-0">
					<dt class="col-sm-3">Weeks</dt>
					<dd class="col-sm-9"><?=(int) $season->num_weeks?> regular + <?=(int) $season->playoff_weeks?> playoff (season row)</dd>
					<dt class="col-sm-3">Games per week</dt>
					<dd class="col-sm-9"><?=$season->getGamesPerWeek()?> (the usual count in this season's weeks)</dd>
					<dt class="col-sm-3">Starts</dt>
					<dd class="col-sm-9"><?=$starts_at ? date('D, M j, Y g:i A', strtotime($starts_at)) : 'week 1 has no games yet'?> (week 1's first kickoff)</dd>
					<dt class="col-sm-3">Advancing</dt>
					<dd class="col-sm-9">
						<?php if ($ko_week): ?>
							Top <?=(int) $ko_week->getFormat()->advance?>, from week <?=(int) $ko_week->week_num?>'s format
							(<a href="<?=$page->link('admin/formats/format/index.php?id=' . $ko_week->getFormat()->id)?>"><?=h($ko_week->getFormat()->name)?></a>)
						<?php else: ?>
							No playoff week's format sets players advancing
						<?php endif; ?>
					</dd>
					<dt class="col-sm-3">Guaranteed games</dt>
					<dd class="col-sm-9">
						<?php if (!$gg): ?>
							None recorded
						<?php else: ?>
							Top <?=$gg['places']?><?=(int) $gg['season']->id !== (int) $season->id ? ', borrowed from the ' . h($gg['season']->name) . ' until this season\'s are set' : ''?>
						<?php endif; ?>
					</dd>
				</dl>
			</div>
			<div class="card-footer">
				<button type="submit" class="btn btn-primary btn-block">Save Changes</button>
			</div>
		</div>
	</form>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
