<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Models\Season;
use Pick55\Models\WeekFormatPayout;
use Pick55\R\Icons;
use Pick55\R\Shell;
use Pick55\Snippets\WeekFormatPayouts;

// Public: no guard. The owner's copy for the season, as on the classic rules.php.
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
$venmo_handle = 'Brad-North-2';
$venmo_name = 'Brad';

$shell = new Shell;
$shell->setNav('none');
$shell->setPageClass('page-rules page-narrow');
$shell->addStyle('css/pages/rules.css');

$season = Season::getActive();
if (!$season) {
	$shell->setTitle('Rules');
	$shell->setContent(Shell::empty(
		'The rules return with the next season',
		'There is no active season right now, so there is no schedule of weeks and payouts to show. Check back in late summer.',
		[['label' => 'All-time stats', 'href' => $shell->link('stats/index.php'), 'kind' => 'primary']],
		'book-open'
	));
	print $shell->render();
	exit();
}

$shell->setTitle('Rules');
$shell->addScript('js/pages/rules.js');
$shell->setModule('rules');

$sections = [
	'faq' => 'FAQ',
	'about' => 'How it works',
	'money' => 'Fees & winnings',
	'weeks' => 'Weeks',
	'games' => 'Game selection',
	'points' => 'Points',
];
if ($num_playoff_weeks) {
	$sections['playoffs'] = 'Playoffs';
}
$sections['ties'] = 'Tiebreakers';

$weeks_by_num = [];
foreach ($season->weeks as $week) {
	$weeks_by_num[(int) $week->week_num] = $week;
}

// The Knock-Out Round's guaranteed games: regular-season place => how many of
// the twelve slots (0, 0, 1 ... 10 points) are guaranteed, from the classic grid.
$gg_slots = [0, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
$gg_rows = [
	['1st', 12],
	['2nd', 10],
	['3rd', 10],
	['4th', 9],
	['5th', 9],
	['6th', 8],
	['7th', 8],
	['8th–10th', 7],
	['11th–20th', 6],
	['21st and lower', 0],
];

/**
 * @param int $n  guaranteed slots
 * @return string "two 0-point games and 1 through 8 points"
 */
$gg_summary = function ($n) use ($gg_slots) {
	if ($n <= 0) {
		return 'none';
	}
	$top = $gg_slots[$n - 1];
	return 'two 0-point games' . ($top > 0 ? ' and the ' . ($top > 1 ? '1 through ' . $top . ' point' : '1-point') . ' values' : '');
};

ob_start();
?>
<header class="rules-head enter">
	<span class="eyebrow">Rules &middot; <?=h($season->name)?></span>
	<h1 class="page-title">How Pick55 works</h1>
	<p class="page-sub">Pick <?=(int) $num_games_per_week?> games a week and rank them by confidence. 55 points is a perfect week.</p>
	<dl class="rules-facts">
		<div><dt>Entry</dt><dd>$<?=(int) $fee?></dd></div>
		<div><dt>Weeks</dt><dd><?=(int) ($num_regular_weeks + $num_playoff_weeks)?></dd></div>
		<div><dt>Games a week</dt><dd><?=(int) $num_games_per_week?></dd></div>
		<div><dt>Perfect week</dt><dd>55</dd></div>
	</dl>
</header>

<div class="rules-layout">
	<nav class="rules-toc" aria-label="On this page" data-toc>
		<ol>
			<?php foreach ($sections as $id => $label): ?>
				<li><a href="#<?=h($id)?>" data-toc-link="<?=h($id)?>"><?=h($label)?></a></li>
			<?php endforeach; ?>
		</ol>
	</nav>

	<article class="rules-doc">
		<section class="rules-section" id="faq" aria-labelledby="faq-h">
			<h2 id="faq-h">FAQ</h2>
			<dl class="faq">
				<div class="faq-item">
					<dt>When does it start?</dt>
					<dd>Picks for week 1 are due <strong><?=h(date('l, F j, Y \a\t g:i A', strtotime($picks_due)))?></strong>. Payment is due at the same time.</dd>
				</div>
				<div class="faq-item">
					<dt>What is the entry fee?</dt>
					<dd>The buy-in is $<?=(int) $fee?> for a <?=(int) ($num_regular_weeks + $num_playoff_weeks)?> week season. There are a few winners every week and a big winner at the end of the season.</dd>
				</div>
				<div class="faq-item">
					<dt>How much time does it require?</dt>
					<dd>As little as 1 minute per week. Or you could spend hours each week researching teams.</dd>
				</div>
				<div class="faq-item">
					<dt>Who can join?</dt>
					<dd>The league is open to all. Feel free to invite any friends or family who may be interested.</dd>
				</div>
				<div class="faq-item">
					<dt>How do I join?</dt>
					<dd>Send payment via <a href="https://venmo.com/<?=h(rawurlencode($venmo_handle))?>" target="_blank" rel="noopener">Venmo</a> (<code>@<?=h($venmo_handle)?></code>) to <?=h($venmo_name)?>. Once payment is received, we will activate your account for the season.</dd>
				</div>
			</dl>
		</section>

		<section class="rules-section" id="about" aria-labelledby="about-h">
			<h2 id="about-h">How it works</h2>
			<p>The season will consist of <?=(int) $num_regular_weeks?> weeks of picks, in which you will pick <?=(int) $num_games_per_week?> games and rank them by order of confidence.</p>
			<?php if ($is_cfp): ?>
				<p>All bets will relate to the college football playoff games of the week, chosen by the league commissioner.</p>
			<?php else: ?>
				<p>The games will be the half NFL and half college football games of the week, chosen by the league commissioner.</p>
			<?php endif; ?>
			<?php if ($num_playoff_weeks): ?>
				<p>The season will be followed by a <?=(int) $num_playoff_weeks?> week playoff. All players will qualify for the first week of playoffs. Ten to fourteen players will advance to the second week of playoffs.</p>
			<?php endif; ?>
		</section>

		<section class="rules-section" id="money" aria-labelledby="money-h">
			<h2 id="money-h">Fees &amp; winnings</h2>
			<dl class="terms">
				<div><dt>Entry</dt><dd>The entry fee will be $<?=(int) $fee?> for the whole season.</dd></div>
				<div>
					<dt>Weekly winners</dt>
					<dd>$<?=(int) $weekly_pot_per_player?> from each player will go toward the weekly pot.<?php if ($weekly_winner): ?> The winner of each week will be paid $<?=(int) $weekly_winner?>.<?php endif; ?></dd>
				</div>
				<?php if ($has_pools): ?>
					<div><dt>Pool winners and runners-up</dt><dd>To give more players a chance to win, the players will be broken up into smaller pools each week and the winners of these pools will earn cash prizes.</dd></div>
				<?php endif; ?>
				<?php if ($num_playoff_weeks): ?>
					<div><dt>Playoffs</dt><dd>$<?=(int) $playoff_pot_per_player?> from each entry fee will be used to pay out the top players in the playoffs.</dd></div>
				<?php else: ?>
					<div><dt>Season winner</dt><dd>$<?=(int) $playoff_pot_per_player?> from each entry fee will be used to pay out the top players in the overall season standings.</dd></div>
				<?php endif; ?>
				<div><dt>Payment</dt><dd>Weekly winners are paid each week and season winnings will be paid when the season is over.</dd></div>
			</dl>
		</section>

		<section class="rules-section" id="weeks" aria-labelledby="weeks-h">
			<h2 id="weeks-h">Weeks</h2>
			<p>Each week has a format that sets its pools and payouts. A player receives the single largest payout they qualify for; an overall payout outranks a pool payout.</p>
			<ol class="week-list">
				<?php foreach (range(1, $season->num_weeks + $season->playoff_weeks) as $week_num): ?>
					<?php
					$week = isset($weeks_by_num[$week_num]) ? $weeks_by_num[$week_num] : null;
					$format = $week ? $week->getFormat() : null;
					$groups = $format ? WeekFormatPayouts::groups($format) : [];
					?>
					<li class="week-row<?=$format && $format->is_playoffs ? ' is-playoffs' : ''?><?=!$format ? ' is-tbd' : ''?>">
						<span class="week-num" aria-hidden="true"><?=(int) $week_num?></span>
						<div class="week-body">
							<div class="week-title">
								<span class="sr-only">Week <?=(int) $week_num?>:</span>
								<?php if (!$format): ?>
									<span class="faint">To be decided</span>
								<?php else: ?>
									<strong><?=h($week->getName())?></strong>
									<?php if ($format->is_playoffs): ?>
										<span class="pill pill-accent">Playoffs</span>
									<?php endif; ?>
									<?php if ($week->getNumPools()): ?>
										<span class="pill pill-outline"><?=Icons::svg('users')?><?=(int) $week->getNumPools()?> pools</span>
									<?php endif; ?>
								<?php endif; ?>
							</div>
							<?php if ($format && strlen($week->getDescriptionLong())): ?>
								<p class="week-desc"><?=h($week->getDescriptionLong())?></p>
							<?php endif; ?>
							<?php if ($format && $format->is_playoffs && $format->advance): ?>
								<div class="payout-line"><span class="payout-label">Advance</span><span class="payout">Top <b><?=(int) $format->advance?></b></span></div>
							<?php endif; ?>
							<?php foreach ($groups as $label => $rows): ?>
								<div class="payout-line">
									<span class="payout-label"><?=h($label)?></span>
									<span class="payout-items">
										<?php foreach ($rows as $row): ?>
											<span class="payout"><?=h($row->getPlaceLabel())?> <b><?=h($row->getAmountLabel())?></b></span>
										<?php endforeach; ?>
									</span>
								</div>
							<?php endforeach; ?>
							<?php if ($format && sizeof($groups) && $format->total_payout > 0): ?>
								<div class="payout-total">Total paid <b>$<?=h(WeekFormatPayout::money($format->total_payout))?></b></div>
							<?php elseif ($format && !sizeof($groups) && !($format->is_playoffs && $format->advance)): ?>
								<div class="payout-line faint small">No payouts</div>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>

		<section class="rules-section" id="games" aria-labelledby="games-h">
			<h2 id="games-h">Game selection</h2>
			<?php if (!$is_cfp): ?>
				<p>The games will be chosen by the league commissioner and will consist of a mix of NCAA and NFL, as well as spread and points. All odds and lines will come from <a href="http://www.vegasinsider.com" target="_blank" rel="noopener">Vegas Insider</a> on Tuesday before each week's games.</p>
			<?php else: ?>
				<p>All bets will relate to the College Football Playoff. The props and lines will be chosen by the league commissioner. All odds and lines will come from <a href="http://www.vegasinsider.com" target="_blank" rel="noopener">Vegas Insider</a> or another reputable gaming site on Tuesday before each week's games.</p>
			<?php endif; ?>
		</section>

		<section class="rules-section" id="points" aria-labelledby="points-h">
			<h2 id="points-h">Points</h2>
			<p>A total of 55 points may be scored each week. You get to choose one game as your 10 point game, one as your 9 point game, one as your 8 pt game etc. You will also choose <?=(int) ($num_games_per_week - 10)?> games to be worth 0 points.</p>
			<div class="points-rail" aria-hidden="true">
				<?php foreach (range(10, 1) as $v): ?>
					<span style="--v: <?=$v?>"><?=$v?></span>
				<?php endforeach; ?>
				<?php foreach (range(1, $num_games_per_week - 10) as $z): ?>
					<span class="is-zero">0</span>
				<?php endforeach; ?>
			</div>
			<p class="faint small">10 + 9 + 8 + &hellip; + 1 = 55.</p>
		</section>

		<?php if ($num_playoff_weeks): ?>
			<section class="rules-section" id="playoffs" aria-labelledby="playoffs-h">
				<h2 id="playoffs-h">Playoffs</h2>
				<p>There will be two rounds of playoffs: a <em>Knock-Out Round</em> and a <em>Money Round</em>.</p>

				<h3>The Knock-Out Round</h3>
				<p>All players qualify for the <em>Knock-Out Round</em>.</p>
				<p>The top 12 players from the Season Standings will receive freebies (&ldquo;Guaranteed Games&rdquo;) in the Knock-Out round.</p>

				<figure class="gg">
					<table class="gg-grid">
						<caption class="sr-only">Guaranteed games by regular-season place</caption>
						<thead>
							<tr>
								<th scope="col" class="gg-place">Place</th>
								<?php foreach ($gg_slots as $pts): ?>
									<th scope="col"><span aria-hidden="true"><?=(int) $pts?></span><span class="sr-only"><?=(int) $pts?> points</span></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($gg_rows as $gr): ?>
								<tr>
									<th scope="row" class="gg-place"><?=h($gr[0])?></th>
									<?php if ($gr[1] <= 0): ?>
										<td colspan="<?=sizeof($gg_slots)?>" class="gg-none">None</td>
									<?php else: ?>
										<?php foreach ($gg_slots as $i => $pts): ?>
											<?php $on = $i < $gr[1]; ?>
											<td class="<?=$on ? 'is-on' : ''?>" style="--v: <?=(int) $pts?>"><span class="sr-only"><?=$on ? 'Guaranteed' : 'Not guaranteed'?></span></td>
										<?php endforeach; ?>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<figcaption class="faint small">Columns are the point values of the Knock-Out Round's twelve guaranteed slots; a filled cell is a guaranteed correct game at that value. 1st place is guaranteed <?=h($gg_summary(12))?>; 11th&ndash;20th, <?=h($gg_summary(6))?>.</figcaption>
				</figure>

				<h3>The Money Round</h3>
				<p>Only the top 20 players from the Knock-Out Round qualify for the Money Round.</p>
				<p>There will be no seeding in this round. None of the players' previous points will be considered for any reason. Normal tie-breaker rules apply.</p>
			</section>
		<?php endif; ?>

		<section class="rules-section" id="ties" aria-labelledby="ties-h">
			<h2 id="ties-h">Tiebreakers</h2>
			<h3>Weekly</h3>
			<p>In the event of a points tie after a week's picks (and each round of the playoffs) we will use the following steps to break the tie:</p>
			<ol class="steps">
				<li>The player with the most correctly guessed picks will be the winner.</li>
				<li>If there is still a tie, the player with the highest confidence correct pick will be the winner.</li>
				<li>If two or more players are still tied, the player with the second highest confidence correct wins.</li>
				<li>Continue in this fashion until the tie is broken. If a tie is not broken, the pot will be split.</li>
			</ol>
			<?php if ($num_playoff_weeks): ?>
				<h3>Playoff seeding</h3>
				<p>The first tiebreaker for playoff seeding is total points for the season. In the event of a tie, the player with the most correct picks for the season will be the winner. If two or more players are still tied, the player with the most correct 10pt picks will be the winner. If still tied, the player with the most correct 9pt picks, then 8pt picks, etc. will be determined as the winner.</p>
			<?php else: ?>
				<h3>Final standings</h3>
				<p>The first tiebreaker for final standings rankings is total points for the season. In the event of a tie, the player with the most correct picks for the season will be the winner. If two or more players are still tied, the player with the most correct 10pt picks will be the winner. If still tied, the player with the most correct 9pt picks, then 8pt picks, etc. will be determined as the winner.</p>
			<?php endif; ?>
		</section>
	</article>
</div>
<?php
$shell->setContent(ob_get_clean());
print $shell->render();
