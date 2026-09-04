<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Seasons - Admin');
$page->options['admin_bar']['show'] = true;
$page->options['admin_bar']['title'] = 'Seasons / Activate';
$page->options['admin_bar']['sub_bar']['type'] = 'seasons';

$seasons = Season::orderBy('id', 'DESC')
	->get();

if (is_post()) {
	try {
		if (post('action') == 'activate') {
			$season = Season::find(post('season_id'));
			Season::whereRaw('1')
				->update(['is_active' => false]);
			if ($season) {

				$season->is_active = true;
				$season->save();
			}
			Alert::success("Changed active season.");
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir('admin/seasons/index.php');
}

ob_start();
?>
<div class="container py-4">
	<form action="" method="post">
		<input type="hidden" name="action" value="activate">
		<div class="card">
			<h4 class="card-header">Activate Season</h4>
			<div class="card-body">
				<div class="row g-3 align-items-center">
					<div class="col-auto">
						<select name="season_id" id="season_id" class="form-select">
							<option>None</option>
							<?php foreach ($seasons as $season): ?>
								<option <?=selb($season->is_active)?> value="<?=$season->id?>"><?=$season->name?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-auto">
						<button type="submit" class="btn btn-outline-primary">Activate</button>
					</div>
				</div>
			</div>
		</div>
	</form>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
