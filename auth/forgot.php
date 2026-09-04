<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;

Auth::guardGuest();

$page = new Page;
$page->setTitle('Forgot Password');
$page->options['header']['show_links'] = false;

if (is_post()) {
	try {
		if (post('action') == 'forgot') {
			Auth::attemptReset(post('email'));
		}
	}
	catch (Exception $e) {
		if ($e->getMessage()) {
			Alert::error($e->getMessage());
			redir('auth/signup.php');
		}
	}
	Alert::info("If a user account exists with the supplied email, an email has been sent to it with a link to reset a password.");
	redir('auth/check.php');
}

ob_start();
?>
<div class="container py-4">
	<div class="row">
		<div class="col-xl-4 col-md-6 col-sm-8 offset-sm-2 offset-md-3 offset-xl-4">
			<?=$page->renderAlerts()?>
			<form action="" method="post">
				<input type="hidden" name="action" value="forgot">
				<div class="card">
					<div class="card-body">
						<h3 class="card-title mb-3">Forgot Password</h3>

						<div class="mb-3">Enter your email address and we will send you a link to reset your password.</div>

						<div class="form-floating mb-3">
							<input type="email" class="form-control" name="email" id="email" placeholder="name@example.com" required autofocus>
							<label for="email">Email address</label>
						</div>

						<div class="d-grid mb-3">
							<button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
						</div>

						<div>
							<div class="fw-bold">Remember your password?</div>
							<div><a href="login.php">Sign In</a></div>
						</div>
					</div>
				</div>
			</form>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
