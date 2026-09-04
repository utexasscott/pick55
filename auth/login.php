<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;

Auth::guardGuest();

$page = new Page;
$page->setTitle('Sign In');
$page->options['header']['show_links'] = false;

if (is_post()) {
	try {
		if (post('action') == 'login') {
			$user = Auth::checkCredentials(post('email'), post('password'));
			if (!$user) {
				throw new Exception("Invalid email and/or password.");
			}
			Auth::setAuthedUserId($user->id);
			Alert::success("You have signed in.");
			redir('index.php');
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir();
}

ob_start();
?>
<div class="container py-4">
	<div class="row">
		<div class="col-xl-4 col-md-6 col-sm-8 offset-sm-2 offset-md-3 offset-xl-4">
			<?=$page->renderAlerts()?>
			<form action="" method="post">
				<input type="hidden" name="action" value="login">
				<div class="card">
					<div class="card-body">
						<h3 class="card-title mb-3">Sign In</h3>

						<div class="form-floating mb-3">
							<input type="email" class="form-control" name="email" id="email" placeholder="name@example.com" required autofocus>
							<label for="email">Email address</label>
						</div>

						<div class="form-floating mb-4">
							<input type="password" class="form-control" name="password" id="password" placeholder="Password" required>
							<label for="password">Password</label>
						</div>

						<div class="d-grid mb-3">
							<button type="submit" class="btn btn-primary btn-block">Sign In</button>
						</div>

						<div class="mb-3"><a href="forgot.php">Forgot password?</a></div>

						<div>
							<div class="fw-bold">No account?</div>
							<div><a href="signup.php">Sign Up</a></div>
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
