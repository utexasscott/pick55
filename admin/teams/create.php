<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Paging;
use Pick55\Models\Game;
use Pick55\Models\Team;
use Pick55\Models\Week;
use Pick55\Snippets\AdminWeekRow;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Create Team - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Teams';
$page->options['admin_bar']['sub_bar']['type'] = 'teams';

$teams = Team::orderBy('type', 'ASC')
	->orderBy('team', 'ASC')
	->get();

if (is_post()) {
	try {
		if (post('action') == 'create') {
			$name = trim(post('name'));
			if (!strlen($name)) {
				throw new Exception("Please enter a name.");
			}
			$league = trim(post('league'));
			if (!in_array($league, Game::getLeagues())) {
				throw new Exception("Invalid league.");
			}
			$team = Team::create([
				'team' => $name,
				'nickname' => trim(post('nickname')),
				'type' => $league,
			]);
			Alert::success("Created team.");
			redir('admin/teams/team/index.php?id=' . $team->id);
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
		<input type="hidden" name="action" value="create">
		<div class="card">
			<h4 class="card-header">Create Team</h4>
			<div class="card-body">
				<div class="row g-3 align-items-center">
					<div class="col-auto">
						<select id="league" name="league" class="form-select" required>
							<?php foreach (Game::getLeagues() as $league): ?>
								<option value="<?=$league?>"><?=$league?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-auto">
						<input type="text" class="form-control" name="name" id="name" placeholder="Name" required>
					</div>
					<div class="col-auto">
						<input type="text" class="form-control" name="nickname" id="nickname" placeholder="Nickname" required>
					</div>
					<div class="col-auto">
						<button type="submit" class="btn btn-outline-primary">Create</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
