<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Bet;
use Pick55\Models\Game;
use Pick55\Models\User;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\Week;
use Pick55\Snippets\DateTimeDisplay;

Auth::guardAdmin();

$week = Week::find(get('id'));
if (!$week) {
	redir('admin/weeks/index.php');
}

$page = new Page;
$page->setTitle('Picks - Week #' . $week->id . ' - Weeks - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Week #' . $week->id;
$page->options['admin_bar']['sub_bar']['type'] = 'week';
$page->options['admin_bar']['sub_bar']['obj'] = $week;

$players = [];
$status_by_player_id = [];
foreach ($week->season->getPlayers() as $player) {
	$players[$player->id] = $player;
	$status_by_player_id[$player->id] = 'NONE';
}

$num_games = $week->games()->count();

$q = DB::table(Bet::getTableName() . ' AS pick')
	->leftJoin(Game::getTableName() . ' AS game', 'pick.football_game_id', '=', 'game.id')
	->select([
		DB::raw("COUNT(*) AS num"),
		'pick.user_id',
	])
	->where('game.football_week_id', '=', $week->id)
	->whereIn('pick.user_id', array_keys($status_by_player_id))
	->whereIn('option', ['1', '2', '3'])
	->groupBy('pick.user_id');
foreach ($q->cursor() as $row) {
	$status_by_player_id[$row->user_id] = ($row->num == $num_games ? 'ALL' : 'SOME');
}

$player_ids_by_status = [
	'ALL' => [],
	'SOME' => [],
	'NONE' => [],
];
foreach ($status_by_player_id as $player_id => $status) {
	$player_ids_by_status[$status][] = $player_id;
}

if (is_post()) {
	try {
		if (post('action') == 'randomize') {
			$player = User::find(post('user_id'));
			if (!$player) {
				throw new Exception("Invalid player.");
			}
			if (!$player->randomizePicksForWeek($week->id)) {
				throw new Exception("Could not randomize picks.");
			}
			Alert::success("Randomized the picks for player.");
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
		<input type="hidden" name="action" value="randomize">
		<div class="card mb-3">
			<h4 class="card-header">Randomize Remaining Picks</h4>
			<div class="card-body">
				<div class="input-group">
					<select class="form-select" name="user_id">
						<option></option>
						<?php
						foreach (['SOME', 'NONE'] as $key):
							foreach ($player_ids_by_status[$key] as $player_id):
								$player = $players[$player_id];
								?>
								<option value="<?=$player_id?>"><?=$player->getFullDisplay()?></option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="btn btn-outline-primary">Randomize</button>
				</div>
			</div>
		</div>
	</form>
	<div class="card">
		<h4 class="card-header">Picks for Week #<?=$week->id?></h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped table-bordered">
				<thead>
					<tr class="text-center">
						<th>All Picks Done</th>
						<th>Some Picks Done</th>
						<th>No Picks</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$num_rows = 0;
					$keys = [
						'ALL',
						'SOME',
						'NONE',
					];
					foreach ($keys as $key) {
						$num_rows = max($num_rows, sizeof($player_ids_by_status[$key]));
					}
					for ($i = 0; $i < $num_rows; $i++): ?>
						<tr>
							<?php
							foreach ($keys as $key):
								$player = null;
								if (isset($player_ids_by_status[$key][$i])) {
									$player = $players[$player_ids_by_status[$key][$i]];
								}
								if ($player): ?>
									<td><?=$player->first_name?> <?=$player->last_name?> &lt;<?=$player->email?>&gt;,</td>
								<?php else: ?>
									<td></td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endfor; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
