<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Paging;
use Pick55\Models\Team;
use Pick55\Models\Week;
use Pick55\Snippets\AdminWeekRow;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Teams - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Teams';
$page->options['admin_bar']['sub_bar']['type'] = 'teams';

$teams = Team::orderBy('type', 'ASC')
	->orderBy('team', 'ASC')
	->get();

ob_start();
?>
<div class="container py-4">
	<div class="card">
		<h4 class="card-header">Teams List</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped">
				<thead>
					<tr class="text-center">
						<th class="text-end">ID</th>
						<th>League</th>
						<th>Name</th>
						<th>Nickname</th>
						<th>Vegas Insider URL Slug</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($teams as $team): ?>
						<tr>
							<td class="cell-link"><a class="text-end" href="team/index.php?id=<?=$team->id?>"><?=$team->id?></a></td>
							<td class="text-center"><?=$team->type?></td>
							<td class="text-end fw-bold"><?=$team->team?></td>
							<td class="p-0">
								<div style="padding: 3px 10px; border-radius: 4px; font-weight: bold; <?=$team->getCss()?>"><?=$team->nickname?></div>
							</td>
							<td><?=$team->vegas_insider_url?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
