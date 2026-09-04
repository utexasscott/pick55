<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Create Season - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Seasons / Create';
$page->options['admin_bar']['sub_bar']['type'] = 'seasons';

$seasons = Season::orderBy('id', 'DESC')
	->get();

if (is_post()) {
	try {
		if (post('action') == 'create') {
			$name = trim(post('name'));
			if (!strlen($name)) {
				throw new Exception("Please enter a name.");
			}
			$existing_season = Season::where('name', 'LIKE', $name)
				->first();
			if ($existing_season) {
				throw new Exception("The name you entered is already being used by another season.");
			}
			$season = Season::create([
				'name' => $name,
				'is_active' => false,
			]);
			Alert::success("Created season.");
			redir('admin/seasons/season/index.php?id=' . $season->id);
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
			<h4 class="card-header">Create Season</h4>
			<div class="card-body">
				<div class="row g-3 align-items-center">
					<div class="col-auto">
						<input type="text" class="form-control" name="name" id="name" placeholder="Season Name" required>
					</div>
					<div class="col-auto">
						<button type="submit" class="btn btn-outline-primary">Create</button>
					</div>
				</div>
			</div>
		</div>
	</form>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
