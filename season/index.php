<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\SeasonHistory;
use Pick55\Models\Bet;
use Pick55\Models\Game;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\Week;
use Pick55\Snippets\Rank as RankSnippet;
use Pick55\Snippets\DateTimeDisplay;
use Pick55\Snippets\DateDisplay;

Auth::guard();

$app = App::get();
$season = $app->getSeason();
if (!$season) {
	redir('season/inactive.php');
}
$me = Auth::user();
if (!$season->hasPlayer($me->id)) {
	redir('season/unauthorized.php?id=' . $season->id);
}

$page = new Page;
$page->setTitle($season->name);
$page->options['season_bar']['show'] = true;
$page->options['season_bar']['show_select'] = true;
$page->options['season_bar']['title'] = '';
$page->options['season_bar']['rel_path'] = 'season/index.php';

$stats = [
	Game::LEAGUE_NFL => 0,
	Game::LEAGUE_NCAA => 0,
	Game::BET_TYPE_OVER_UNDER => 0,
	Game::BET_TYPE_SPREAD => 0,
];

$q = DB::table(Bet::getTableName() . ' AS pick')
	->leftJoin(Game::getTableName() . ' AS game', 'pick.football_game_id', '=', 'game.id')
	->leftJoin(Week::getTableName() . ' AS week', 'game.football_week_id', '=', 'week.id')
	->select([
		'game.type',
		'game.bet_type',
		DB::raw('COUNT(*) AS num'),
		DB::raw('SUM(pick.multiplier) AS points'),
	])
	->where('game.correct_option', '!=', '0')
	->where('pick.user_id', '=', $me->id)
	->whereRaw('pick.option = game.correct_option')
	->where('week.football_season_id', '=', $season->id)
	->groupBy('game.type')
	->groupBy('game.bet_type');
foreach ($q->cursor() as $row) {
	$stats[$row->type] += $row->points;
	$stats[$row->bet_type] += $row->points;
}

$history = SeasonHistory::forUser($me->id);
$total_winnings = 0;
foreach ($history as $h) {
	$total_winnings += $h['winnings'];
}

ob_start();
?>
<script>
const data1 = {
	labels: ['NFL', 'NCAA'],
	datasets: [{
		label: 'dataset label',
		backgroundColor: [
			'rgb(39, 174, 96)', // NFL
			'rgb(192, 57, 43)' // NCAA
		],
		data: [
			<?=$stats[Game::LEAGUE_NFL]?>,
			<?=$stats[Game::LEAGUE_NCAA]?>
		],
	}]
};
const config1 = {
	type: 'doughnut',
	data: data1,
};
var chart_points_by_league = new Chart(
	document.getElementById('chart_points_by_league'),
	config1
);

const data2 = {
	labels: ['Over/Under', 'Spread'],
	datasets: [{
		label: 'dataset label',
		backgroundColor: [
			'rgb(155, 89, 182)', // Over/Under
			'rgb(230, 126, 34)' // Spread
		],
		data: [
			<?=$stats[Game::BET_TYPE_OVER_UNDER]?>,
			<?=$stats[Game::BET_TYPE_SPREAD]?>
		],
	}]
};
const config2 = {
	type: 'doughnut',
	data: data2,
};
var chart_points_by_type = new Chart(
	document.getElementById('chart_points_by_type'),
	config2
);
</script>
<?php
$page->setScripts(ob_get_clean());

ob_start();
?>
<div class="container py-4">
	<div class="card mb-3">
		<h4 class="card-header">My Season</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped table-my-season">
				<colgroup>
					<col class="c-week-num">
					<col class="c-week-link">
					<col class="c-rank">
					<col class="c-pts">
					<?php foreach (range(1, 10) as $i): ?>
						<col class="c-pick">
					<?php endforeach; ?>
				</colgroup>
				<thead>
					<tr class="text-center">
						<th rowspan="2" colspan="2">Week</th>
						<th rowspan="2">Rank</th>
						<th rowspan="2">Pts</th>
						<th colspan="10">Picks</th>
					</tr>
					<tr class="text-center">
						<?php foreach (range(1, 10) as $i): ?>
							<th>x<?=$i?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($season->weeks as $week): ?>
						<tr class="text-center">
							<td class="fw-bold"><?=$week->week_num?></td>
							<td>
								<?php if ($week->canUserPick($me->id)): ?>
									<a href="week/pick.php?id=<?=$week->id?>">Make Picks</a>
								<?php elseif ($week->canUserSeeResults($me->id)): ?>
									<a href="week/results.php?id=<?=$week->id?>">Results</a>
								<?php endif; ?>
							</td>
							<?php if ($week->canUserPick($me->id)): ?>
								<td colspan="6"><?=$week->getName()?></td>
								<td colspan="6">Picks due <?=DateTimeDisplay::b($week->getFirstGameAt())?></td>
							<?php elseif ($week->canUserSeeResults($me->id)): ?>
								<td>
									<?php if ($rank = $week->getUserRank($me->id)): ?>
										<?=RankSnippet::build(['rank' => $rank])?>
									<?php endif; ?>
								</td>
								<td class="fw-bold"><?=$week->getUserScore($me->id)?></td>
								<?php
								foreach (range(1, 10) as $i):
									$result = $week->getUserPickResult($me->id, $i);
									if ($result == 1): ?>
										<td class="td-right fw-bold"><i class="fas fa-check"></i></td>
									<?php elseif ($result == -1): ?>
										<td class="td-wrong fw-bold"><i class="fas fa-times"></i></td>
									<?php elseif ($result == 3): ?>
										<td class="td-auto fw-bold"><i class="far fa-dot-circle"></i></td>
									<?php elseif($week->canUserSeeResults($me->id)): ?>
										<td class="fw-bold">?</td>
									<?php else: ?>
										<td></td>
									<?php endif; ?>
								<?php endforeach; ?>
							<?php elseif ($week->getPicksAvailableAt()): ?>
								<td colspan="6"><?=$week->getName()?></td>
								<td colspan="6">Picks available <?=DateTimeDisplay::b($week->getPicksAvailableAt())?></td>
							<?php else: ?>
								<td colspan="10" class="fts-italic">TBD</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Points Breakdown</h4>
		<div class="card-body">
			<div class="row">
				<div class="col-sm-6 col-12">
					<canvas id="chart_points_by_league" style="max-height: 200px;"></canvas>
				</div>
				<div class="col-sm-6 col-12">
					<canvas id="chart_points_by_type" style="max-height: 200px;"></canvas>
				</div>
			</div>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Seasons</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped">
				<thead>
					<tr class="text-center">
						<th class="text-start">Season</th>
						<th>Players</th>
						<th>Finish</th>
						<th>Best Week</th>
						<th>Winnings</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($history as $season_id => $h): ?>
						<tr class="text-center <?=$season_id == $season->id ? 'bg-me' : ''?>">
							<td class="text-start fw-bold">
								<a href="index.php?id=<?=$season_id?>"><?=$h['season']->name?></a>
							</td>
							<td><?=$h['players']?></td>
							<td>
								<?php if ($h['finish']): ?>
									<a href="standings.php?id=<?=$season_id?>" class="text-decoration-none">
										<?=RankSnippet::build(['rank' => $h['finish']])?>
									</a>
									<?php if ($h['season']->is_active): ?>
										<small class="text-muted">so far</small>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($h['best_week_rank']): ?>
									<?=RankSnippet::build(['rank' => $h['best_week_rank']])?>
									<small class="text-muted">Week <?=$h['best_week_num']?></small>
								<?php endif; ?>
							</td>
							<td><?=$h['winnings'] > 0 ? '$' . round($h['winnings']) : ''?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="text-center fw-bold bg-light2">
						<td class="text-start">Totals</td>
						<td colspan="3"><?=sizeof($history)?> season<?=sizeof($history) == 1 ? '' : 's'?></td>
						<td>$<?=round($total_winnings)?></td>
					</tr>
				</tfoot>
			</table>
		</div>
		<div class="card-footer text-muted small">
			Finish and Best Week count regular-season weeks only (no playoffs).
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
