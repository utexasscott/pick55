<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\R\Icons;
use Pick55\R\Shell;

Shell::guardGuest();

if (is_post()) {
	try {
		if (post('action') == 'forgot') {
			Auth::attemptReset(post('email'));
		}
	}
	catch (Exception $e) {
		if ($e->getMessage()) {
			Alert::error($e->getMessage());
			redir('r/auth/forgot.php');
		}
	}
	Alert::info("If an account exists for that email, we have sent it a link to reset the password.");
	redir('r/auth/check.php');
}

$shell = new Shell;
$shell->setTitle('Forgot password');
$shell->setNav('none');
$shell->setPageClass('page-auth');

ob_start();
?>
<div class="card auth-card enter">
	<div class="auth-icon"><?=Icons::svg('key')?></div>
	<h1 class="auth-title">Forgot your password?</h1>
	<p class="auth-sub">Enter your email and we will send you a link to set a new one.</p>

	<form method="post" action="" data-async data-validate>
		<input type="hidden" name="action" value="forgot">

		<div class="field">
			<label class="field-label" for="fg-email">Email</label>
			<input class="input" type="email" name="email" id="fg-email" autocomplete="email" inputmode="email" required autofocus data-msg-required="Enter your email address." data-msg-type="That does not look like an email address.">
			<div class="field-error" aria-live="polite"></div>
		</div>

		<button type="submit" class="btn btn-primary btn-lg btn-block"><?=Icons::svg('mail')?>Send reset link</button>
	</form>

	<div class="auth-foot">Remembered it? <a href="<?=h($shell->link('auth/login.php'))?>">Sign in</a></div>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
