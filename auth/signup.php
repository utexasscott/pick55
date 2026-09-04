<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;

Auth::guardGuest();

$page = new Page;
$page->setTitle('Sign Up');
$page->options['header']['show_links'] = false;

if (is_post()) {
	try {
		if (post('action') == 'signup') {
			Auth::attemptSignup(post('email'), post('password'), post('first_name'), post('last_name'), post('passcode'));
		}
	}
	catch (Exception $e) {
		if ($e->getMessage()) {
			Alert::error($e->getMessage());
			redir();
		}
	}
	Alert::info("Verify your email to complete account creation.");
	redir('auth/check.php');
}

ob_start();
?>
<div class="container py-4">
	<div class="row">
		<div class="col-xl-4 col-md-6 col-sm-8 offset-sm-2 offset-md-3 offset-xl-4">
			<?=$page->renderAlerts()?>
			<form action="" method="post">
				<input type="hidden" name="action" value="signup">
				<div class="card">
					<div class="card-body">
						<h3 class="card-title mb-3">Sign Up for <span class="text-info">Pick55</span>!</h3>

						<div class="mb-3">Welcome to Pick55. Enter your email and a password and we will send you a link to get started.</div>

						<div class="form-floating mb-3">
							<input type="email" class="form-control" name="email" id="email" placeholder="name@example.com" required autofocus>
							<label for="email">Your Email Address</label>
						</div>

						<div class="form-floating mb-3">
							<input type="first_name" class="form-control" name="first_name" id="first_name" placeholder="Aaron" required autofocus>
							<label for="first_name">First Name</label>
						</div>

						<div class="form-floating mb-3">
							<input type="last_name" class="form-control" name="last_name" id="last_name" placeholder="Rodgers" required autofocus>
							<label for="last_name">Last Name</label>
						</div>

						<div class="form-floating mb-4">
							<input type="password" class="form-control" name="password" id="password" placeholder="Password" required>
							<label for="password">Create a Password (minimum 8 characters)</label>
						</div>

						<div class="form-floating mb-3">
							<input type="passcode" class="form-control" name="passcode" id="passcode" placeholder="Enter the league passcode" required autofocus>
							<label for="passcode">Passcode</label>
						</div>

						<div class="d-grid mb-3">
							<button type="submit" class="btn btn-primary btn-block">Sign Up</button>
						</div>

						<div>
							<div class="fw-bold">Already have an account?</div>
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
