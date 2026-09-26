<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\R\Icons;
use Pick55\R\Shell;

Shell::guardGuest();

if (is_post()) {
	try {
		if (post('action') == 'signup') {
			// Keep what was typed (never the password) so an error does not clear the form.
			$_SESSION[SKEY]['r_signup'] = [
				'email' => (string) post('email'),
				'first_name' => (string) post('first_name'),
				'last_name' => (string) post('last_name'),
			];
			Auth::attemptSignup(post('email'), post('password'), post('first_name'), post('last_name'), post('passcode'));
		}
	}
	catch (Exception $e) {
		if ($e->getMessage()) {
			Alert::error($e->getMessage());
			redir('r/auth/signup.php');
		}
	}
	unset($_SESSION[SKEY]['r_signup']);
	Alert::info("Verify your email to complete account creation.");
	redir('r/auth/check.php');
}

$kept = isset($_SESSION[SKEY]['r_signup']) ? $_SESSION[SKEY]['r_signup'] : [];
$val = function ($key) use ($kept) {
	return isset($kept[$key]) ? $kept[$key] : '';
};

$shell = new Shell;
$shell->setTitle('Sign up');
$shell->setNav('none');
$shell->setPageClass('page-auth');

ob_start();
?>
<div class="card auth-card enter">
	<div class="auth-icon"><?=Icons::svg('football')?></div>
	<h1 class="auth-title">Join the pool</h1>
	<p class="auth-sub">Fourteen games a week, ten confidence points, one leaderboard. We will email you a link to finish.</p>

	<form method="post" action="" data-async data-validate>
		<input type="hidden" name="action" value="signup">

		<div class="field">
			<label class="field-label" for="su-email">Email</label>
			<input class="input" type="email" name="email" id="su-email" value="<?=h($val('email'))?>" autocomplete="email" inputmode="email" required autofocus data-msg-required="Enter your email address." data-msg-type="That does not look like an email address.">
			<div class="field-error" aria-live="polite"></div>
		</div>

		<div class="field-row">
			<div class="field">
				<label class="field-label" for="su-first">First name</label>
				<input class="input" type="text" name="first_name" id="su-first" value="<?=h($val('first_name'))?>" autocomplete="given-name" required data-msg-required="Required.">
				<div class="field-error" aria-live="polite"></div>
			</div>
			<div class="field">
				<label class="field-label" for="su-last">Last name</label>
				<input class="input" type="text" name="last_name" id="su-last" value="<?=h($val('last_name'))?>" autocomplete="family-name" required data-msg-required="Required.">
				<div class="field-error" aria-live="polite"></div>
			</div>
		</div>

		<div class="field">
			<label class="field-label" for="su-password">Password</label>
			<input class="input" type="password" name="password" id="su-password" autocomplete="new-password" minlength="8" required data-msg-required="Choose a password." data-msg-length="At least 8 characters.">
			<div class="field-hint">At least 8 characters.</div>
			<div class="field-error" aria-live="polite"></div>
		</div>

		<div class="field">
			<label class="field-label" for="su-passcode">League passcode</label>
			<input class="input" type="text" name="passcode" id="su-passcode" autocomplete="off" autocapitalize="characters" spellcheck="false" required data-msg-required="Ask the commissioner for the passcode.">
			<div class="field-error" aria-live="polite"></div>
		</div>

		<button type="submit" class="btn btn-primary btn-lg btn-block">Create account</button>
	</form>

	<div class="auth-foot">Already have an account? <a href="<?=h($shell->link('auth/login.php'))?>">Sign in</a></div>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
