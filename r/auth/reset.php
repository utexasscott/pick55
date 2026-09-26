<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Models\User;
use Pick55\R\Icons;
use Pick55\R\Shell;

Shell::guardGuest();

$token = trim((string) get('token'));
$user = null;
if (strlen($token) >= 16) {
	$user = User::where('reset_token', 'LIKE', $token)
		->first();
}
if (!$user) {
	redir('r/auth/login.php');
}
if (strtotime($user->reset_token_at) < time() - 60 * 60) {
	Alert::warning("The link you clicked has expired. Please try again.");
	redir('r/auth/forgot.php');
}

if (is_post()) {
	try {
		if (post('action') == 'reset') {
			Auth::setNewPassword($user, post('password'));
			Alert::success("New password set. Please sign in.");
			redir('r/auth/login.php');
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir('r/auth/reset.php?token=' . urlencode($token));
}

$shell = new Shell;
$shell->setTitle('Reset password');
$shell->setNav('none');
$shell->setPageClass('page-auth');

ob_start();
?>
<div class="card auth-card enter">
	<div class="auth-icon"><?=Icons::svg('lock')?></div>
	<h1 class="auth-title">Set a new password</h1>
	<p class="auth-sub">For <strong><?=h($user->email)?></strong>. At least 8 characters.</p>

	<form method="post" action="" data-async data-validate>
		<input type="hidden" name="action" value="reset">

		<div class="field">
			<label class="field-label" for="rs-password">New password</label>
			<input class="input" type="password" name="password" id="rs-password" autocomplete="new-password" minlength="8" required autofocus data-msg-required="Choose a password." data-msg-length="At least 8 characters.">
			<div class="field-error" aria-live="polite"></div>
		</div>

		<button type="submit" class="btn btn-primary btn-lg btn-block">Set password</button>
	</form>

	<div class="auth-foot">Remembered it? <a href="<?=h($shell->link('auth/login.php'))?>">Sign in</a></div>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
