<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Auth;
use Pick55\Page;

Auth::guardGuest();

$page = new Page;
$page->setTitle('Check Your Email');
$page->options['header']['show_links'] = false;

ob_start();
?>
<div class="container py-4">
	<div class="row">
		<div class="col-xl-4 col-md-6 col-sm-8 offset-sm-2 offset-md-3 offset-xl-4">
			<?=$page->renderAlerts()?>
			<div class="card">
				<div class="card-body">
					<h3 class="card-title mb-3">Check Your Email!</h3>

					<div>You should have a message from <code>support@pick55.com</code>. Email sometimes takes a few minutes to arrive. You may need to check your spam folder.</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
