<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Game;
use Pick55\Models\Season;
use Pick55\Models\Team;
use Pick55\Models\Week;
use Pick55\Snippets\GameForm;

Auth::guardAdmin();

$week = Week::find(get('week_id'));
if (!$week) {
	redir('admin/weeks/index.php');
}

$page = new Page;
$page->setTitle('Bulk Games - Week #' . $week->id . ' - Weeks - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Weeks';
$page->options['admin_bar']['sub_bar']['type'] = 'week';
$page->options['admin_bar']['sub_bar']['obj'] = $week;

$teams = Team::orderBy('team', 'ASC')
	->orderBy('nickname', 'ASC')
	->get();

$games = $week->games()
	->orderBy('type', 'ASC')
	->orderBy('date', 'ASC')
	->orderBy('time', 'ASC')
	->get();

$scrapes = [];
$scrape_path = __DIR__ . '/../../../scrape/raw/vegas-insider';
foreach (scandir($scrape_path) as $league) {
	if (in_array($league, ['.', '..'])) {
		continue;
	}
	$league_path = $scrape_path . '/' . $league;
	foreach (scandir($league_path) as $file) {
		if (in_array($file, ['.', '..'])) {
			continue;
		}
		$file_path = $league_path . '/' . $file;
		if (preg_match('/^(\d+-\d+-\d+)-(\d+-\d+-\d+)\.json$/', $file, $m)) {
			$date = $m[1];
			$time = str_replace('-', ':', $m[2]);
			$ts = strtotime($date . ' ' . $time);
			if ($ts) {
				if (!isset($scrapes[$league]) || $scrapes[$league]['ts'] < $ts) {
					$scrapes[$league] = [
						'path' => $file_path,
						'league' => $league,
						'ts' => $ts,
						'slug' => $league . '/' . $file,
					];
				}
			}
		}
	}
}

$populate_game_data = [];
if ($scrape_slug = get('scrape_slug')) {
	foreach ($scrapes as $scrape) {
		if ($scrape['slug'] == $scrape_slug) {
			$data = json_decode(file_get_contents($scrape['path']));
			$unknown_team_slugs = [];
			foreach ($data as $game_data) {
				$game_data->league = strtoupper($scrape['league']);
				$game_data->away_team_id = null;
				$game_data->home_team_id = null;
				foreach ($teams as $team) {
					if ($team->type != $game_data->league) {
						continue;
					}
					if ($team->vegas_insider_url == $game_data->away_team) {
						$game_data->away_team_id = $team->id;
					}
					if ($team->vegas_insider_url == $game_data->home_team) {
						$game_data->home_team_id = $team->id;
					}
				}
				if (!$game_data->away_team_id) {
					$unknown_team_slugs[] = $game_data->away_team;
				}
				elseif (!$game_data->home_team_id) {
					$unknown_team_slugs[] = $game_data->home_team;
				}
				else {
					$populate_game_data[] = $game_data;
				}
			}
			if (sizeof($unknown_team_slugs)) {
				$str = '<ul class="mb-0">';
				foreach ($unknown_team_slugs as $team_slug) {
					$str .= '<li>' . $team_slug . '</li>';
				}
				$str .= '</ul>';
				Alert::warning("The following team vegas-insider-urls are not set: " . $str);
			}
			break;
		}
	}
}

$dates = [];
foreach ($populate_game_data as $k => $v) {
	$dates[$k] = $v->date;
}
array_multisort($dates, SORT_ASC, $populate_game_data);

if (is_post()) {
	try {
		if (post('action') == 'create') {
			$num_success = 0;
			$game_ids = [];
			foreach ($_POST['game_id'] as $index => $game_id) {
				$league = $_POST['league'][$index];
				$game_date = $_POST['game_date'][$index];
				$game_time = $_POST['game_time'][$index];
				$away_team_id = $_POST['away_team_id'][$index];
				$home_team_id = $_POST['home_team_id'][$index];
				$pick_type = $_POST['pick_type'][$index];
				$game_value = $_POST['game_value'][$index];

				$game = null;
				if ($game_id) {
					$game = Game::find($game_id);
					if (!$game) {
						Alert::warning("Could not save game row #" . $index .": Invalid game ID '" . $game_id . "'.");
						continue;
					}
				}
				else {
					if ($away_team_id || $home_team_id) {
						$game = Game::create([
							'football_week_id' => $week->id,
						]);
					}
					else {
						continue;
					}
				}

				$away_team = Team::find($away_team_id);
				$home_team = Team::find($home_team_id);

				$option_1 = '';
				$option_2 = '';
				if ($pick_type == Game::BET_TYPE_OVER_UNDER) {
					$option_1 = 'OVER (' . $game_value . ')';
					$option_2 = 'UNDER (' . $game_value . ')';
				}
				elseif ($pick_type == Game::BET_TYPE_SPREAD) {
					$option_1 = ($away_team ? $away_team->team : 'Away Team') . ' (' . round($game_value, 1) . ')';
					$option_2 = ($home_team ? $home_team->team : 'Home Team') . ' (' . round($game_value * -1, 1) . ')';
				}

				$game->type = $league;
				$game->date = strtotime($game_date) ? date("Y-m-d", strtotime($game_date)) : null;
				$game->time = strtotime($game_time) ? date("H:i:s", strtotime($game_time)) : null;
				$game->away_team_id = ifempty($away_team_id, null);
				$game->home_team_id = ifempty($home_team_id, null);
				$game->bet_type = $pick_type;
				$game->value = ifempty($game_value, null);
				$game->title = ($away_team ? $away_team->getName() : '') . ' @ ' . ($home_team ? $home_team->getName() : '');
				$game->option_1 = $option_1;
				$game->option_2 = $option_2;
				$game->save();
				$game_ids[] = $game->id;
				$num_success++;
			}
			Game::where('football_week_id', '=', $week->id)
				->whereNotIn('id', $game_ids)
				->delete();
			Alert::success("Saved " . $num_success . " games.");
			redir('admin/weeks/week/bulk-games.php?week_id=' . $week->id);
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir();
}

ob_start();
?>
<script>
$(document).ready(function () {
	$('body').on('change', '[name="league[]"]', function (event, is_init = false) {
		var game_row = $(this).parents('.game').first();
		game_row.find('[data-team-league]').hide();
		game_row.find('[data-team-league="' + $(this).val() + '"]').show();
		if (!is_init) {
			game_row.find('[data-team-league="' + $(this).val() + '"][value=""]').prop('selected', true);
		}
	});
	$('[name="league[]"]').trigger('change', true);

	$('body').on('click', '.action-remove-game', function () {
		$(this).parents('.game').first().remove();
		return false;
	});

	$('.action-add-game').on('click', function () {
		var game_template = $('.game-template').first();
		var game_row = game_template.clone();
		game_row.removeClass('hidden');
		game_row.removeClass('game-template');
		game_template.parents('table').first().append(game_row);
		game_row.find('select option:selected').prop('selected', false);
		game_row.find('input').val('');
		return false;
	});

	$('.remove-unselected-games').on('click', function () {
		$('.ck-game-off').each(function () {
			$(this).remove();
		});
	});
	$(".ck-game").on('click', function () {
		if ($(this).hasClass('ck-game-off')) {
			$(this).addClass('ck-game-on');
			$(this).removeClass('ck-game-off');
		}
		else {
			$(this).addClass('ck-game-off');
			$(this).removeClass('ck-game-on');
		}
	});
});

</script>
<?php
$page->setScripts(ob_get_clean());

/**
 * @param array $game_data
 * @param array $options
 */
function render_game_row(array $game_data = []) {
	global $teams;
	$game_data = array_merge([
		'template' => false,
		'populated' => false,
		'id' => null,
		'league' => null,
		'date' => null,
		'time' => null,
		'away_team_id' => null,
		'home_team_id' => null,
		'bet_type' => null,
		'value' => null,
	], $game_data);
	ob_start();
	?>
	<tr class="align-middle game <?=$game_data['populated'] ? 'ck-game ck-game-off' : '' ?><?=$game_data['template'] ? 'game-template hidden' : ''?>">
		<td class="text-center">
			<?php if ($game_data['populated']): ?>
				<input type="hidden" name="game_id[]" value="">
				<input type="hidden" name="league[]" value="<?=$game_data['league']?>">
				<?=$game_data['league']?>
			<?php else: ?>
				<input type="hidden" name="game_id[]" value="<?=$game_data['id']?>">
				<select name="league[]" class="form-select w-auto">
					<?php foreach (Game::getLeagues() as $league): ?>
						<option <?=sel($game_data['league'], $league)?> value="<?=$league?>"><?=$league?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>
		<td>
			<?php if ($game_data['populated']): ?>
				<input type="hidden" name="game_date[]" value="<?=date("Y-m-d", strtotime($game_data['date']))?>">
				<input type="hidden" name="game_time[]" value="<?=date("H:i:s", strtotime($game_data['time']) - 60*60)?>">
				<div style="float: left"><?=date("l m/d", strtotime($game_data['date']))?></div>
				<div style="float: right;"><?=date("g:i A", strtotime($game_data['time']) - 60*60)?></div>
			<?php else: ?>
				<div class="input-group w-auto">
					<input type="date" class="form-control" name="game_date[]" value="<?=strtotime($game_data['date']) ? date("Y-m-d", strtotime($game_data['date'])) : ''?>">
					<input type="time" class="form-control" name="game_time[]" value="<?=strtotime($game_data['time']) ? date("H:i:s", strtotime($game_data['time'])) : ''?>">
				</div>
			<?php endif; ?>
		</td>
		<td class="text-end">
			<?php if ($game_data['populated']): ?>
				<input type="hidden" name="away_team_id[]" value="<?=$game_data['away_team_id']?>">
				<?=$game_data['away_team']?>
			<?php else: ?>
				<select name="away_team_id[]" class="form-select">
					<?php foreach (Game::getLeagues() as $league): ?>
						<option data-team-league="<?=$league?>" value=""></option>
					<?php endforeach; ?>
					<?php foreach ($teams as $team): ?>
						<option
							value="<?=$team->id?>"
							data-team="<?=$team->team?>"
							data-team-league="<?=$team->type?>"
							style="<?=$team->getCss()?>"
							<?=sel($game_data['away_team_id'], $team->id)?>
							><?=$team->team?> <?=$team->nickname?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>
		<td class="text-center">@</td>
		<td>
			<?php if ($game_data['populated']): ?>
				<input type="hidden" name="home_team_id[]" value="<?=$game_data['home_team_id']?>">
				<?=$game_data['home_team']?>
			<?php else: ?>
				<select name="home_team_id[]" class="form-select">
					<?php foreach (Game::getLeagues() as $league): ?>
						<option data-team-league="<?=$league?>" value=""></option>
					<?php endforeach; ?>
					<?php foreach ($teams as $team): ?>
						<option
							value="<?=$team->id?>"
							data-team="<?=$team->team?>"
							data-team-league="<?=$team->type?>"
							style="<?=$team->getCss()?>"
							<?=sel($game_data['home_team_id'], $team->id)?>
							><?=$team->team?> <?=$team->nickname?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>
		<td>
			<?php if ($game_data['populated']): ?>
				<input type="hidden" name="pick_type[]" value="<?=$game_data['bet_type']?>">
				<?=$game_data['bet_type']?>
			<?php else: ?>
				<select name="pick_type[]" class="form-select w-auto">
					<?php foreach (Game::getPickTypes() as $pick_type): ?>
						<option <?=sel($game_data['bet_type'], $pick_type)?> value="<?=$pick_type?>"><?=$pick_type?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>
		<td>
			<?php if ($game_data['populated']): ?>
				<input type="hidden" name="game_value[]" value="<?=$game_data['value']?>">
				<?=$game_data['value']?>
			<?php else: ?>
				<input type="text" name="game_value[]" value="<?=$game_data['value']?>" class="form-control">
			<?php endif; ?>
		</td>
		<td>
			<button class="action-remove-game btn btn-outline-danger btn-xs mt-2"><i class="fas fa-times"></i></button>
		</td>
	</tr>
	<?php
	return ob_get_clean();
}

ob_start();
?>
<div class="container py-4">
	<form action="" method="get">
		<input type="hidden" name="week_id" value="<?=$week->id?>">
		<div class="card mb-3">
			<h4 class="card-header">Populate from Scrape Data File</h4>
			<div class="card-body">
				<?php if (sizeof($populate_game_data)): ?>
					<i>Currently populating <?=sizeof($populate_game_data)?> games. <a href="?week_id=<?=$week->id?>">Reset</a></i>
				<?php else: ?>
					<div class="input-group">
						<select name="scrape_slug" class="form-select">
							<option></option>
							<?php foreach ($scrapes as $scrape): ?>
								<option value="<?=$scrape['slug']?>"><?=$scrape['league'] . ' - ' . date("Y-m-d", $scrape['ts'])?></option>
							<?php endforeach; ?>
						</select>
						<button class="btn btn-outline-primary" type="submit">Populate</button>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</form>
	<form action="" method="post" id="bulk-games-form">
		<input type="hidden" name="action" value="create">
		<div class="card">
			<h4 class="card-header">Bulk Games Tool - <?=$week->season->name?> - Week <?=$week->week_num?></h4>
			<div class="card-body">
				<div class="fst-italic">For spreads, negative value means away team is favored, positive value means home team is favored.</div>
			</div>

			<div class="table-responsive">
				<table class="table table-sm table-striped">
					<thead>
						<tr class="text-center">
							<th>League</th>
							<th>Kickoff At</th>
							<th>Away Team</th>
							<th>@</th>
							<th>Home Team</th>
							<th>Type</th>
							<th>Value</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php
						print render_game_row(['template' => true]);
						if (!sizeof($games) && !sizeof($populate_game_data)) {
							print render_game_row();
						}
						foreach ($games as $game) {
							print render_game_row([
								'id' => $game->id,
								'league' => $game->type,
								'date' => $game->date,
								'time' => $game->time,
								'away_team_id' => $game->away_team_id,
								'home_team_id' => $game->home_team_id,
								'bet_type' => $game->bet_type,
								'value' => $game->value,
							]);
						}
						foreach ($populate_game_data as $game_data) {
							print render_game_row([
								'league' => $game_data->league,
								'date' => $game_data->date,
								'time' => $game_data->time,
								'away_team' => $game_data->away_team,
								'home_team' => $game_data->home_team,
								'away_team_id' => $game_data->away_team_id,
								'home_team_id' => $game_data->home_team_id,
								'bet_type' => Game::BET_TYPE_SPREAD,
								'value' => $game_data->spread,
								'populated' => true,
							]);
							print render_game_row([
								'league' => $game_data->league,
								'date' => $game_data->date,
								'time' => $game_data->time,
								'away_team' => $game_data->away_team,
								'home_team' => $game_data->home_team,
								'away_team_id' => $game_data->away_team_id,
								'home_team_id' => $game_data->home_team_id,
								'bet_type' => Game::BET_TYPE_OVER_UNDER,
								'value' => $game_data->{'over-under'},
								'populated' => true,
							]);
						}
						?>
					</tbody>
				</table>
			</div>
			<div class="card-footer">
				<div class="d-flex justify-content-between">
					<a href="#" class="action-add-game btn btn-outline-primary">Add Game</a>
					<span class="remove-unselected-games  btn btn-outline-danger">Remove Unselected</span>
					<button type="submit" class="btn btn-primary">Save</button>
				</div>
			</div>
		</div>
	</form>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
