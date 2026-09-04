<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Paging;
use Pick55\Models\Game;
use Pick55\Models\Season;
use Pick55\Models\Week;
use Pick55\Snippets\DateDisplay;
use Pick55\Snippets\TimeDisplay;
use Pick55\Snippets\AdminGameRow;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Games - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Games';
$page->options['admin_bar']['sub_bar']['type'] = 'games';

$app = App::get();
$season = Season::getActive();
$paging = new Paging(['per' => 24]);

if (get('show') == 'all') {
	$q = Game::orderBy('football_week_id', 'DESC');
}
else {
	// Only this week's games
	$week = Week::getActive();
	if (!$week) {
		// No "this" week found
		Alert::warning("No viewable week.");
		redir('admin/games/index.php?all=1');
	}
	if (get('show') == 'week') {
		// All this week's games
		$q = $week->games();
	}
	else {
		// Only this week's unset games
		$q = $week->games()
			->where('correct_option','=','0');
	}
}

$paging->setTotal($q->count());
$games = $q->orderBy('date', 'DESC')
	->orderBy('time', 'DESC')
	->limit($paging->getLimit())
	->offset($paging->getOffset())
	->with('week')
	->get();

AdminGameRow::handle();

ob_start();
?>
<div class="container py-4">
	<div class="card">
		<h4 class="card-header">Games List</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped">
				<thead>
					<tr class="text-center">
						<th>ID</th>
						<th class="text-end">Teams</th>
						<th class="text-start">Kickoff</th>
						<th class="text-end">Options</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ($games as $game) {
						print AdminGameRow::build(['game' => $game]);
					}
					?>
				</tbody>
			</table>
		</div>
		<div class="card-footer">
			<?=$paging->render()?>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
