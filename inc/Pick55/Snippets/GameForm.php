<?php

namespace Pick55\Snippets;

use Pick55\Models\Game;
use Pick55\Models\Season;
use Pick55\Models\Team;
use Pick55\Models\Week;
use Pick55\Snippets\DateTimeDisplay;

class GameForm
{
	/**
	 * @return string
	 */
	public static function getScript()
	{
		ob_start();
		?>
		<script>
		function clean_whitespace(str) {
			return str.replace(/\s+/g, ' ').trim();
		}
		function soft_str_eq(str1, str2) {
			return clean_whitespace(str1) == clean_whitespace(str2);
		}
		function update_generated() {
			var title = $('[data-title]');
			var opt1 = $('[data-option1]');
			var opt2 = $('[data-option2]');
			var val = $('#game_value').val();
			var pick_type = $('#pick_type').val();
			var away_team = $('#away_team_id option:selected');
			var home_team = $('#home_team_id option:selected');

			title.text(away_team.text() + " @ " + home_team.text());
			if (pick_type == '<?=Game::BET_TYPE_OVER_UNDER?>') {
				opt1.text("OVER (" + val + ")");
				opt2.text("UNDER (" + val + ")");
			}
			else {
				opposite_val = parseFloat(val) * -1;
				readable_val = val;
				readable_opposite_val = opposite_val;
				if (val > 0) {
					readable_val = "+" + val;
				}
				else {
					readable_opposite_val = "+" + opposite_val;
				}
				opt1.text(away_team.attr('data-team') + " (" + readable_val + ")");
				opt2.text(home_team.attr('data-team') + " (" + readable_opposite_val + ")");
			}

			if (!soft_str_eq(title.text(), $('#title').val())) {
				title.parent().addClass('bg-warning');
			}
			else {
				title.parent().removeClass('bg-warning');
			}

			if (!soft_str_eq(opt1.text(), $('#option_1').val())) {
				opt1.parent().addClass('bg-warning');
			}
			else {
				opt1.parent().removeClass('bg-warning');
			}

			if (!soft_str_eq(opt2.text(), $('#option_2').val())) {
				opt2.parent().addClass('bg-warning');
			}
			else {
				opt2.parent().removeClass('bg-warning');
			}
		}
		$(document).ready(function () {
			$('[data-copyto]').on('click', function () {
				var src = $($(this).attr('data-copywhat'));
				var target = $($(this).attr('data-copyto'));
				if (target && src) {
					target.val(src.text().replace(/\s+/g, ' '));
				}
				update_generated();
				return false;
			});

			$('[name="league"]').on('change', function (event, is_init = false) {
				$('[data-team-league]').hide();
				$('[data-team-league="' + $(this).val() + '"]').show();
				if (!is_init) {
					$('[data-team-league="' + $(this).val() + '"][value=""]').prop('selected', true);
				}
				update_generated();
			});
			$('[name="league"]').trigger('change', true);
			$('#away_team_id').on('change', function () { update_generated(); });
			$('#home_team_id').on('change', function () { update_generated(); });
			$('#game_value').on('change', function () { update_generated(); });
			$('#game_value').on('keyup', function () { update_generated(); });
			$('#pick_type').on('change', function () { update_generated(); });
			update_generated();
		});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'season' => null,
			'week' => null,
			'game' => null,
		], $params);

		$season = $params['season'];
		$week = $params['week'];
		$game = $params['game'];

		$teams = Team::orderBy('team', 'ASC')
			->orderBy('nickname', 'ASC')
			->get();

		ob_start();
		?>
		<dl>
			<dt>Season</dt>
			<dd><a href="../../seasons/season/index.php?id=<?=$season->id?>"><?=$season->name?></a></dd>

			<dt>Week</dt>
			<dd><a href="../../weeks/week/index.php?id=<?=$week->id?>">Week <?=$week->week_num?> - <?=$week->getName()?></a></dd>

			<dt>Games Finalized At</dt>
			<dd><?=ifempty(DateTimeDisplay::b($week->picks_due_date), 'TBD')?></dd>
		</dl>

		<div class="row mb-3">
			<div class="col-lg-3 col-sm-4">
				<label for="league" class="form-label">League</label>
				<select id="league" name="league" class="form-select" required>
					<?php foreach (Game::getLeagues() as $league): ?>
						<option <?=$game ? sel($game->type, $league) : ''?> value="<?=$league?>"><?=$league?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<div class="row mb-3">
			<div class="col-lg-3 col-sm-4">
				<label for="pick_type" class="form-label">Type</label>
				<select id="pick_type" name="pick_type" class="form-select" required>
					<?php foreach (Game::getPickTypes() as $pick_type): ?>
						<option <?=$game ? sel($game->bet_type, $pick_type) : ''?> value="<?=$pick_type?>"><?=$pick_type?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-lg-2 col-sm-3">
				<label for="type" class="form-label">Value</label>
				<input type="text" name="game_value" id="game_value" value="<?=$game ? round($game->value, 1) : 0?>" class="form-control" required>
			</div>
		</div>
		<div class="row mb-3">
			<div class="col-lg-3 col-sm-4">
				<label for="away_team_id" class="form-label">Away Team</label>
				<select id="away_team_id" name="away_team_id" class="form-select" required>
					<?php foreach (Game::getLeagues() as $league): ?>
						<option data-team-league="<?=$league?>" value=""></option>
					<?php endforeach; ?>
					<?php foreach ($teams as $team): ?>
						<option
							<?=$game && $team->id == $game->away_team_id ? 'selected' : ''?>
							value="<?=$team->id?>"
							data-team="<?=$team->type == 'NCAA' ? $team->team : $team->nickname?>"
							data-team-league="<?=$team->type?>"
							><?=$team->team?> <?=$team->nickname?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-lg-3 col-sm-4">
				<label for="home_team_id" class="form-label">@ Home Team</label>
				<select id="home_team_id" name="home_team_id" class="form-select" required>
					<?php foreach (Game::getLeagues() as $league): ?>
						<option data-team-league="<?=$league?>" value=""></option>
					<?php endforeach; ?>
					<?php foreach ($teams as $team): ?>
						<option
							<?=$game && $team->id == $game->home_team_id ? 'selected' : ''?>
							value="<?=$team->id?>"
							data-team="<?=$team->type == 'NCAA' ? $team->team : $team->nickname?>"
							data-team-league="<?=$team->type?>"
							><?=$team->team?> <?=$team->nickname?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<div class="row mb-3">
			<div class="col-lg-6 col-mg-8 col-sm-10">
				<label>Date and Time</label>
				<div class="input-group">
					<input type="date" class="form-control" name="game_date" required value="<?=$game && strtotime($game->date) ? date("Y-m-d", strtotime($game->date)) : ''?>">
					<input type="time" class="form-control" name="game_time" required value="<?=$game && strtotime($game->time) ? date("H:i:s", strtotime($game->time)) : ''?>">
				</div>
			</div>
		</div>

		<dl class="mb-3">
			<div>
				<dt>Suggested Title <a data-copywhat="#suggested_title" data-copyto="#title" class="fw-normal" href="#">Copy to input</a></dt>
				<dd data-title id="suggested_title"></dd>
			</div>

			<div>
				<dt>Suggested Option 1 <a data-copywhat="#suggested_option1" data-copyto="#option_1" class="fw-normal" href="#">Copy to input</a></dt>
				<dd data-option1 id="suggested_option1"></dd>
			</div>

			<div>
				<dt>Suggested Option 2 <a data-copywhat="#suggested_option2" data-copyto="#option_2" class="fw-normal" href="#">Copy to input</a></dt>
				<dd data-option2 id="suggested_option2"></dd>
			</div>
		</dl>

		<div class="row mb-3">
			<div class="col-lg-6 col-sm-12">
				<label for="title" class="form-label">Title</label>
				<input type="text" name="title" id="title" value="<?=$game ? $game->title : ''?>" class="form-control" required>
			</div>
		</div>

		<div class="row mb-3">
			<div class="col-lg-3 col-sm-4">
				<label for="option_1" class="form-label">Option 1</label>
				<input type="text" name="option_1" id="option_1" value="<?=$game ? $game->option_1 : ''?>" class="form-control" required>
			</div>
			<div class="col-lg-3 col-sm-4">
				<label for="option_2" class="form-label">Option 2</label>
				<input type="text" name="option_2" id="option_2" value="<?=$game ? $game->option_2 : ''?>" class="form-control" required>
			</div>
			<div class="col-lg-3 col-sm-4">
				<label for="correct_option" class="form-label">Correct Option</label>
				<select id="correct_option" name="correct_option" class="form-select" required>
					<?php foreach ([
						'0' => 'Undecided',
						'1' => 'Option 1',
						'2' => 'Option 2',
					] as $val => $name): ?>
						<option value="<?=$val?>" <?=$game ? sel($game->correct_option, $val) : ''?>><?=$name?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
