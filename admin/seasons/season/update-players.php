<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;
use Pick55\Models\User;
use Pick55\Models\UsersSeasonsLink;

Auth::guardAdmin();

$season = Season::find(get('id'));
if (!$season) {
	redir('admin/seasons/index.php');
}

$page = new Page;
$page->setTitle('Modify Players - ' . $season->name . ' - Seasons - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = $season->name;
$page->options['admin_bar']['sub_bar']['type'] = 'season';
$page->options['admin_bar']['sub_bar']['obj'] = $season;

$players = $season->getPlayers();
$player_ids = [];
foreach ($players as $user) {
	$player_ids[] = $user->id;
}

$others = User::whereNotIn('id', $player_ids)
	->get();

if (is_post()) {
	try {
		if (post('action') == 'save') {
			$set_player_ids = arrayify(post('player_ids', []));
			$new_player_ids = array_diff($set_player_ids, $player_ids);
			$del_player_ids = array_diff($player_ids, $set_player_ids);
			foreach ($new_player_ids as $player_id) {
				UsersSeasonsLink::create([
					'er_user_id' => $player_id,
					'football_season_id' => $season->id,
				]);
			}
			UsersSeasonsLink::where('football_season_id', '=', $season->id)
				->whereIn('er_user_id', $del_player_ids)
				->delete();
			Alert::success("Updated players.");
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
	var players_sel = $('#players');
	var others_sel = $('#others');
	$('[data-filter-others]').on('keyup', function () {
		others_sel.find('option').hide();
		others_sel.find('option:contains("' + $(this).val() + '")').show();
	});
	$('[data-players-remove]').on('click', function () {
		players_sel.find('option:selected').prependTo(others_sel);
		players_sel.find('option:selected').prop('selected', false);
		others_sel.find('option:selected').prop('selected', false);
	});
	$('[data-players-add]').on('click', function () {
		others_sel.find('option:selected').prependTo(players_sel);
		players_sel.find('option:selected').prop('selected', false);
		others_sel.find('option:selected').prop('selected', false);
	});
	players_sel.parents('form').first().on('submit', function () {
		players_sel.find('option').prop('selected', true);
	});
});
</script>
<?php
$page->setScripts(ob_get_clean());

ob_start();
?>
<div class="container py-4">
	<form action="" method="post">
		<input type="hidden" name="action" value="save">
		<div class="card mb-3">
			<h4 class="card-header">Modify Players</h4>
			<div class="card-body">
				<div class="row mb-3">
					<div class="col-md-5">
						<label class="form-label">Non-players</label>
						<input type="text" data-filter-others class="form-control-sm form-control mb-1" placeholder="Type to filter">
						<select class="form-select" multiple size="10" name="other_ids[]" id="others">
							<?php foreach ($others as $player): ?>
								<option value="<?=$player->id?>">
									<?=$player->id?>. <?=$player->getName()?> (<?=$player->email?>)
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-2 py-3">
						<div class="d-flex flex-row flex-md-column justify-content-evenly">
							<a class="btn btn-outline-danger text-nowrap mb-md-2" data-players-remove>&lt; Remove</a>
							<a class="btn btn-outline-success text-nowrap mb-md-2" data-players-add>Add &gt;</a>
							<button type="submit" class="btn btn-primary text-nowrap">Save</button>
						</div>
					</div>
					<div class="col-md-5">
						<label class="form-label">Players</label>
						<select class="form-select" multiple size="10" name="player_ids[]" id="players">
							<?php foreach ($players as $player): ?>
								<option value="<?=$player->id?>">
									<?=$player->id?>. <?=$player->getName()?> (<?=$player->email?>)
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>
		</div>
		<div class="card">
			<h4 class="card-header">Emails</h4>
			<div class="card-body">
				<h5>Non-players</h5>
				<?php foreach ($others as $player): ?>
					<?=$player->getName()?> &#x3c;<?=$player->email?>&#x3e;,
				<?php endforeach; ?>
				<h5 class="mt-3">Players</h5>
				<?php foreach ($players as $player): ?>
					<?=$player->getName()?> &#x3c;<?=$player->email?>&#x3e;,
				<?php endforeach; ?>
			</div>
		</div>
	</form>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
