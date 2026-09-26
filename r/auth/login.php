<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\R\Icons;
use Pick55\R\Shell;

Shell::guardGuest();

// Where to go after signing in: a relative r/ path (Shell::safeReturn).
$return = Shell::safeReturn(input('r'));
$self = 'r/auth/login.php' . ($return !== null ? '?r=' . urlencode($return) : '');

if (is_post()) {
	try {
		if (post('action') == 'login') {
			$_SESSION[SKEY]['r_login_email'] = (string) post('email');
			$user = Auth::checkCredentials(post('email'), post('password'));
			if (!$user) {
				throw new Exception("That email and password do not match an account.");
			}
			unset($_SESSION[SKEY]['r_login_email']);
			Auth::setAuthedUserId($user->id);
			Alert::success("You have signed in.");
			redir('r/' . ($return !== null ? $return : 'index.php'));
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir($self);
}

$email = isset($_SESSION[SKEY]['r_login_email']) ? $_SESSION[SKEY]['r_login_email'] : '';

$shell = new Shell;
$shell->setTitle('Sign in');
$shell->setNav('none');
$shell->setPageClass('page-auth');

ob_start();
?>
<div class="card auth-card enter">
	<div class="auth-icon"><?=Icons::svg('football')?></div>
	<h1 class="auth-title">Sign in</h1>
	<p class="auth-sub">Welcome back. Your picks are waiting.</p>

	<form method="post" action="" data-async data-validate>
		<input type="hidden" name="action" value="login">
		<?php if ($return !== null): ?>
			<input type="hidden" name="r" value="<?=h($return)?>">
		<?php endif; ?>

		<div class="field">
			<label class="field-label" for="login-email">Email</label>
			<input class="input" type="email" name="email" id="login-email" value="<?=h($email)?>" autocomplete="email" inputmode="email" required <?=$email === '' ? 'autofocus' : ''?> data-msg-required="Enter your email address." data-msg-type="That does not look like an email address.">
			<div class="field-error" aria-live="polite"></div>
		</div>

		<div class="field">
			<label class="field-label" for="login-password">
				<span>Password</span>
				<a href="<?=h($shell->link('auth/forgot.php'))?>">Forgot?</a>
			</label>
			<input class="input" type="password" name="password" id="login-password" autocomplete="current-password" required <?=$email !== '' ? 'autofocus' : ''?> data-msg-required="Enter your password.">
			<div class="field-error" aria-live="polite"></div>
		</div>

		<button type="submit" class="btn btn-primary btn-lg btn-block"><?=Icons::svg('log-in')?>Sign in</button>
	</form>

	<div class="auth-foot">New to Pick55? <a href="<?=h($shell->link('auth/signup.php'))?>">Create an account</a></div>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
