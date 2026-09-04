<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Seasons - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Seasons';
$page->options['admin_bar']['sub_bar']['type'] = 'seasons';

$seasons = Season::orderBy('id', 'DESC')
	->get();

ob_start();
?>
<div class="container py-4">
	<div class="card">
		<h4 class="card-header">Season List</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped">
				<thead>
					<tr class="text-center">
						<th class="text-end">ID</th>
						<th>Name</th>
						<th># Weeks</th>
						<th># Games</th>
						<th># Players</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($seasons as $season): ?>
						<tr>
							<td class="text-end"><?=$season->id?></td>
							<td class="cell-link fw-bold"><a class="text-center" href="season/index.php?id=<?=$season->id?>"><?=$season->name?></a></td>
							<td class="text-center"><?=$season->weeks()->count()?></td>
							<td class="text-center"><?=$season->getNumGames()?></td>
							<td class="text-center"><?=$season->getNumPlayers()?></td>
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
