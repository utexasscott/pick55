<?php

require_once __DIR__ . '/inc/_inc.php';

use Pick55\Page;
use Pick55\SeasonRules;
use Pick55\Models\WeekFormatPayout;
use Pick55\Snippets\WeekFormatPayouts;

$season = SeasonRules::resolveSeason();
if (!$season) {
	redir('season/inactive.php');
}
$r = SeasonRules::get($season);
$money = function ($amount) {
	return '$' . WeekFormatPayout::money($amount);
};

$page = new Page;
$page->setTitle('Rules - ' . $season->name);

ob_start();
?>
<div class="container py-4">
	<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
		<h1 class="mb-0">
			Rules
			<small class="text-muted">- <?=h($season->name)?></small>
		</h1>
		<form method="get" action="<?=$page->link('rules.php')?>" class="d-flex gap-2">
			<label class="visually-hidden" for="season-select">Season</label>
			<select class="form-select form-select-sm w-auto" id="season-select" name="id" onchange="this.form.submit()">
				<?php foreach (SeasonRules::seasonList() as $s): ?>
					<option value="<?=(int) $s->id?>"<?=(int) $s->id === (int) $season->id ? ' selected' : ''?>><?=h($s->name)?></option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Go</button></noscript>
		</form>
	</div>

	<?php if (!$r['is_active']): ?>
		<div class="alert alert-secondary">These are the rules the <?=h($season->name)?> was played under.</div>
	<?php endif; ?>

	<div class="card mb-3">
		<h4 class="card-header">FAQ</h4>
		<div class="card-body">
			<dl class="mb-0">
				<dt>When does it start?</dt>
				<?php if ($r['starts_at']): ?>
					<dd>Picks for week 1 <?=$r['has_started'] ? 'were' : 'are'?> due <?=date('l, F j, Y \a\t g:i A', strtotime($r['starts_at']))?>, when its first game kicks off.<?php if (!$r['has_started']): ?> Payment is due at the same time.<?php endif; ?></dd>
				<?php else: ?>
					<dd>Picks for week 1 are due when its first game kicks off; the games have not been set yet. Payment is due at the same time.</dd>
				<?php endif; ?>

				<dt>What is the entry fee?</dt>
				<dd>The buy-in is <?=$money($r['fee'])?> for a <?=$r['num_weeks']?> week season. There are a few winners every week<?=$r['finals_pot'] > 0 ? ' and a big winner at the end of the season' : ''?>.</dd>

				<dt>How much time does it require?</dt>
				<dd>As little as 1 minute per week. Or you could spend hours each week researching teams.</dd>

				<?php if ($r['is_active']): ?>
					<dt>Who can join?</dt>
					<dd>The league is open to all. Feel free to invite any friends or family who may be interested.</dd>

					<?php if ($r['pay_to_venmo']): ?>
						<dt>How do I join?</dt>
						<dd>Send payment via <a target="_blank" rel="noopener" href="https://venmo.com/<?=h(rawurlencode($r['pay_to_venmo']))?>">Venmo</a> (<code>@<?=h($r['pay_to_venmo'])?></code>)<?=$r['pay_to_name'] ? ' to ' . h($r['pay_to_name']) : ''?>. Once payment is received, we will activate your account for the season.</dd>
					<?php endif; ?>
				<?php endif; ?>
			</dl>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Description</h4>
		<div class="card-body">
			<p>The season will consist of <?=$r['num_regular_weeks']?> weeks of picks, in which you will pick <?=$r['games_per_week']?> games and rank them by order of confidence.</p>
			<?php if ($r['is_cfp']): ?>
				<p>All bets will relate to the college football playoff games of the week, chosen by the league commissioner.</p>
			<?php else: ?>
				<p>The games will be the half NFL and half college football games of the week, chosen by the league commissioner.</p>
			<?php endif; ?>
			<?php if ($r['num_playoff_weeks']): ?>
				<p class="mb-0">The season will be followed by a <?=$r['num_playoff_weeks']?> week playoff. All players will qualify for the first week of playoffs. <?=$r['advance'] ? 'The top ' . $r['advance'] . ' players' : 'The top players'?> will advance to the second week of playoffs.</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Fees and Winnings</h4>
		<div class="card-body">
			<dl class="mb-0">
				<dt>Entry</dt>
				<dd>The entry fee will be <?=$money($r['fee'])?> for the whole season.</dd>

				<dt>Weekly Winners</dt>
				<dd><?=$money($r['weekly_pot'])?> from each player will go toward each week's pot. Each week's payouts are listed under Weeks below.</dd>

				<?php if ($r['has_pools']): ?>
					<dt>Pool winners and runners-up</dt>
					<dd>To give more players a chance to win, the players will be broken up into smaller pools in some weeks and the winners of these pools will earn cash prizes.</dd>
				<?php endif; ?>

				<?php if ($r['finals_pot'] > 0): ?>
					<?php if ($r['num_playoff_weeks']): ?>
						<dt>Playoffs</dt>
						<dd><?=$money($r['finals_pot'])?> from each entry fee will be used to pay out the top players in the playoffs.</dd>
					<?php else: ?>
						<dt>Season Winner</dt>
						<dd><?=$money($r['finals_pot'])?> from each entry fee will be used to pay out the top players in the overall season standings.</dd>
					<?php endif; ?>
				<?php endif; ?>

				<dt>Payment</dt>
				<dd>Weekly winners are paid each week<?=$r['finals_pot'] > 0 ? ' and season winnings will be paid when the season is over' : ''?>.</dd>
			</dl>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Weeks</h4>
		<div class="card-body">
			<p>Each week has a format that sets its pools and payouts. A player receives the single largest payout they qualify for; an overall payout outranks a pool payout.</p>
			<div class="table-responsive">
				<table class="table table-striped table-sm">
					<thead>
						<tr class="text-center">
							<th>Week</th>
							<th>Format</th>
							<th>Pools</th>
							<th>Payouts</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$weeks_by_num = [];
						foreach ($season->weeks as $week) {
							$weeks_by_num[(int) $week->week_num] = $week;
						}
						foreach (range(1, $season->num_weeks + $season->playoff_weeks) as $week_num):
							$week = isset($weeks_by_num[$week_num]) ? $weeks_by_num[$week_num] : null;
							$format = $week ? $week->getFormat() : null;
							$payouts_html = $format ? WeekFormatPayouts::b($format) : '';
							?>
							<tr class="text-center">
								<td class="text-nowrap">Week <?=$week_num?></td>
								<?php if (!$format): ?>
									<td colspan="3">TBD</td>
								<?php else: ?>
									<td>
										<span class="fw-bold"><?=$week->getName()?></span>
										<?php if (strlen($week->getDescriptionLong())): ?>
											<br><em><?=$week->getDescriptionLong()?></em>
										<?php endif; ?>
									</td>
									<td><?=$week->getNumPools() ? $week->getNumPools() : '&mdash;'?></td>
									<td class="text-start">
										<?php if ($format->is_playoffs && $format->advance): ?>
											<div>Top <?=$format->advance?> advance</div>
										<?php endif; ?>
										<?php if (strlen($payouts_html)): ?>
											<?=$payouts_html?>
										<?php elseif (!($format->is_playoffs && $format->advance)): ?>
											&mdash;
										<?php endif; ?>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Game Selection Process</h4>
		<div class="card-body">
			<?php if (!$r['is_cfp']): ?>
				<p class="mb-0">The games will be chosen by the league commissioner and will consist of a mix of NCAA and NFL, as well as spread and points. All odds and lines will come from <a href="http://www.vegasinsider.com">Vegas Insider</a> on Tuesday before each week's games.</p>
			<?php else: ?>
				<p class="mb-0">All bets will relate to the College Football Playoff. The props and lines will be chosen by the league commissioner. All odds and lines will come from <a href="http://www.vegasinsider.com">Vegas Insider</a> or another reputable gaming site on Tuesday before each week's games.</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Points</h4>
		<div class="card-body">
			<p class="mb-0">A total of 55 points may be scored each week. You get to choose one game as your 10 point game, one as your 9 point game, one as your 8 pt game etc.<?php if ($r['num_zero_games']): ?> You will also choose <?=$r['num_zero_games']?> games to be worth 0 points.<?php endif; ?></p>
		</div>
	</div>

	<?php if ($r['num_playoff_weeks']): ?>
		<div class="card mb-3">
			<h4 class="card-header">Playoffs</h4>
			<div class="card-body">
				<p>There will be two rounds of playoffs: a <span class="fst-italic">Knock-Out Round</span> and a <span class="fst-italic">Money Round</span>.</p>

				<h5>The Knock-Out Round</h5>

				<p>All players qualify for the <span class="fst-italic">Knock-Out Round</span>.</p>
				<?php if ($r['gg_places']): ?>
					<p>The top <?=$r['gg_places']?> players from the Season Standings will receive freebies ("Guaranteed Games") in the Knock-Out round.</p>
					<?php if ($r['gg_season']): ?>
						<p class="text-muted small">This is the <?=h($r['gg_season']->name)?>'s grid; this season's guaranteed games are set when the regular season ends.</p>
					<?php endif; ?>

					<div class="table-responsive">
						<table class="table table-sm">
							<thead>
								<tr>
									<th rowspan="2">Regular Season Place</th>
									<th colspan="<?=sizeof($r['gg_slots'])?>">Guaranteed Games</th>
								</tr>
								<tr class="text-center">
									<?php foreach ($r['gg_slots'] as $pts): ?>
										<th><?=$pts?>pt</th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($r['gg_rows'] as $gr): ?>
									<tr class="text-center">
										<td class="text-start"><?=h($gr[0])?></td>
										<?php if ($gr[1] <= 0): ?>
											<td colspan="<?=sizeof($r['gg_slots'])?>" class="bg-secondary text-white">None</td>
										<?php else: ?>
											<?php foreach ($r['gg_slots'] as $i => $pts): ?>
												<?php if ($i < $gr[1]): ?>
													<td class="td-right"><i class="fas fa-check"></i></td>
												<?php else: ?>
													<td></td>
												<?php endif; ?>
											<?php endforeach; ?>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<h5>The Money Round</h5>

				<p><?=$r['advance'] ? 'Only the top ' . $r['advance'] . ' players' : 'Only the top players'?> from the Knock-Out Round qualify for the Money Round.</p>
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

			<?php if ($r['num_playoff_weeks']): ?>
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
