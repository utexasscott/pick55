<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;
use Pick55\Snippets\AdminWeekRow;

Auth::guardAdmin();

$season = Season::find(get('id'));
if (!$season) {
	redir('admin/seasons/index.php');
}

$page = new Page;
$page->setTitle('Weeks - ' . $season->name . ' - Seasons - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = $season->name;
$page->options['admin_bar']['sub_bar']['type'] = 'season';
$page->options['admin_bar']['sub_bar']['obj'] = $season;

$weeks = $season->weeks()
	->orderBy('week_num', 'ASC')
	->get();

if (is_post()) {
	try {
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir();
}

ob_start();
?>
<div class="container py-4">
	<div class="card">
		<div class="card-header">
			<div class="d-flex">
				<h4 class="mb-0 me-3">Weeks in Season</h4>
			</div>
		</div>
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
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
