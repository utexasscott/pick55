<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;
use Pick55\Models\User;
use Pick55\Models\UsersSeasonsLink;
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
			$season->name = $name;
			$season->fee = round(post('fee'), 2);
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
								<th>Description</th>
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
				<div class="col-sm-4 mb-3">
					<label for="fee" class="form-label">Fee</label>
					<input type="text" class="form-control" name="fee" id="fee" placeholder="120.00" value="<?=$season->fee?>" required>
				</div>
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
