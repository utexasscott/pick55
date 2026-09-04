<?php

require_once __DIR__ . '/inc/_inc.php';

use Pick55\Alert;
use Pick55\Auth;
use Pick55\Page;

$page = new Page;

ob_start();
?>
<div class="bg-dark text-secondary">
	<div class="container">
		<?=$page->renderAlerts()?>
	</div>
	<div class="container col-xxl-8 px-4 pb-5">
		<div class="row flex-lg-row-reverse align-items-center justify-content-center g-5 py-4">
			<div class="col-12 col-sm-10 col-lg-6">
				<img src="static/img/boucher.jpg" class="d-block mx-lg-auto img-fluid">
			</div>
			<div class="col-lg-6">
				<h1 class="display-5 fw-bold lh-1 mb-3 text-white">Pick55</h1>
				<p class="lead text-white">Pick lines and over/unders for the NFL and NCAA football season. Order your picks by confidence or place the points on the games you care about most. Starts early Sept and runs for 12 weeks!</p>
				<div class="d-grid gap-2 d-md-flex justify-content-md-start">
					<?php if (!Auth::authed()): ?>
						<a href="auth/signup.php" class="btn btn-primary btn-lg px-4 me-md-2">Sign Up</a>
						<a href="auth/login.php" class="btn btn-outline-light btn-lg px-4">Sign In</a>
					<?php else: ?>
						<a href="season/index.php" class="btn btn-outline-light btn-lg px-4 me-md-2">My Season</a>
						<a href="season/standings.php" class="btn btn-outline-light btn-lg px-4 me-md-2">Standings</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="container px-4">
	<div class="row align-items-start justify-content-around mb-4 py-5">
		<div class="col-lg-4">
			<div class="splash-icon"><i class="fas fa-trophy"></i></div>
			<h5>Lines / Spreads</h5>
			<p>Sometimes the winner of a game seems obvious. Like when the <a target="_blank" href="https://en.wikipedia.org/wiki/1992_United_States_men%27s_Olympic_basketball_team">USA dream team</a> played Angola in the 1992 Olympics. So instead of picking who will win the game outright, odds makers create a handicap for one team, which is referred to as the line (or spread). So instead of picking USA to win vs Angola, you'd pick who would win if USA had a handicap of -40 points.</p>
		</div>
		<div class="col-lg-4">
			<div class="splash-icon"><i class="fas fa-random"></i></div>
			<h5>Over / Under</h5>
			<p>The goal of an over/under pick is to guess the total number of points that will be scored in a game. In the past few decades, offenses have been getting better. In the NFL in 2020 teams averaged 24.8 points per game.</p>
		</div>
		<div class="col-lg-4">
			<div class="splash-icon"><i class="fas fa-football-ball"></i></div>
			<h5>Confidence</h5>
			<p>You might not be so sure about some picks each week. Or you might not care about certain games or teams. No problem! Just put those picks at the bottom of your list and they will have a marginal impact on your weekly score.</p>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
