<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\User;

Auth::guardGuest();

$page = new Page;
$page->setTitle('Reset Password');
$page->options['header']['show_links'] = false;

$user = User::where('reset_token', 'LIKE', get('token'))
	->first();
if (!$user) {
	redir('auth/login.php');
}
if (strtotime($user->reset_token_at) < time() - 60 * 60) {
	Alert::warning("The link you clicked has expired. Please try again.");
	redir('auth/forgot.php');
}

if (is_post()) {
	try {
		if (post('action') == 'reset') {
			Auth::setNewPassword($user, post('password'));
			Alert::success("New password set successfully, please sign in.");
			redir('auth/login.php');
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
				<input type="hidden" name="action" value="reset">
				<div class="card">
					<div class="card-body">
						<h3 class="card-title mb-3">Reset Password</h3>

						<div class="mb-3">Passwords must be at least 8 characters in length.</div>

						<div class="form-floating mb-3">
							<input type="email" class="form-control" id="email" value="<?=$user->email?>" disabled>
							<label for="email">Email address</label>
						</div>

						<div class="form-floating mb-4">
							<input type="password" class="form-control" name="password" id="password" placeholder="Password" required>
							<label for="password">New Password</label>
						</div>

						<div class="d-grid mb-3">
							<button type="submit" class="btn btn-primary btn-block">Set Password</button>
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
