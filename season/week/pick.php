<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Bet;
use Pick55\Models\Guarantee;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\Week;
use Pick55\Models\Team;
use Pick55\Snippets\DateTimeDisplay;

Auth::guard();

$app = App::get();
$season = $app->getSeason();
if (!$season) {
	redir('season/inactive.php');
}
$me = Auth::user();
if (!$season->hasPlayer($me->id)) {
	redir('season/unauthorized.php');
}
$week = $season->getPickWeek();
if (!$week) {
	redir('season/index.php');
}
if (!$week->canUserPick($me->id)) {
	if ($week->canUserSeeResults($me->id)) {
		redir('season/week/results.php?id=' . $week->id);
	}
	redir('season/index.php');
}

$mult_less_than = 0;
$guarantee = Guarantee::where('week_id','=',$week->id)
				->where('user_id','=',$me->id)
				->first();
if ($guarantee) {
	$mult_less_than = $guarantee->multipliers_less_than;
}

$page = new Page;
$page->setTitle('Picks for Week ' . $week->week_num . ' - ' . $season->name);
$page->options['season_bar']['show'] = true;
$page->options['season_bar']['title'] = 'Week #' . $week->week_num . ' / Make Picks';

$game_ids = [];
$team_ids = [];
$games = [];
$q = $week->games()
	->inRandomOrder();
foreach ($q->cursor() as $game) {
	$games[] = $game;
	$game_ids[] = $game->id;
	if ($game->away_team_id) $team_ids[$game->away_team_id] = 1;
	if ($game->home_team_id) $team_ids[$game->home_team_id] = 1;
}
$team_ids = array_keys($team_ids);
$q = Bet::whereIn('football_game_id', $game_ids)
	->where('user_id', '=', $me->id)
	->orderBy('multiplier', 'DESC');
$my_picks_by_game_id = [];
foreach ($q->cursor() as $pick) {
	$my_picks_by_game_id[$pick->football_game_id] = $pick;
}

if (sizeof($my_picks_by_game_id)) {
	$sort = [];
	foreach ($games as $k => $game) {
		$sort[$k] = 0;
		if (array_key_exists($game->id, $my_picks_by_game_id)) {
			$sort[$k] = $my_picks_by_game_id[$game->id]->multiplier;
		}
	}
	array_multisort($sort, SORT_DESC, $games);
}

// Find team colors
$q = DB::table(Team::getTableName())
	->select([
		'id',
		'color_1',
		'color_2',
		'color_3',
	])
	->whereIn('id', $team_ids);
$team_colors = [];
foreach ($q->cursor() as $row) {
	$team_colors[$row->id] = [
		$row->color_1,
		$row->color_2,
		$row->color_3,
	];
}

ob_start();
?>
<style type="text/css">
.game {
	border: 1px solid #aaa;
	border-radius: 3px;
	display: flex;
	align-items: stretch;
	justify-content: center;
}
.game-info {
	line-height: 1.2;
	padding: 0.25rem 1rem;
}
.game-middle {
	position: relative;
}
.game-overlay {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0, 0, 0, 0.2);
}
.game-mult {
	font-weight: bold;
	font-size: 20px;
	width: 40px;
}
.game-option {
	font-weight: normal;
	border: 4px solid #eee;
	background-color: #fff;
	cursor: pointer;
	border-radius: 3px;
	padding: 2px 10px;
}
.game-option:hover {
	background-color: #b6d4e8;
	border-color: #90b1c7;
}
.game-option input {
	display: none;
}
.game-option div {
	display: inline-block;
	padding: 0 5px;
}
.over-under-active.active {
	color: #fff;
	background-color: #333;
	border-color: #666;
	font-weight: bold;
}
.game-placer {
	cursor: pointer;
	padding: 0.25rem 1rem;
	display: flex;
	justify-content: center;
	align-items: center;
	width: 50px;
	border-right: 1px solid rgba(0, 0, 0, 0.1);
	background-color: #cae1f0;
	border-right: 1px solid #a9cee6;
}
.game-placer:hover {
	background-color: #b6d4e8;
	border-color: #90b1c7;
}
.game-placer-cancel,
.game-placer-cancel:hover {
	background-color: #f0caca;
	border-color: #d8abab;
}
.game-placer-before,
.game-placer-after {
	background-color: #c6e8b6;
	border-color: #a9ce98;
}
.game-placer-before:hover,
.game-placer-after:hover {
	background-color: #a9ce98;
	border-color: #7fab6a;
}
.game-placer.hidden {
	display: none;
}
.game-placing .game-overlay {
	background-color: rgba(175, 200, 255, 0.4);
}
.guaranteed {
	background-color: #D6C0EF!important;
}
.checkered {
	background-image: linear-gradient(45deg, #fff 25%, transparent 25%), linear-gradient(-45deg, #fff 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #fff 75%), linear-gradient(-45deg, transparent 75%, #fff 75%);
	background-size: 18px 18px;
	background-position: 0 0, 0 9px, 9px -9px, -9px 0px;
	border-width: 1px!important;
}
.checkered div {
	margin-top: 3px;
	border-radius: 3px;
}

<?php foreach ($team_colors as $team_id => $colors): ?>
	.team-<?=$team_id?>.active { <?=Team::getCssForColors($colors[0], $colors[1], $colors[2])?> font-weight: bold; }
	.team-<?=$team_id?>.active div { <?=Team::getInnerCssForColors($colors[0], $colors[1], $colors[2])?> font-weight: bold; }
<?php endforeach; ?>
</style>
<?php
$page->setStyles(ob_get_clean());

ob_start();
?>
<script>
function remult() {
	var mult_less_than = $('#mult_less_than').val();
	var mult = 10;
	$('.game').each(function () {
		$(this).find('.game-mult-input').first().val(mult);
		$(this).find('.game-mult-text').first().text(mult);
		$(this).removeClass('guaranteed');
		if (mult < mult_less_than) {
			$(this).addClass('guaranteed');
			$(this).find('.game-mult-text').first().text('✓');
		}
		mult = Math.max(0, mult - 1);
	});
}

function save_changes() {
	setTimeout(function() {
		$.ajax({
			type: "POST",
			url: 'save-picks.php?week_id=<?=$week->id?>',
			data: $('#save-form').serialize(),
			dataType: "json",
			success: function (data) {
				if (data.error) {
					window.location.replace("../../auth/login.php?r=season/week/pick.php");
				}
				else {
					$('.saved').fadeIn('fast');
					$('.saved').delay(1000).fadeOut('fast');
				}
			}
		});
	}, 50);
}

function reset_placer() {
	$('.game').removeClass('game-placing');
	$('.game-placer-cancel').hide();
	$('.game-placer-before').hide();
	$('.game-placer-after').hide();
	$('.game-overlay').hide();
	$('.game-placer-start').css('display', 'flex');
}

$(document).ready(function () {
	remult();
	// set the active class of checkbox parents
	$('.game-option-input').on('change', function (event, is_init = false) {
		$(this).parents('.game-pick').first().find('.game-option').removeClass('active');
		$(this).parents('.game-option').first().addClass('active');
		if (!is_init) {
			save_changes();
		}
	});
	$('.game-option-input:checked').trigger('change', true);
	save_changes();

	$('.game-placer-start').on('click', function () {
		$('.game-placer-start').hide();
		$('.game-overlay').show();
		var game = $(this).parents('.game').first();
		game.find('.game-placer-cancel').css('display', 'flex');
		game.prevAll('.game').find('.game-placer-before').css('display', 'flex');
		game.nextAll('.game').find('.game-placer-after').css('display', 'flex');
		game.addClass('game-placing');
	});
	$('.game-placer-cancel').on('click', function () {
		reset_placer();
	});
	$('.game-placer-before').on('click', function () {
		var game = $('.game-placing');
		reset_placer();
		var target_game = $(this).parents('.game').first();
		game.insertBefore(target_game);
		remult();
		save_changes();
		game.hide();
		game.slideDown('slow');
	});
	$('.game-placer-after').on('click', function () {
		var game = $('.game-placing');
		reset_placer();
		var target_game = $(this).parents('.game').first();
		game.insertAfter(target_game);
		remult();
		save_changes();
		game.hide();
		game.slideDown('slow');
	});
});
</script>
<?php
$page->setScripts(ob_get_clean());

ob_start();
?>
<div class="container py-4">
	<form id="save-form">
		<input type="hidden" id="mult_less_than" value="<?=$mult_less_than?>">
		<div class="row">
			<div class="col-xl-6 col-md-8 col-sm-10 offset-sm-1 offset-md-2 offset-xl-3 mb-1">
				<div class="fst-italic">Order your picks from most confident to least. You may change your picks up until the first game.</div>
				<div class="fw-bold">Picks due <?=ago($week->getFirstGameAt())?>. <span class="text-success saved hidden">Changes saved.</span></div>
				<?php
				$mult = 11;
				foreach ($games as $game):
					$mult = max(0, $mult - 1);
					$my_pick = null;
					if (array_key_exists($game->id, $my_picks_by_game_id)) {
						$my_pick = $my_picks_by_game_id[$game->id];
					}
					?>
					<div class="game mb-2 bg-light">
						<div class="game-placer game-placer-start">
							<i class="fas fa-exchange-alt fa-rotate-90"></i>
						</div>
						<div class="game-placer game-placer-cancel hidden">
							<i class="fas fa-times"></i>
						</div>
						<div class="game-placer game-placer-before hidden  game-mover-up">
							<i class="fas fa-share"></i>
						</div>
						<div class="game-placer game-placer-after hidden game-mover-down">
							<i class="fas fa-share fa-flip-vertical"></i>
						</div>
						<div class="game-middle flex-grow-1 d-flex flex-column">
							<div class="game-overlay hidden"></div>
							<div class="game-row1 d-flex text-center">
								<div class="game-mult">
									<div class="ps-2 pt-2 game-mult-text"><?=max(0, $mult)?></div>
									<input class="game-mult-input" type="hidden" name="game-<?=$game->id?>-mult" value="<?=$mult?>">
								</div>
								<div class="game-info flex-grow-1">
									<div class="game-title"><?=$game->title?></div>
									<div><small class="text-muted">(<?=$game->type?>) <?=DateTimeDisplay::b($game->date . ' ' . $game->time)?></small></div>
								</div>
							</div>
							<div class="game-pick d-flex justify-content-center align-items-stretch text-center p-1 gap-1 pt-0">
								<label class="game-option w-50 p-1 <?=$game->bet_type == 'spread' ? $game->awayTeam->css_custom_class . ' team-' . $game->away_team_id : 'over-under-active' ?>">
									<input class="game-option-input form-check-input me-1" <?=$my_pick && $my_pick->option == '1' ? 'checked' : ''?> name="game-<?=$game->id?>-option" type="radio" value="1">
									<div><?=$game->option_1?></div>
								</label>
								<label class="game-option w-50 p-1 <?=$game->bet_type == 'spread' ? $game->homeTeam->css_custom_class . ' team-' . $game->home_team_id : 'over-under-active' ?>">
									<input class="game-option-input form-check-input me-1" <?=$my_pick && $my_pick->option == '2' ? 'checked' : ''?> name="game-<?=$game->id?>-option" type="radio" value="2">
									<div><?=$game->option_2?></div>
								</label>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</form>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
