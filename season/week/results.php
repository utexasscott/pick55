<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\WeekPayouts;
use Pick55\WeekResults;
use Pick55\Models\Game;
use Pick55\Models\Pool;
use Pick55\Models\PoolsUsersLink;
use Pick55\Models\Week;
use Pick55\Models\WeekFormatPayout;
use Pick55\Snippets\GameOptionCell;
use Pick55\Snippets\Rank as RankSnippet;
use Pick55\Snippets\DateTimeDisplay;
use Pick55\Snippets\WeekFormatPayouts;

Auth::guard();

// Players shown by default in "Picks by Point Value" (plus the viewer).
const PICKS_TABLE_ROWS = 10;
// Picks per side shown for a decided game in "Game Results" (plus the viewer's).
const GAME_CELL_PICKS = 5;
// Lines on the expected-winnings chart (plus the viewer's).
const CHART_PLAYERS = 5;

$week = null;
if (get('id')) {
	$week = Week::find(get('id'));
}
if (!$week) {
	$app = App::get();
	$season = $app->getSeason();
	if (!$season) {
		redir('season/inactive.php');
	}
	$week = $season->getResultWeek();
	if (!$week) {
		redir('season/index.php?id=' . $season->id);
	}
}

$me = Auth::user();
if (!$week->season->hasPlayer($me->id)) {
	redir('season/unauthorized.php?id=' . $week->season->id);
}
if (!$week->canUserSeeResults($me->id)) {
	redir('season/index.php?id=' . $week->season->id);
}
$app->setSeason($week->season);

$page = new Page;
$page->setTitle('Results - Week ' . $week->week_num . ' - ' . $week->season->name);
$page->options['season_bar']['show'] = true;
$page->options['season_bar']['title'] = 'Week ' . $week->week_num . ' / Results';

$show_probabilities = false;
$show_auto_column = false;

$focus_user_ids = [];
$users = [];
foreach ($week->season->getPlayers() as $user) {
	$users[$user->id] = $user;
	$focus_user_ids[] = $user->id;
}
$all_user_ids = $focus_user_ids;

// Load pools
$pools = [];
$map_pool_id_to_user_ids = [];
$pool_by_user_id = [];
$my_pool_id = null;
$selected_pool_id = null;
$q = PoolsUsersLink::where('week_id', '=', $week->id)
	->whereIn('er_user_id', array_keys($users));
foreach ($q->cursor() as $link) {
	if (!array_key_exists($link->pool_id, $pools)) {
		$pools[$link->pool_id] = Pool::find($link->pool_id);
		$map_pool_id_to_user_ids[$link->pool_id] = [];
	}
	$map_pool_id_to_user_ids[$link->pool_id][] = $link->er_user_id;
	$pool_by_user_id[$link->er_user_id] = (int) $pools[$link->pool_id]->pool_num;
	if ($link->er_user_id == $me->id) {
		$my_pool_id = $link->pool_id;
	}
}
if (sizeof($pools)) {
	// Determine selected pool
	$selected_pool_id = get('pool', null);
	if ($selected_pool_id === null) {
		// Set selected pool to my pool
		$selected_pool_id = $my_pool_id;
	}
	if (!array_key_exists($selected_pool_id, $pools)) {
		$selected_pool_id = null;
	}
	// If a pool is selected, focus only the users in the pool
	if ($selected_pool_id) {
		$focus_user_ids = $map_pool_id_to_user_ids[$selected_pool_id];
	}
}

// Find "What Ifs" from query string
$what_ifs_by_game_id = [];
foreach ($_GET as $k => $v) {
	if (preg_match('/^g(\d+)$/', $k, $m) && in_array($v, ['1', '2'])) {
		$what_ifs_by_game_id[$m[1]] = $v;
	}
}

// The paying places and point threshold come from the week's format and
// depend on which pool is being viewed (null = the overall view).
$format = $week->getFormat();
$selected_pool_num = null;
if ($selected_pool_id) {
	$selected_pool_num = (int) $pools[$selected_pool_id]->pool_num;
}
$num_winners = $week->getNumWinners($selected_pool_num);
$threshold = $week->getMinScoreThreshold($selected_pool_num);

// Scores, ranks, win probabilities and payouts: computed once per change to
// the week's games/bets/format and cached (see docs/results-cache.md). The
// overall view (every player, with their pools) is what money is computed
// from; a pool view reads its own ranks and probabilities from a second,
// pool-only computation.
$overall = WeekResults::get($week, $all_user_ids, $what_ifs_by_game_id, null, ['pool_by_user_id' => $pool_by_user_id]);
if ($selected_pool_id) {
	$results = WeekResults::get($week, $focus_user_ids, $what_ifs_by_game_id, $selected_pool_num);
}
else {
	$results = $overall;
}
$stats_by_user_id = $results['stats_by_user_id'];
$bets_by_game_id = $results['bets_by_game_id'];
$unknown_game_ids = $results['unknown_game_ids'];
$num_unknowns = $results['num_unknowns'];
$num_predictions = $results['num_predictions'];
$show_auto_column = $results['show_auto_column'];
$games = [];
$last_game_at = null;
foreach (Game::hydrate($results['games']) as $game) {
	$games[$game->id] = $game;
	$at = $game->date . ' ' . $game->time;
	if ($last_game_at === null || $at > $last_game_at) {
		$last_game_at = $at;
	}
}
$has_payouts = $overall['has_payouts'];
$week_complete = $overall['num_unknowns'] == 0;

// Money: what the format pays on the current standing, and the mean over
// every outcome of the undecided games.
$payout_by_user_id = [];
$expected_by_user_id = [];
foreach ($overall['stats_by_user_id'] as $u_user_id => $stats) {
	$payout_by_user_id[substr($u_user_id, 1)] = $stats['payout'];
	$expected_by_user_id[substr($u_user_id, 1)] = $stats['expected_payout'];
}

// Winnings are recorded from the format once the week is complete (no admin
// entry any more; owner, 2026-09-25), then shown from football_week_winners.
if ($week_complete && $has_payouts) {
	if (WeekPayouts::syncWinners($week, $payout_by_user_id, $last_game_at)) {
		$week->unsetRelation('weekWinners');
	}
}
$winnings_by_user_id = [];
foreach ($week->weekWinners as $week_winner) {
	$user_id = (int) $week_winner->er_user_id;
	$winnings_by_user_id[$user_id] = (isset($winnings_by_user_id[$user_id]) ? $winnings_by_user_id[$user_id] : 0) + $week_winner->amount;
}
$show_winnings = sizeof($winnings_by_user_id) > 0;

$show_probabilities = $num_predictions || sizeof($what_ifs_by_game_id);
$show_expected = $has_payouts && !$week_complete && $num_predictions > 0;

// Expected winnings after each kickoff slot: the top finishers plus the viewer.
$timeline = null;
$chart_user_ids = [];
if ($has_payouts && $overall['num_unknowns'] < sizeof($games)) {
	$timeline = WeekResults::timeline($week, $all_user_ids, $pool_by_user_id);
	if (sizeof($timeline['labels']) < 2) {
		$timeline = null;
	}
	else {
		foreach ($overall['stats_by_user_id'] as $u_user_id => $stats) {
			if (sizeof($chart_user_ids) >= CHART_PLAYERS) {
				break;
			}
			$chart_user_ids[] = (int) substr($u_user_id, 1);
		}
		if (!in_array($me->id, $chart_user_ids)) {
			$chart_user_ids[] = (int) $me->id;
		}
	}
}

/**
 * "$140", "$47.50", "" for zero.
 */
$money = function ($amount) {
	$amount = (float) $amount;
	if ($amount < 0.005) {
		return '';
	}
	return '$' . WeekFormatPayout::money($amount);
};

if ($timeline) {
	$chart_colors = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
	$datasets = [];
	foreach ($chart_user_ids as $i => $user_id) {
		$mine = $user_id == $me->id;
		$datasets[] = [
			'label' => $users[$user_id]->getDisplayName() . ($mine ? ' (me)' : ''),
			'data' => isset($timeline['series'][$user_id]) ? $timeline['series'][$user_id] : [],
			'borderColor' => $mine ? '#222222' : $chart_colors[$i % sizeof($chart_colors)],
			'backgroundColor' => $mine ? '#222222' : $chart_colors[$i % sizeof($chart_colors)],
			'borderWidth' => $mine ? 3 : 2,
			'pointRadius' => 4,
			'pointHoverRadius' => 6,
			'tension' => 0.2,
		];
	}
	ob_start();
	?>
	<script>
	new Chart(document.getElementById('chart_timeline'), {
		type: 'line',
		data: {
			labels: <?=json_encode($timeline['labels'])?>,
			datasets: <?=json_encode($datasets)?>
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			interaction: { mode: 'index', intersect: false },
			plugins: {
				legend: { position: 'bottom' },
				tooltip: {
					callbacks: {
						label: function (ctx) {
							return ' ' + ctx.dataset.label + ': $' + ctx.parsed.y.toFixed(2);
						}
					}
				}
			},
			scales: {
				y: {
					beginAtZero: true,
					ticks: { callback: function (v) { return '$' + v; } },
					grid: { color: 'rgba(0, 0, 0, 0.08)' }
				},
				x: { grid: { display: false } }
			}
		}
	});
	</script>
	<?php
	$page->setScripts(ob_get_clean());
}

ob_start();
?>
<div class="container py-4">
	<?php if (sizeof($pools)): ?>
		<div class="card mb-3">
			<h4 class="card-header">Pools</h4>
			<div class="card-body">
				<ul class="nav nav-pills">
					<li class="nav-item">
						<a class="nav-link <?=!$selected_pool_id ? 'active' : ''?>" href="?id=<?=$week->id?>&pool=0">All Players</a>
					</li>
					<?php foreach ($pools as $pool): ?>
						<li class="nav-item">
							<a class="nav-link <?=$selected_pool_id == $pool->id ? 'active' : ''?>" href="?id=<?=$week->id?>&pool=<?=$pool->id?>"><?=$pool->name?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	<?php endif; ?>

	<?php if ($format): ?>
		<div class="card mb-3">
			<h4 class="card-header">Payouts</h4>
			<div class="card-body">
				<div class="fw-bold"><?=$week->getName()?></div>
				<?php if (strlen($week->getDescriptionLong())): ?>
					<div class="text-muted"><?=$week->getDescriptionLong()?></div>
				<?php endif; ?>
				<?=WeekFormatPayouts::b($format)?>
			</div>
		</div>
	<?php endif; ?>

	<?php if (sizeof($what_ifs_by_game_id)): ?>
		<p class="fst-italic">Results shown with <?=sizeof($what_ifs_by_game_id)?> "what if" game(s). <a href="?id=<?=$week->id?>">Reset what ifs</a>.</p>
	<?php endif; ?>

	<div class="card mb-3">
		<h4 class="card-header">Player Results</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped table-sortable">
				<thead>
					<tr class="text-center">
						<th colspan="100%">
							<?=$week->getName()?>
							<?php if (strlen($week->getDescriptionLong())): ?>
								<small class="fw-normal text-muted">&mdash; <?=$week->getDescriptionLong()?></small>
							<?php endif; ?>
						</th>
					</tr>
					<tr class="text-center">
						<th data-sort="int">#</th>
						<th data-sort="string">Player</th>
						<th data-sort="int" data-sort-default="desc">Pts</th>
						<th data-sort="int" data-sort-default="desc">&check;</th>
						<?php if ($show_probabilities): ?>
							<?php if ($num_winners == 1 || (sizeof($pools) > 1 && !$selected_pool_id)): ?>
								<th class="text-end" data-sort="float" data-sort-default="desc">1st</th>
							<?php else: ?>
								<?php if ($threshold): ?>
									<th class="text-end" data-sort="float" data-sort-default="desc">Top <?=$num_winners?> or ≥<?=$threshold?></th>
								<?php endif; ?>
								<?php if (!$threshold): ?>
									<?php if ($num_winners < 10): ?>
										<?php foreach (range(1, $num_winners) as $rank): ?>
											<th class="text-end" data-sort="float" data-sort-default="desc"><?=ordinal($rank)?></th>
										<?php endforeach; ?>
									<?php endif; ?>
								<?php endif; ?>
								<th class="text-end" data-sort="float" data-sort-default="desc">Top <?=$num_winners?></th>
							<?php endif; ?>
							<?php if ($threshold): ?>
								<th class="text-end" data-sort="float" data-sort-default="desc">≥<?=$threshold?></th>
								<th class="text-end" data-sort="float" data-sort-default="desc">1st</th>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ($show_expected): ?>
							<th class="text-end d-none d-md-table-cell" data-sort="float" data-sort-default="desc" title="Expected winnings: the average payout over every possible outcome of the remaining games">Exp $</th>
						<?php endif; ?>
						<?php if ($show_winnings): ?>
							<th class="text-end" data-sort="float" data-sort-default="desc">Winnings</th>
						<?php endif; ?>
						<?php if (Auth::isAdmin()): ?>
							<th>Poss</th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ($stats_by_user_id as $u_user_id => $stats):
						$user_id = substr($u_user_id, 1);
						?>
						<tr class="text-center <?=$user_id == $me->id ? 'bg-me' : ''?>">
							<td><?=RankSnippet::build(['rank' => $stats['rank']])?></td>
							<td class="text-start fw-bold text-nowrap"><?=$users[$user_id]->getDisplayName()?></td>
							<td><?=$stats['points']?></td>
							<td><?=$stats['right']?></td>
							<?php if ($show_probabilities): ?>
								<?php if ($num_winners == 1 || (sizeof($pools) > 1 && !$selected_pool_id)) : ?>
									<?php $val = round($stats['prediction_ranks_pct']['r1'], 1); ?>
									<td class="text-end col_winpct2" data-sort-value="<?=$val?>">
										<?php if ($val > 0) printf("%01.1f", $val); ?>
									</td>
								<?php else: ?>
									<?php foreach (range(1, $num_winners) as $rank): ?>
										<?php $val = round($stats['prediction_ranks_pct']['r' . $rank], 1); ?>
										<?php if ($num_winners < 10): ?>
											<?php if (!$threshold): ?>
												<td class="text-end col_placepct" data-sort-value="<?=$val?>">
													<?php if ($val > 0) printf("%01.1f", $val); ?>
												</td>
											<?php endif; ?>
										<?php endif; ?>
									<?php endforeach; ?>
									<?php if ($threshold): ?>
										<?php $val = round($stats['either_threshold_pct'], 1); ?>
										<td class="text-end col_toppct" data-sort-value="<?=$val?>">
											<?php if ($val > 0) printf("%01.1f", $val); ?>
										</td>
									<?php endif; ?>
									<?php $top_val = round(array_sum($stats['prediction_ranks_pct']), 1); ?>
									<td class="text-end col_toppct" data-sort-value="<?=$top_val?>">
										<?php if ($top_val > 0) printf("%01.1f", $top_val); ?>
									</td>
								<?php endif; ?>
								<?php if ($threshold): ?>
									<?php $val = round($stats['prediction_gte_threshold_pct'], 1); ?>
									<td class="text-end col_gte_pct" data-sort-value="<?=$val?>">
										<?php if ($val > 0) printf("%01.1f", $val); ?>
									</td>
									<?php $val = round($stats['prediction_ranks_pct']['r1'], 1); ?>
									<td class="text-end col_winpct" data-sort-value="<?=$val?>">
										<?php if ($val > 0) printf("%01.1f", $val); ?>
									</td>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ($show_expected): ?>
								<?php $val = isset($expected_by_user_id[$user_id]) ? $expected_by_user_id[$user_id] : 0; ?>
								<td class="text-end text-nowrap d-none d-md-table-cell" data-sort-value="<?=$val?>">
									<?php if ($val >= 0.005) printf("$%01.2f", $val); ?>
								</td>
							<?php endif; ?>
							<?php if ($show_winnings): ?>
								<?php $val = isset($winnings_by_user_id[$user_id]) ? $winnings_by_user_id[$user_id] : 0; ?>
								<td class="text-end text-nowrap fw-bold" data-sort-value="<?=$val?>"><?=$money($val)?></td>
							<?php endif; ?>
							<?php if (Auth::isAdmin()): ?>
								<td><?=$stats['possible']?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ($timeline): ?>
		<div class="card mb-3">
			<div class="card-header">
				<div class="d-flex justify-content-between align-items-baseline">
					<h4 class="mb-0">Expected Winnings</h4>
					<div class="fst-italic small text-muted">After each kickoff: the average payout over every outcome of the games still to play.</div>
				</div>
			</div>
			<div class="card-body">
				<div class="chart-timeline-wrap">
					<canvas id="chart_timeline"></canvas>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<?php
	$picks_hidden = 0;
	?>
	<div class="card mb-3">
		<h4 class="card-header">Picks by Point Value</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped table-sortable">
				<thead>
					<tr class="text-center">
						<th data-sort="string">Player</th>
						<?php foreach (range(1, 10) as $multiplier): ?>
							<th data-sort="int">x<?=$multiplier?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					$row_num = 0;
					foreach ($stats_by_user_id as $u_user_id => $stats):
						$user_id = substr($u_user_id, 1);
						$hidden = $row_num >= PICKS_TABLE_ROWS && $user_id != $me->id;
						if ($hidden) {
							$picks_hidden++;
						}
						$row_num++;
						?>
						<tr class="text-center fw-bold <?=$user_id == $me->id ? 'bg-me' : ''?> <?=$hidden ? 'd-none js-more-picks' : ''?>">
							<td class="text-start"><?=$users[$user_id]->getDisplayName()?></td>
							<?php foreach (range(1, 10) as $multiplier): ?>
								<?php if ($stats['by_multiplier'][$multiplier] == 1): ?>
									<td data-sort-value="2" class="td-right"><i class="fas fa-check"></i></td>
								<?php elseif ($stats['by_multiplier'][$multiplier] == 3): ?>
									<td data-sort-value="1" class="td-auto"><i class="far fa-dot-circle"></i></td>
								<?php elseif ($stats['by_multiplier'][$multiplier] == -1): ?>
									<td data-sort-value="3" class="td-wrong"><i class="fas fa-times"></i></td>
								<?php else: ?>
									<td data-sort-value="4">?</td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ($picks_hidden): ?>
			<div class="card-footer text-center">
				<a href="#" class="btn btn-sm btn-outline-secondary" data-toggle-more=".js-more-picks" data-text-alt="Show top <?=PICKS_TABLE_ROWS?> only">Show all <?=sizeof($stats_by_user_id)?> players</a>
			</div>
		<?php endif; ?>
	</div>

	<div class="card mb-3">
		<div class="card-header">
			<div class="d-flex justify-content-between">
				<h4>Game Results</h4>
				<div class="fst-italic">My picks are outlined.</div>
			</div>
		</div>
		<div class="table-responsive">
			<table class="table table-sm table-striped">
				<tbody>
					<?php foreach ($games as $game): ?>
						<?php
						$decided = $game->correct_option != '0';
						$collapse_after = $decided ? GAME_CELL_PICKS : null;
						$collapse_class = 'js-more-g' . $game->id;
						$hidden = $decided ? GameOptionCell::hiddenCount($bets_by_game_id[$game->id], $focus_user_ids, $me->id, GAME_CELL_PICKS) : 0;
						$cell_params = [
							'game' => $game,
							'user_ids' => $focus_user_ids,
							'my_user_id' => $me->id,
							'players' => $users,
							'picks' => $bets_by_game_id[$game->id],
							'collapse_after' => $collapse_after,
							'collapse_class' => $collapse_class,
						];
						?>
						<tr>
							<td>
								<div class="fw-bold"><?=$game->title?></div>
								<div class="text-muted"><?=DateTimeDisplay::b($game->date . ' ' . $game->time)?></div>
								<div class="live-score" data-game-id="<?=$game->id?>"></div>
								<?php if ($hidden): ?>
									<div class="mt-2"><a href="#" class="btn btn-xs btn-outline-secondary" data-toggle-more=".<?=$collapse_class?>" data-text-alt="Show fewer picks">Show all picks (+<?=$hidden?>)</a></div>
								<?php endif; ?>
							</td>

							<?php
							print GameOptionCell::build($cell_params + [
								'option' => '1',
								'show_what_if' => (bool) $num_unknowns,
								'what_if_option' => isset($what_ifs_by_game_id[$game->id]) ? $what_ifs_by_game_id[$game->id] : null,
							]);
							print GameOptionCell::build($cell_params + [
								'option' => '2',
								'show_what_if' => (bool) $num_unknowns,
								'what_if_option' => isset($what_ifs_by_game_id[$game->id]) ? $what_ifs_by_game_id[$game->id] : null,
							]);
							if ($show_auto_column) {
								print GameOptionCell::build($cell_params + [
									'option' => '3',
								]);
							}
							?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
