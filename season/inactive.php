<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;

Auth::guard();

$season = Season::getActive();
if ($season) {
	redir('index.php');
}
$season = Season::getLatest();
if ($season) {
	redir('index.php');
}

$page = new Page;
$page->setTitle('Inactive');

ob_start();
?>
<div class="container py-4">
	<div class="row">
		<div class="col-xl-4 col-md-6 col-sm-8 offset-sm-2 offset-md-3 offset-xl-4">
			<?=$page->renderAlerts()?>
			<div class="card">
				<div class="card-body">
					<h3 class="card-title mb-3">No Active Season</h3>
					<div>Sorry, there is no active season at the moment. Please check back later.</div>
					<div class="text-center mt-4"><a class="btn btn-outline-primary" href="<?=$page->link()?>">Home</a></div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
