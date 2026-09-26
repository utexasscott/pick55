<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\R\Icons;
use Pick55\R\Shell;

Shell::guardGuest();

$shell = new Shell;
$shell->setTitle('Check your email');
$shell->setNav('none');
$shell->setPageClass('page-auth');

ob_start();
?>
<div class="card auth-card enter">
	<div class="auth-icon"><?=Icons::svg('mail')?></div>
	<h1 class="auth-title">Check your email</h1>
	<p class="auth-sub">You should have a message from <strong>support@pick55.com</strong>. It can take a few minutes to arrive, and it may land in your spam folder.</p>
	<a class="btn btn-ghost btn-block" href="<?=h($shell->link('auth/login.php'))?>">Back to sign in</a>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
