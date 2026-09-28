<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Models\WeekFormatPayout;
use Pick55\R\Icons;
use Pick55\R\Shell;
use Pick55\SeasonRules;
use Pick55\Snippets\WeekFormatPayouts;

// Public: no guard. Any season's rules (?id=), from SeasonRules (docs/season-rules.md).
$shell = new Shell;
$shell->setNav('none');
$shell->setPageClass('page-rules page-narrow');
$shell->addStyle('css/pages/rules.css');

$season = SeasonRules::resolveSeason();
if (!$season) {
	$shell->setTitle('Rules');
	$shell->setContent(Shell::empty(
		'The rules return with the next season',
		'There is no season yet, so there is no schedule of weeks and payouts to show. Check back in late summer.',
		[['label' => 'All-time stats', 'href' => $shell->link('stats/index.php'), 'kind' => 'primary']],
		'book-open'
	));
	print $shell->render();
	exit();
}

$r = SeasonRules::get($season);
$money = function ($amount) {
	return '$' . WeekFormatPayout::money($amount);
};

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
if ($r['num_playoff_weeks']) {
	$sections['playoffs'] = 'Playoffs';
}
$sections['ties'] = 'Tiebreakers';

$weeks_by_num = [];
foreach ($season->weeks as $week) {
	$weeks_by_num[(int) $week->week_num] = $week;
}

// The Knock-Out Round's guaranteed games: regular-season place => how many of
// the week's slots (the 0-point games, then 1 ... 10 points) are guaranteed.
$gg_slots = $r['gg_slots'];
$gg_rows = $r['gg_rows'];

/**
 * @param int $n  guaranteed slots
 * @return string "the four 0-point games and the 1 through 8 point values"
 */
$gg_summary = function ($n) use ($gg_slots, $r) {
	if ($n <= 0) {
		return 'none';
	}
	$words = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six'];
	$z = $r['num_zero_games'];
	$zeros = $z ? 'the ' . (isset($words[$z]) ? $words[$z] : $z) . ' 0-point game' . ($z == 1 ? '' : 's') : '';
	$top = $gg_slots[$n - 1];
	$values = $top > 0 ? 'the ' . ($top > 1 ? '1 through ' . $top . ' point' : '1-point') . ' values' : '';
	return implode(' and ', array_filter([$zeros, $values]));
};

ob_start();
?>
<header class="rules-head enter">
	<span class="eyebrow">Rules &middot; <?=h($season->name)?></span>
	<h1 class="page-title">How Pick55 works</h1>
	<p class="page-sub">Pick <?=(int) $r['games_per_week']?> games a week and rank them by confidence. 55 points is a perfect week.</p>
	<form class="season-pick" method="get" action="<?=h($shell->link('rules.php'))?>" data-season-pick>
		<label class="sr-only" for="season-select">Season</label>
		<select class="select" id="season-select" name="id">
			<?php foreach (SeasonRules::seasonList() as $s): ?>
				<option value="<?=(int) $s->id?>"<?=(int) $s->id === (int) $season->id ? ' selected' : ''?>><?=h($s->name)?></option>
			<?php endforeach; ?>
		</select>
		<button class="btn btn-sm btn-ghost season-go" type="submit">Go</button>
	</form>
	<?php if (!$r['is_active']): ?>
		<p class="rules-past small muted">These are the rules the <?=h($season->name)?> was played under.</p>
	<?php endif; ?>
	<dl class="rules-facts">
		<div><dt>Entry</dt><dd><?=h($money($r['fee']))?></dd></div>
		<div><dt>Weeks</dt><dd><?=(int) $r['num_weeks']?></dd></div>
		<div><dt>Games a week</dt><dd><?=(int) $r['games_per_week']?></dd></div>
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
					<?php if ($r['starts_at']): ?>
						<dd>Picks for week 1 <?=$r['has_started'] ? 'were' : 'are'?> due <strong><?=h(date('l, F j, Y \a\t g:i A', strtotime($r['starts_at'])))?></strong>, when its first game kicks off.<?php if (!$r['has_started']): ?> Payment is due at the same time.<?php endif; ?></dd>
					<?php else: ?>
						<dd>Picks for week 1 are due when its first game kicks off; the games have not been set yet. Payment is due at the same time.</dd>
					<?php endif; ?>
				</div>
				<div class="faq-item">
					<dt>What is the entry fee?</dt>
					<dd>The buy-in is <?=h($money($r['fee']))?> for a <?=(int) $r['num_weeks']?> week season. There are a few winners every week<?=$r['finals_pot'] > 0 ? ' and a big winner at the end of the season' : ''?>.</dd>
				</div>
				<div class="faq-item">
					<dt>How much time does it require?</dt>
					<dd>As little as 1 minute per week. Or you could spend hours each week researching teams.</dd>
				</div>
				<?php if ($r['is_active']): ?>
					<div class="faq-item">
						<dt>Who can join?</dt>
						<dd>The league is open to all. Feel free to invite any friends or family who may be interested.</dd>
					</div>
					<?php if ($r['pay_to_venmo']): ?>
						<div class="faq-item">
							<dt>How do I join?</dt>
							<dd>Send payment via <a href="https://venmo.com/<?=h(rawurlencode($r['pay_to_venmo']))?>" target="_blank" rel="noopener">Venmo</a> (<code>@<?=h($r['pay_to_venmo'])?></code>)<?=$r['pay_to_name'] ? ' to ' . h($r['pay_to_name']) : ''?>. Once payment is received, we will activate your account for the season.</dd>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</dl>
		</section>

		<section class="rules-section" id="about" aria-labelledby="about-h">
			<h2 id="about-h">How it works</h2>
			<p>The season will consist of <?=(int) $r['num_regular_weeks']?> weeks of picks, in which you will pick <?=(int) $r['games_per_week']?> games and rank them by order of confidence.</p>
			<?php if ($r['is_cfp']): ?>
				<p>All bets will relate to the college football playoff games of the week, chosen by the league commissioner.</p>
			<?php else: ?>
				<p>The games will be the half NFL and half college football games of the week, chosen by the league commissioner.</p>
			<?php endif; ?>
			<?php if ($r['num_playoff_weeks']): ?>
				<p>The season will be followed by a <?=(int) $r['num_playoff_weeks']?> week playoff. All players will qualify for the first week of playoffs. <?=$r['advance'] ? 'The top ' . (int) $r['advance'] . ' players' : 'The top players'?> will advance to the second week of playoffs.</p>
			<?php endif; ?>
		</section>

		<section class="rules-section" id="money" aria-labelledby="money-h">
			<h2 id="money-h">Fees &amp; winnings</h2>
			<dl class="terms">
				<div><dt>Entry</dt><dd>The entry fee will be <?=h($money($r['fee']))?> for the whole season.</dd></div>
				<div>
					<dt>Weekly winners</dt>
					<dd><?=h($money($r['weekly_pot']))?> from each player will go toward each week's pot. Each week's payouts are listed under <a href="#weeks">Weeks</a>.</dd>
				</div>
				<?php if ($r['has_pools']): ?>
					<div><dt>Pool winners and runners-up</dt><dd>To give more players a chance to win, the players will be broken up into smaller pools in some weeks and the winners of these pools will earn cash prizes.</dd></div>
				<?php endif; ?>
				<?php if ($r['finals_pot'] > 0): ?>
					<?php if ($r['num_playoff_weeks']): ?>
						<div><dt>Playoffs</dt><dd><?=h($money($r['finals_pot']))?> from each entry fee will be used to pay out the top players in the playoffs.</dd></div>
					<?php else: ?>
						<div><dt>Season winner</dt><dd><?=h($money($r['finals_pot']))?> from each entry fee will be used to pay out the top players in the overall season standings.</dd></div>
					<?php endif; ?>
				<?php endif; ?>
				<div><dt>Payment</dt><dd>Weekly winners are paid each week<?=$r['finals_pot'] > 0 ? ' and season winnings will be paid when the season is over' : ''?>.</dd></div>
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
			<?php if (!$r['is_cfp']): ?>
				<p>The games will be chosen by the league commissioner and will consist of a mix of NCAA and NFL, as well as spread and points. All odds and lines will come from <a href="http://www.vegasinsider.com" target="_blank" rel="noopener">Vegas Insider</a> on Tuesday before each week's games.</p>
			<?php else: ?>
				<p>All bets will relate to the College Football Playoff. The props and lines will be chosen by the league commissioner. All odds and lines will come from <a href="http://www.vegasinsider.com" target="_blank" rel="noopener">Vegas Insider</a> or another reputable gaming site on Tuesday before each week's games.</p>
			<?php endif; ?>
		</section>

		<section class="rules-section" id="points" aria-labelledby="points-h">
			<h2 id="points-h">Points</h2>
			<p>A total of 55 points may be scored each week. You get to choose one game as your 10 point game, one as your 9 point game, one as your 8 pt game etc.<?php if ($r['num_zero_games']): ?> You will also choose <?=(int) $r['num_zero_games']?> games to be worth 0 points.<?php endif; ?></p>
			<div class="points-rail" aria-hidden="true">
				<?php foreach (range(10, 1) as $v): ?>
					<span style="--v: <?=$v?>"><?=$v?></span>
				<?php endforeach; ?>
				<?php for ($z = 0; $z < $r['num_zero_games']; $z++): ?>
					<span class="is-zero">0</span>
				<?php endfor; ?>
			</div>
			<p class="faint small">10 + 9 + 8 + &hellip; + 1 = 55.</p>
		</section>

		<?php if ($r['num_playoff_weeks']): ?>
			<section class="rules-section" id="playoffs" aria-labelledby="playoffs-h">
				<h2 id="playoffs-h">Playoffs</h2>
				<p>There will be two rounds of playoffs: a <em>Knock-Out Round</em> and a <em>Money Round</em>.</p>

				<h3>The Knock-Out Round</h3>
				<p>All players qualify for the <em>Knock-Out Round</em>.</p>
				<?php if ($r['gg_places']): ?>
				<p>The top <?=(int) $r['gg_places']?> players from the Season Standings will receive freebies (&ldquo;Guaranteed Games&rdquo;) in the Knock-Out round.</p>
				<?php if ($r['gg_season']): ?>
					<p class="faint small">This is the <?=h($r['gg_season']->name)?>&rsquo;s grid; this season&rsquo;s guaranteed games are set when the regular season ends.</p>
				<?php endif; ?>

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
					<?php
					$gg_first = $gg_rows[0];
					$gg_last = $gg_rows[sizeof($gg_rows) - 2];
					?>
					<figcaption class="faint small">Columns are the point values of the Knock-Out Round's <?=sizeof($gg_slots)?> game slots; a filled cell is a guaranteed correct game at that value. <?=h($gg_first[0])?> place is guaranteed <?=h($gg_summary($gg_first[1]))?><?php if ($gg_last !== $gg_first): ?>; <?=h($gg_last[0])?>, <?=h($gg_summary($gg_last[1]))?><?php endif; ?>.</figcaption>
				</figure>
				<?php endif; ?>

				<h3>The Money Round</h3>
				<p><?=$r['advance'] ? 'Only the top ' . (int) $r['advance'] . ' players' : 'Only the top players'?> from the Knock-Out Round qualify for the Money Round.</p>
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
			<?php if ($r['num_playoff_weeks']): ?>
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
