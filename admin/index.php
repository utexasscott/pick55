<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;

Auth::guardAdmin();

$page = new Page;
$page->setTitle('Admin');
$page->options['admin_bar']['show'] = true;

ob_start();
?>
<div class="container py-4">
	<h3 class="mb-3">Admin</h3>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
