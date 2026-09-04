<?php

require_once __DIR__ . '/inc/_inc.php';

use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Season;

$season = Season::getActive();
if (!$season) {
	redir('season/inactive.php');
}

$page = new Page;
$page->setTitle('Rules - ' . $season->name);

$fee = 120;
$num_regular_weeks = 10;
$num_playoff_weeks = 2;
$num_games_per_week = 14;
$picks_due = '2025-09-06 11:00:00';
$is_cfp = false;
$weekly_winner = 160;
$weekly_pot_per_player = 10;
$playoff_pot_per_player = 10;
$has_pools = false;

ob_start();
?>
<div class="container py-4">
	<h1 class="mb-4">
		Rules
		<small class="text-muted">- <?=$season->name?></small>
	</h1>

	<div class="card mb-3">
		<h4 class="card-header">FAQ</h4>
		<div class="card-body">
			<dl class="mb-0">
				<dt>When does it start?</dt>
				<dd>Picks for week 1 are due <?=date('l, F j, Y \a\t g:i A', strtotime($picks_due))?>. Payment is due at the same time.</dd>

				<dt>What is the entry fee?</dt>
				<dd>The buy-in is $<?=$fee?> for a <?=$num_regular_weeks + $num_playoff_weeks?> week season. There are a few winners every week and a big winner at the end of the season.</dd>

				<dt>How much time does it require?</dt>
				<dd>As little as 1 minute per week. Or you could spend hours each week researching teams.</dd>

				<dt>Who can join?</dt>
				<dd>The league is open to all. Feel free to invite any friends or family who may be interested.</dd>

				<dt>How do I join?</dt>
				<dd>Send payment via <a target="_blank" href="https://venmo.com/Brad-North-2">Venmo</a> (<code>@Brad-North-2</code>) to Brad. Once payment is received, we will activate your account for the season.</dd>
			</dl>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Description</h4>
		<div class="card-body">
			<p>The season will consist of <?=$num_regular_weeks?> weeks of picks, in which you will pick <?=$num_games_per_week?> games and rank them by order of confidence.</p>
			<?php if ($is_cfp): ?>
				<p>All bets will relate to the college football playoff games of the week, chosen by the league commissioner.</p>
			<?php else: ?>
				<p>The games will be the half NFL and half college football games of the week, chosen by the league commissioner.</p>
			<?php endif; ?>
			<?php if ($num_playoff_weeks): ?>
				<p class="mb-0">The season will be followed by a <?=$num_playoff_weeks?> week playoff. All players will qualify for the first week of playoffs. Ten to fourteen players will advance to the second week of playoffs.</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Fees and Winnings</h4>
		<div class="card-body">
			<dl class="mb-0">
				<dt>Entry</dt>
				<dd>The entry fee will be $<?=$fee?> for the whole season.</dd>

				<dt>Weekly Winners</dt>
				<dd>$<?=$weekly_pot_per_player?> from each player will go toward the weekly pot.</dd>
				<?php if ($weekly_winner): ?>
					<dd>The winner of each week will be paid $<?=$weekly_winner?>.</dd>
				<?php endif; ?>

				<?php if ($has_pools): ?>
					<dt>Pool winners and runners-up</dt>
					<dd>To give more players a chance to win, the players will be broken up into smaller pools each week and the winners of these pools will earn cash prizes.</dd>
				<?php endif; ?>

				<?php if ($num_playoff_weeks): ?>
					<dt>Playoffs</dt>
					<dd>$<?=$playoff_pot_per_player?> from each entry fee will be used to pay out the top players in the playoffs.</dd>
				<?php else: ?>
					<dt>Season Winner</dt>
					<dd>$<?=$playoff_pot_per_player?> from each entry fee will be used to pay out the top players in the overall season standings.</dd>
				<?php endif; ?>

				<dt>Payment</dt>
				<dd>Weekly winners are paid each week and season winnings will be paid when the season is over.</dd>
			</dl>
		</div>
	</div>

	<?php if ($num_playoff_weeks): ?>
		<div class="card mb-3">
			<h4 class="card-header">Weeks</h4>
			<div class="card-body">
				<p>This table is based on a 50 player pool. <!--The weekly pool sizes and payouts will vary depending on the actual number of players who join.--></p>
				<div class="table-responsive">
					<table class="table table-striped table-sm">
						<thead>
							<tr class="text-center">
								<th>Week</th>
								<th>Game<br><em>Description</em></th>
								<th># Pools</th>
								<th>Pool Winner</th>
								<th>Overall Winner</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach (range(1, $num_regular_weeks) as $week_num):
								$week = $season->weeks()
									->where('week_num', '=', $week_num)
									->first();
								?>
								<tr class="text-center">
									<td>Week <?=$week_num?></td>
									<td><?=$week ? $week->description . '<br><em>' . $week->description_long . '</em>' : 'TBD'?></td>
									<td><?=$week ? $week->num_pools : 'TBD'?></td>
									<td>$<?=$week ? $week->pool_winner : 'TBD'?></td>
									<td>$<?=$week ? (sprintf('%0.2f', $week->weekly_bonus + $week->pool_winner)) : 'TBD'?></td>
								</tr>
							<?php endforeach; ?>
							<tr class="text-center">
								<td>Week 11</td>
								<td>Playoffs - <span class="fw-bold">Knock-Out Round</span></td>
								<td colspan="4">Top 20 players advance to Money Round</td>
							</tr>
							<tr class="text-center">
								<td>Week 12</td>
								<td>Playoffs - <span class="fw-bold">Money Round</span></td>
								<td colspan="2">
									<table>
										<tr>
											<th colspan="2"><u>Finalists Pool</u></th>
										</tr>
										<tr>
											<th>1st place</th>
											<td>$240</td>
										</tr>
										<tr>
											<th>2nd place</th>
											<td>$140</td>
										</tr>
										<tr>
											<th>3rd place</th>
											<td>$110</td>
										</tr>
										<tr>
											<th>4th place</th>
											<td>$90</td>
										</tr>
										<tr>
											<th>5th place</th>
											<td>$70</td>
										</tr>
										<tr>
											<th>6th place</th>
											<td>$50</td>
										</tr>
										<tr>
											<th>7th place</th>
											<td>$40</td>
										</tr>
										<tr>
											<th>8th-9th place</th>
											<td>$30</td>
										</tr>
										<tr>
											<th>10th-15th place</th>
											<td>$20</td>
										</tr>
									</table>
								</td>
								<td colspan="2">
									<table>
										<tr>
											<th colspan="2"><u>Consolation Pool</u></th>
										</tr>
										<tr>
											<th>1st Pl.</th>
											<td>$80</td>
										</tr>
										<tr>
											<th>2nd Pl.</th>
											<td>$40</td>
										</tr>
										<tr>
											<th>3rd Pl.</th>
											<td>$30</td>
										</tr>
										<tr>
											<th>4th Pl.</th>
											<td>$20</td>
										</tr>
									</table>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="card mb-3">
		<h4 class="card-header">Game Selection Process</h4>
		<div class="card-body">
			<?php if (!$is_cfp): ?>
				<p class="mb-0">The games will be chosen by the league commissioner and will consist of a mix of NCAA and NFL, as well as spread and points. All odds and lines will come from <a href="http://www.vegasinsider.com">Vegas Insider</a> on Tuesday before each week's games.</p>
			<?php else: ?>
				<p class="mb-0">All bets will relate to the College Football Playoff. The props and lines will be chosen by the league commissioner. All odds and lines will come from <a href="http://www.vegasinsider.com">Vegas Insider</a> or another reputable gaming site on Tuesday before each week's games.</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Points</h4>
		<div class="card-body">
			<p class="mb-0">A total of 55 points may be scored each week. You get to choose one game as your 10 point game, one as your 9 point game, one as your 8 pt game etc.  You will also choose <?=$num_games_per_week - 10?> games to be worth 0 points.</p>
		</div>
	</div>

	<?php if ($num_playoff_weeks): ?>
		<div class="card mb-3">
			<h4 class="card-header">Playoffs</h4>
			<div class="card-body">
				<p>There will be two rounds of playoffs: a <span class="fst-italic">Knock-Out Round</span> and a <span class="fst-italic">Money Round</span>.</p>

				<h5>The Knock-Out Round</h5>

				<p>All players qualify for the <span class="fst-italic">Knock-Out Round</span>.</p>
				<p>The top 12 players from the Season Standings will receive freebies ("Guaranteed Games") in the Knock-Out round.</p>

				<div class="table-responsive">
					<table class="table table-sm">
						<thead>
							<tr>
								<th rowspan="2">Regular Season Place</th>
								<th colspan="12">Guaranteed Games</th>
							</tr>
							<tr class="text-center">
								<th>0pt</th>
								<th>0pt</th>
								<th>1pt</th>
								<th>2pt</th>
								<th>3pt</th>
								<th>4pt</th>
								<th>5pt</th>
								<th>6pt</th>
								<th>7pt</th>
								<th>8pt</th>
								<th>9pt</th>
								<th>10pt</th>
							</tr>
						</thead>
						<tbody>
							<tr class="text-center">
								<td class="text-start">1st</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">2nd</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td></td>
								<td></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">3rd</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td></td>
								<td></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">4th</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">5th</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">6th</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">7th</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">8th-10th</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">11th-20th</td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td class="td-right"><i class="fas fa-check"></i></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
							<tr class="text-center">
								<td class="text-start">21st and lower</td>
								<td colspan="100%" class="bg-secondary text-white">None</td>
							</tr>
						</tbody>
					</table>
				</div>

				<h5>The Money Round</h5>

				<p>Only the top 20 players from the Knock-Out Round qualify for the Money Round.</p>
				<p class="mb-0">There will be no seeding in this round. None of the players' previous points will be considered for any reason. Normal tie-breaker rules apply.</p>
			</div>
		</div>
	<?php endif; ?>

	<div class="card mb-3">
		<h4 class="card-header">Tiebreakers</h4>
		<div class="card-body">
			<h5>Weekly</h5>

			<p>In the event of a points tie after a week's picks (and each round of the playoffs) we will use the following steps to break the tie:</p>

			<ol>
				<li>The player with the most correctly guessed picks will be the winner.</li>
				<li>If there is still a tie, the player with the highest confidence correct pick will be the winner.</li>
				<li>If two or more players are still tied, the player with the second highest confidence correct wins</li>
				<li>Continue in this fashion until the tie is broken. If a tie is not broken, the pot will be split.</li>
			</ol>

			<?php if ($num_playoff_weeks): ?>
				<h5>Playoff Seeding</h5>
				<p class="mb-0">The first tiebreaker for playoff seeding is total points for the season. In the event of a tie, the player with the most correct picks for the season will be the winner. If two or more players are still tied, the player with the most correct 10pt picks will be the winner. If still tied, the player with the most correct 9pt picks, then 8pt picks, etc. will be determined as the winner.</p>
			<?php else: ?>
				<h5>Final Standings</h5>
				<p class="mb-0">The first tiebreaker for final standings rankings is total points for the season. In the event of a tie, the player with the most correct picks for the season will be the winner. If two or more players are still tied, the player with the most correct 10pt picks will be the winner. If still tied, the player with the most correct 9pt picks, then 8pt picks, etc. will be determined as the winner.</p>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
