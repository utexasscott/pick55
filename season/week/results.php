<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\WeekResults;
use Pick55\Models\Game;
use Pick55\Models\Pool;
use Pick55\Models\PoolsUsersLink;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\Week;
use Pick55\Models\Season;
use Pick55\Models\WeekWinner;
use Pick55\Snippets\GameOptionCell;
use Pick55\Snippets\Rank as RankSnippet;
use Pick55\Snippets\DateTimeDisplay;

Auth::guard();

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

if (is_post()) {
	try {
		switch (post('action')) {
			case 'save':
				foreach ($focus_user_ids as $user_id) {
					$winnings = post('winnings-' . $user_id);
					if ($winnings === null || $winnings === '') {
						continue;
					}
					$winnings = (float) $winnings;
					$week_winner = WeekWinner::where('week_id', '=', $week->id)
						->where('er_user_id', '=', $user_id)
						->first();
					if ($week_winner) {
						if ($winnings > 0) {
							$week_winner->amount = $winnings;
							$week_winner->save();
						}
						else {
							$week_winner->delete();
						}
					}
					elseif ($winnings > 0) {
						WeekWinner::create([
							'week_id' => $week->id,
							'er_user_id' => $user_id,
							'amount' => $winnings,
						]);
					}
				}
				Alert::success("Saved changes.");
				break;
		}
	}
	catch (Exception $e) {
		Alert::error($e->getMessage());
	}
	redir();
}

// Load pools
$pools = [];
$map_pool_id_to_user_ids = [];
$my_pool_id = null;
$q = PoolsUsersLink::where('week_id', '=', $week->id)
	->whereIn('er_user_id', array_keys($users));
foreach ($q->cursor() as $link) {
	if (!array_key_exists($link->pool_id, $pools)) {
		$pools[$link->pool_id] = Pool::find($link->pool_id);
		$map_pool_id_to_user_ids[$link->pool_id] = [];
	}
	$map_pool_id_to_user_ids[$link->pool_id][] = $link->er_user_id;
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

// Scores, ranks and win probabilities: computed once per change to the
// week's games/bets/settings and cached (see docs/results-cache.md).
$results = WeekResults::get($week, $focus_user_ids, $what_ifs_by_game_id);
$stats_by_user_id = $results['stats_by_user_id'];
$bets_by_game_id = $results['bets_by_game_id'];
$unknown_game_ids = $results['unknown_game_ids'];
$num_unknowns = $results['num_unknowns'];
$num_predictions = $results['num_predictions'];
$show_auto_column = $results['show_auto_column'];
$games = [];
foreach (Game::hydrate($results['games']) as $game) {
	$games[$game->id] = $game;
}

// Winnings are edited on this page, so they stay outside the cache.
foreach ($stats_by_user_id as $u_user_id => $stats) {
	$stats_by_user_id[$u_user_id]['winnings'] = 0;
}
if ($week->weekWinners->count()) {
	foreach ($week->weekWinners as $week_winner) {
		if (isset($stats_by_user_id['u' . $week_winner->er_user_id])) {
			$stats_by_user_id['u' . $week_winner->er_user_id]['winnings'] += $week_winner->amount;
		}
	}
}

$show_probabilities = $num_predictions || sizeof($what_ifs_by_game_id);

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

	<?php if (sizeof($what_ifs_by_game_id)): ?>
		<p class="fst-italic">Results shown with <?=sizeof($what_ifs_by_game_id)?> "what if" game(s). <a href="?id=<?=$week->id?>">Reset what ifs</a>.</p>
	<?php endif; ?>

	<div class="card mb-3">
		<form method="post" action="">
			<input type="hidden" name="action" value="save">
			<h4 class="card-header">Player Results</h4>
			<div class="table-responsive">
				<table class="table table-sm table-striped table-sortable">
					<thead>
						<tr class="text-center">
							<th colspan="100%"><?=$week->description_long?></th>
						</tr>
						<tr class="text-center">
							<th data-sort="int">#</th>
							<th data-sort="string">Player</th>
							<th data-sort="int" data-sort-default="desc">Pts</th>
							<th data-sort="int" data-sort-default="desc">&check;</th>
							<?php if ($show_probabilities): ?>
								<?php if ($week->num_winners == 1 || (sizeof($pools) > 1 && !$selected_pool_id)): ?>
									<th class="text-end" data-sort="float" data-sort-default="desc">1st</th>
								<?php else: ?>
									<?php if ($week->min_score_threshold): ?>
										<th class="text-end" data-sort="float" data-sort-default="desc">Top <?=$week->num_winners?> or ≥<?=$week->min_score_threshold?></th>
									<?php endif; ?>
									<?php if (!$week->min_score_threshold): ?>
										<?php if ($week->num_winners < 10): ?>
											<?php foreach (range(1, $week->num_winners) as $rank): ?>
												<th class="text-end" data-sort="float" data-sort-default="desc"><?=ordinal($rank)?></th>
											<?php endforeach; ?>
										<?php endif; ?>
									<?php endif; ?>
									<th class="text-end" data-sort="float" data-sort-default="desc">Top <?=$week->num_winners?></th>
								<?php endif; ?>
								<?php if ($week->min_score_threshold): ?>
									<th class="text-end" data-sort="float" data-sort-default="desc">≥<?=$week->min_score_threshold?></th>
									<th class="text-end" data-sort="float" data-sort-default="desc">1st</th>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ($week->weekWinners->count() || (!sizeof($unknown_game_ids) && Auth::isAdmin())): ?>
								<th>Winnings</th>
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
									<?php if ($week->num_winners == 1 || (sizeof($pools) > 1 && !$selected_pool_id)) : ?>
										<?php $val = round($stats['prediction_ranks_pct']['r1'], 1); ?>
										<td class="text-end col_winpct2" data-sort-value="<?=$val?>">
											<?php if ($val > 0) printf("%01.1f", $val); ?>
										</td>
									<?php else: ?>
										<?php foreach (range(1, $week->num_winners) as $rank): ?>
											<?php $val = round($stats['prediction_ranks_pct']['r' . $rank], 1); ?>
											<?php if ($week->num_winners < 10): ?>
												<?php if (!$week->min_score_threshold): ?>
													<td class="text-end col_placepct" data-sort-value="<?=$val?>">
														<?php if ($val > 0) printf("%01.1f", $val); ?>
													</td>
												<?php endif; ?>
											<?php endif; ?>
										<?php endforeach; ?>
										<?php if ($week->min_score_threshold): ?>
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
									<?php if ($week->min_score_threshold): ?>
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
								<?php if ($week->weekWinners->count() || (!sizeof($unknown_game_ids) && Auth::isAdmin())): ?>
									<?php if (Auth::isAdmin()): ?>
										<td><input style="max-width: 60px;" type="text" class="form-control form-control-sm" name="winnings-<?=$user_id?>" value="<?=$stats['winnings'] > 0 ? sprintf("%01.2f", $stats['winnings']) : ''?>"></td>
									<?php else: ?>
										<td><?=$stats['winnings'] > 0 ? '$' . sprintf("%01.2f", $stats['winnings']) : ''?></td>
									<?php endif; ?>
								<?php endif; ?>
								<?php if (Auth::isAdmin()): ?>
									<td><?=$stats['possible']?></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<?php if (!sizeof($unknown_game_ids) && Auth::isAdmin()): ?>
						<tfoot>
							<tr class="text-center">
								<td colspan="100%">
									<button type="submit" class="btn btn-primary">Save</button>
								</td>
							</tr>
						</tfoot>
					<?php endif; ?>
				</table>
			</div>
		</form>
	</div>

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
					foreach ($stats_by_user_id as $u_user_id => $stats):
						$user_id = substr($u_user_id, 1);
						?>
						<tr class="text-center fw-bold <?=$user_id == $me->id ? 'bg-me' : ''?>">
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
						<tr>
							<td>
								<div class="fw-bold"><?=$game->title?></div>
								<div class="text-muted"><?=DateTimeDisplay::b($game->date . ' ' . $game->time)?></div>
							</td>

							<?php
							print GameOptionCell::build([
								'game' => $game,
								'option' => '1',
								'user_ids' => $focus_user_ids,
								'my_user_id' => $me->id,
								'show_what_if' => (bool) $num_unknowns,
								'what_if_option' => isset($what_ifs_by_game_id[$game->id]) ? $what_ifs_by_game_id[$game->id] : null,
								'players' => $users,
								'picks' => $bets_by_game_id[$game->id],
							]);
							print GameOptionCell::build([
								'game' => $game,
								'option' => '2',
								'user_ids' => $focus_user_ids,
								'my_user_id' => $me->id,
								'show_what_if' => (bool) $num_unknowns,
								'what_if_option' => isset($what_ifs_by_game_id[$game->id]) ? $what_ifs_by_game_id[$game->id] : null,
								'players' => $users,
								'picks' => $bets_by_game_id[$game->id],
							]);
							if ($show_auto_column) {
								print GameOptionCell::build([
									'game' => $game,
									'option' => '3',
									'user_ids' => $focus_user_ids,
									'my_user_id' => $me->id,
									'players' => $users,
									'picks' => $bets_by_game_id[$game->id],
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

