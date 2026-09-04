<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\UsersSeasonsLink;

Auth::guard();

$app = App::get();
$season = $app->getSeason();
if (!$season) {
	redir('season/inactive.php');
}
$me = Auth::user();
if ($season->hasPlayer($me->id)) {
	redir('index.php');
}

$page = new Page;
$page->setTitle('Not Authorized');
$page->options['season_bar']['show'] = true;
$page->options['season_bar']['show_select'] = true;
$page->options['season_bar']['title'] = 'Not Authorized';
$page->options['season_bar']['rel_path'] = 'season/index.php';

ob_start();
?>
<div class="container py-4">
	<div class="row">
		<div class="col-xl-4 col-md-6 col-sm-8 offset-sm-2 offset-md-3 offset-xl-4">
			<?=$page->renderAlerts()?>
			<div class="card">
				<div class="card-body">
					<h3 class="card-title mb-3">Not Authorized</h3>
					<div>Sorry, you are not a player in the selected season. Contact the league admin for help.</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
