<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Game;
use Pick55\Models\Team;
use Pick55\Snippets\GameForm;

Auth::guardAdmin();

$game = Game::find(get('id'));
if (!$game) {
	redir('admin/games/index.php');
}

$page = new Page;
$page->setTitle('Game #' . $game->id . ' - Games - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Games / Game #' . $game->id;
$page->options['admin_bar']['sub_bar']['type'] = 'game';
$page->options['admin_bar']['sub_bar']['obj'] = $game;

if (is_post()) {
	try {
		if (post('action') == 'save') {
			$ts = strtotime(post('game_date') . ' ' . post('game_time'));
			if (!$ts) {
				throw new Exception("Invalid date / time.");
			}
			$away_team = Team::find(post('away_team_id'));
			if (!$away_team) {
				throw new Exception("Invalid away team.");
			}
			$home_team = Team::find(post('home_team_id'));
			if (!$home_team) {
				throw new Exception("Invalid home team.");
			}
			$game->title = post('title');
			$game->type = post('league');
			$game->away_team_id = $away_team->id;
			$game->home_team_id = $home_team->id;
			$game->bet_type = post('pick_type');
			$game->value = round(post('game_value'), 1);
			$game->date = date("Y-m-d", $ts);
			$game->time = date("H:i:s", $ts);
			$game->option_1 = post('option_1');
			$game->option_2 = post('option_2');
			$game->correct_option = post('correct_option');
			$game->save();
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
			<h4 class="card-header">Game #<?=$game->id?></h4>
			<div class="card-body">
				<?=GameForm::build([
					'season' => $game->week->season,
					'week' => $game->week,
					'game' => $game,
				])?>
			</div>
			<div class="card-footer">
				<button type="submit" class="btn btn-primary btn-block">Save Changes</button>
			</div>
		</div>
	</form>
</div>
<?php
$page->setContent(ob_get_clean());
$page->setScripts(GameForm::getScript());
print $page->render();
