<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Game;
use Pick55\Models\Season;
use Pick55\Models\Team;
use Pick55\Models\Week;
use Pick55\Snippets\GameForm;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Create Game - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Games / Create';
$page->options['admin_bar']['sub_bar']['type'] = 'games';

$step = 1;
$total_steps = 4;
$weeks = [];

$seasons = Season::orderBy('id', 'DESC')
	->get();
$sel_season = Season::find(get('season_id'));

$sel_week = Week::find(get('week_id'));
if ($sel_week && !$sel_season) {
	$sel_season = $sel_week->season;
}

if ($sel_season && !$sel_week) {
	$step = 2;
	$weeks = $sel_season->weeks()
		->orderBy('week_num', 'ASC')
		->get();
}
if ($sel_season && $sel_week) {
	$step = 3;
}

if (is_post()) {
	try {
		if (post('action') == 'create') {
			if (!$sel_season) {
				throw new Exception("Invalid season.");
			}
			if (!$sel_week) {
				throw new Exception("Invalid week.");
			}
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
			$game = Game::create([
				'football_week_id' => $sel_week->id,
				'title' => post('title'),
				'type' => post('league'),
				'away_team_id' => $away_team->id,
				'home_team_id' => $home_team->id,
				'bet_type' => post('pick_type'),
				'value' => round(post('game_value'), 1),
				'date' => date("Y-m-d", $ts),
				'time' => date("H:i:s", $ts),
				'option_1' => post('option_1'),
				'option_2' => post('option_2'),
				'correct_option' => post('correct_option'),
			]);
			Alert::success("Created game.");
			redir('admin/games/game/index.php?id=' . $game->id);
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
	<div class="progress mb-3">
		<div class="progress-bar" role="progressbar" style="width: <?=round($step / $total_steps * 100)?>%"></div>
	</div>

	<?php if ($step == 1): ?>
		<form action="" method="get">
			<div class="card">
				<h4 class="card-header">Create Game / Select Season</h4>
				<div class="card-body">
					<label for="season_id" class="form-label">Season</label>
					<select id="season_id" name="season_id" class="form-select w-auto">
						<?php foreach ($seasons as $season): ?>
							<option value="<?=$season->id?>"><?=$season->name?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="card-footer">
					<button type="submit" class="btn btn-outline-primary">Continue</button>
				</div>
			</div>
		</form>
	<?php elseif ($step == 2): ?>
		<form action="" method="get">
			<input type="hidden" name="season_id" value="<?=$sel_season->id?>">
			<div class="card">
				<h4 class="card-header">Create Game / Select Week</h4>
				<div class="card-body">
					<dl>
						<dt>Season</dt>
						<dd><?=$sel_season->name?></dd>
					</dl>

					<label for="week_id" class="form-label">Week</label>
					<select id="week_id" name="week_id" class="form-select w-auto">
						<?php foreach ($weeks as $week): ?>
							<option value="<?=$week->id?>"><?=!$week->canCreateGames() ? '(FINALIZED) ' : ''?>Week <?=$week->week_num?> - <?=$week->description?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="card-footer">
					<a class="btn btn-light" href="?">Back</a>
					<button type="submit" class="btn btn-outline-primary">Continue</button>
				</div>
			</div>
		</form>
	<?php elseif ($step == 3): ?>
		<form action="" method="post">
			<input type="hidden" name="action" value="create">
			<div class="card">
				<h4 class="card-header">Create Game / Game Info</h4>
				<div class="card-body">
					<?=GameForm::build([
						'season' => $sel_season,
						'week' => $sel_week,
					])?>
				</div>
				<div class="card-footer">
					<a class="btn btn-light" href="?season_id=<?=$sel_season->id?>">Back</a>
					<button type="submit" class="btn btn-primary">Create Game</button>
				</div>
			</div>
		</form>
	<?php endif; ?>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
