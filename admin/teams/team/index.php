<?php

require_once __DIR__ . '/../../../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Game;
use Pick55\Models\Team;

Auth::guardAdmin();

$team = Team::find(get('id'));
if (!$team) {
	redir('admin/teams/index.php');
}

$page = new Page;
$page->setTitle('Team #' . $team->id . ' - Teams - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Teams';
$page->options['admin_bar']['sub_bar']['type'] = 'teams';
$page->options['admin_bar']['sub_bar']['obj'] = $team;

$default_colors = Team::getDefaultColors();

if (is_post()) {
	try {
		if (post('action') == 'save') {
			$team->type = post('league');
			$team->team = trim(post('team'));
			$team->nickname = trim(post('nickname'));
			$team->vegas_insider_url = ifempty(trim(post('vegas_insider_url')), null);
			$team->color_1 = ifempty(trim(str_replace('#', '', post('color_1'))), null);
			$team->color_2 = ifempty(trim(str_replace('#', '', post('color_2'))), null);
			$team->color_3 = ifempty(trim(str_replace('#', '', post('color_3'))), null);
			$team->save();
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
			<h4 class="card-header">Team #<?=$team->id?></h4>
			<div class="card-body">
				<div class="col-lg-3 col-sm-4 mb-3">
					<label for="league" class="form-label">League</label>
					<select id="league" name="league" class="form-select" required>
						<?php foreach (Game::getLeagues() as $league): ?>
							<option <?=sel($team->type, $league)?> value="<?=$league?>"><?=$league?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mb-3">
					<label for="team" class="form-label">Name</label>
					<input type="text" class="form-control" name="team" id="team" value="<?=$team->team?>" required>
				</div>
				<div class="mb-3">
					<label for="nickname" class="form-label">Nickname</label>
					<input type="text" class="form-control" name="nickname" id="nickname" value="<?=$team->nickname?>" required>
				</div>
				<div class="mb-3">
					<label for="vegas_insider_url" class="form-label">Vegas Insider URL Slug</label>
					<input type="text" class="form-control" name="vegas_insider_url" id="vegas_insider_url" value="<?=$team->vegas_insider_url?>">
				</div>
				<dl>
					<dt>Color Example</dt>
					<dd><div style="padding: 3px 10px; <?=$team->getCss()?>; font-weight: bold;"><?=$team->getName()?></div></dd>
				</dl>
				<div class="row">
					<div class="col-auto">
						<label for="color_1" class="form-label">Background Color</label>
						<input type="color" class="form-control form-control-color" name="color_1" id="color_1" value="#<?=ifempty($team->color_1, $default_colors[0])?>">
					</div>
					<div class="col-auto">
						<label for="color_2" class="form-label">Text Color</label>
						<input type="color" class="form-control form-control-color" name="color_2" id="color_2" value="#<?=ifempty($team->color_2, $default_colors[1])?>">
					</div>
					<div class="col-auto">
						<label for="color_3" class="form-label">Border Color</label>
						<input type="color" class="form-control form-control-color" name="color_3" id="color_3" value="#<?=ifempty($team->color_3, $default_colors[2])?>">
					</div>
				</div>
			</div>
			<div class="card-footer">
				<button type="submit" class="btn btn-primary btn-block">Save Changes</button>
			</div>
		</div>
	</form>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
