<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\DB;
use Pick55\Alert;
use Pick55\App;
use Pick55\Auth;
use Pick55\Page;
use Pick55\Models\Bet;
use Pick55\Models\Game;
use Pick55\Models\Season;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\Week;
use Pick55\Models\WeekFormat;
use Pick55\Models\WeekWinner;
use Pick55\Snippets\Rank as RankSnippet;
use Pick55\Snippets\Score as ScoreSnippet;

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
$page->setTitle('Standings - ' . $season->name);
$page->options['season_bar']['show'] = true;
$page->options['season_bar']['show_select'] = true;
$page->options['season_bar']['title'] = 'Standings';
$page->options['season_bar']['rel_path'] = 'season/standings.php';

$users = [];
if ($season->is_active) {
	foreach ($season->getPlayers(true) as $user) {
		$users[$user->id] = $user;
	}
}
else {
	foreach ($season->getPlayers() as $user) {
		$users[$user->id] = $user;
	}
}

$stats_base = [
	'user_id' => null,
	'bets' => 0,
	'right' => 0,
	'wrong' => 0,
	'points' => 0,
	'auto' => 0,
	'max' => 0,
	'multipliers' => [
		'0' => 0,
		'1' => 0,
		'2' => 0,
		'3' => 0,
		'4' => 0,
		'5' => 0,
		'6' => 0,
		'7' => 0,
		'8' => 0,
		'9' => 0,
		'10' => 0,
	],
	'score_by_week_id' => [],

	'right_ncaa' => 0,
	'wrong_ncaa' => 0,
	'points_ncaa' => 0,
	'points_wrong_ncaa' => 0,

	'right_nfl' => 0,
	'wrong_nfl' => 0,
	'points_nfl' => 0,
	'points_wrong_nfl' => 0,

	'right_ou' => 0,
	'wrong_ou' => 0,
	'points_ou' => 0,
	'points_wrong_ou' => 0,

	'right_spread' => 0,
	'wrong_spread' => 0,
	'points_spread' => 0,
	'points_wrong_spread' => 0,

	'winnings' => 0,
	'free_points' => 0,
	'rank' => 0,
];

$stats_by_user_id = [];
foreach ($users as $user) {
	$stats_by_user_id[$user->id] = $stats_base;
	$stats_by_user_id[$user->id]['user_id'] = $user->id;
}

// Fill winnings from week winner table
$q = DB::table(WeekWinner::getTableName() . ' AS winner')
	->leftJoin(Week::getTableName() . ' AS week', 'winner.week_id', '=', 'week.id')
	->select([
		'winner.er_user_id',
		'winner.amount',
	])
	->where('week.football_season_id', '=', $season->id);
foreach ($q->cursor() as $row) {
	if (isset($stats_by_user_id[$row->er_user_id])) {
		$stats_by_user_id[$row->er_user_id]['winnings'] += $row->amount;
	}
}

// Fill bet details by user
foreach ($users as $user) {
	$s = &$stats_by_user_id[$user->id];
	$q = DB::table(Bet::getTableName() . ' AS bet')
		->leftJoin(Game::getTableName() . ' AS game', 'bet.football_game_id', '=', 'game.id')
		->leftJoin(Week::getTableName() . ' AS week', 'game.football_week_id', '=', 'week.id')
		->leftJoin(WeekFormat::getTableName() . ' AS fmt', 'week.football_week_format_id', '=', 'fmt.id')
		->select([
			'bet.option',
			'bet.multiplier',
			DB::raw('week.week_num AS week_id'),
			'game.correct_option',
			'game.type',
			'game.bet_type',
		])
		->where('game.correct_option', '!=', '0')
		->where('bet.user_id', '=', $user->id)
		->where('week.football_season_id', '=', $season->id)
		// A week without a format counts as regular season.
		->whereRaw('(fmt.is_playoffs = 0 OR fmt.id IS NULL)');
	foreach ($q->cursor() as $row) {
		$s['bets']++;
		if (!array_key_exists($row->week_id, $s['score_by_week_id'])) {
			$s['score_by_week_id'][$row->week_id] = 0;
		}
		$is_correct = false;
		$is_incorrect = false;
		$is_auto = false;
		if ($row->option == '3') {
			$is_auto = true;
			$is_correct = true;
		}
		elseif ($row->correct_option != '0') {
			if ($row->correct_option == $row->option) {
				$is_correct = true;
			}
			else {
				$is_incorrect = true;
			}
		}
		if ($is_correct) {
			$s['right']++;
			$s['points'] += $row->multiplier;
			$s['score_by_week_id'][$row->week_id] += $row->multiplier;

			if ($is_auto) {
				$s['auto'] += $row->multiplier;
			}
			else {
				$s['multipliers'][$row->multiplier]++;
			}

			if ($row->type == Game::LEAGUE_NFL) {
				$s['right_nfl']++;
				$s['points_nfl'] += $row->multiplier;
			}
			else {
				$s['right_ncaa']++;
				$s['points_ncaa'] += $row->multiplier;
			}

			if ($row->bet_type == Game::BET_TYPE_OVER_UNDER) {
				$s['right_ou']++;
				$s['points_ou'] += $row->multiplier;
			}
			else {
				$s['right_spread']++;
				$s['points_spread'] += $row->multiplier;
			}
		}
		if ($is_incorrect) {
			$s['wrong']++;

			if ($row->type == Game::LEAGUE_NFL) {
				$s['wrong_nfl']++;
				$s['points_wrong_nfl'] += $row->multiplier;
			}
			else {
				$s['wrong_ncaa']++;
				$s['points_wrong_ncaa'] += $row->multiplier;
			}

			if ($row->bet_type == Game::BET_TYPE_OVER_UNDER) {
				$s['wrong_ou']++;
				$s['points_wrong_ou'] += $row->multiplier;
			}
			else {
				$s['wrong_spread']++;
				$s['points_wrong_spread'] += $row->multiplier;
			}
		}

		$s['max'] += $row->multiplier;
	}
}

// Compile totals
$stats_total = array_merge($stats_base, [
	'max_user_points' => null,
	'min_user_points' => null,
	'num_users' => sizeof($users),
]);
foreach ($stats_by_user_id as $user_id => $stats) {
	foreach ($stats as $k => $v) {
		if (in_array($k, [
			'user_id',
			'score_by_week_id',
		])) {
			continue;
		}
		if (is_array($v)) {
			foreach ($v as $k2 => $v2) {
				$stats_total[$k][$k2] += $v2;
			}
		}
		else {
			$stats_total[$k] += $v;
		}
	}
	if ($stats_total['max_user_points'] === null || $stats['points'] > $stats_total['max_user_points']) {
		$stats_total['max_user_points'] = $stats['points'];
	}
	if ($stats_total['min_user_points'] === null || $stats['points'] < $stats_total['min_user_points']) {
		$stats_total['min_user_points'] = $stats['points'];
	}
}

// Sort by points, then sort by number correct
$sort_pts = [];
$sort_correct = [];
foreach ($stats_by_user_id as $user_id => $stats) {
	$sort_pts[$user_id] = $stats['points'];
	$sort_correct[$user_id] = $stats['right'];
}
array_multisort($sort_pts, SORT_DESC, $sort_correct, SORT_DESC, $stats_by_user_id);
$tmp = [];
foreach ($stats_by_user_id as $stats) {
	$tmp[$stats['user_id']] = $stats;
}
$stats_by_user_id = $tmp;

// Apply rank based stats
$rank = 0;
$free_pts_by_rank = [
	1 => 55,
	2 => 36,
	3 => 36,
	4 => 28,
	5 => 28,
	6 => 21,
	7 => 21,
	8 => 15,
	9 => 15,
	10 => 10,
	11 => 10,
	12 => 10
];
foreach ($stats_by_user_id as $user_id => $stats) {
	$rank++;
	$stats_by_user_id[$user_id]['rank'] = $rank;
	if (array_key_exists($rank, $free_pts_by_rank)) {
		$stats_by_user_id[$user_id]['free_points'] = $free_pts_by_rank[$rank];
	}
}

// Week scores count
$week_scores_count = [];
foreach ($stats_by_user_id as $user_id => $stats) {
	foreach ($stats['score_by_week_id'] as $week_id => $score) {
		if (!array_key_exists($score, $week_scores_count)) {
			$week_scores_count[$score] = 0;
		}
		$week_scores_count[$score]++;
	}
}
$tmp_max = 55;
if (sizeof($week_scores_count)) {
	$tmp_max = max($tmp_max, max(array_keys($week_scores_count)));
}
foreach (range(0, $tmp_max) as $score) {
	if (array_key_exists($score, $week_scores_count)) {
		continue;
	}
	$week_scores_count[$score] = 0;
}
ksort($week_scores_count);

ob_start();
?>
<script>
	
$(document).ready(function() {
	$('.toggle-userIdCol').click(function() {
		$('.userIdCol').toggleClass('hidden');
	});
});
const labels = [
	<?=implode(',', array_keys($week_scores_count))?>
];
const data = {
	labels: labels,
	datasets: [{
		label: '#',
		backgroundColor: 'rgb(75, 192, 192)',
		data: [<?=implode(',', $week_scores_count)?>],
	}]
};
const config = {
	type: 'bar',
	data: data,
	options: {
		aspectRatio: 4,
		scales: {
			x: {
				min: 0,
				title: {
					text: 'Weekly Score',
					display: true
				}
			},
			y: {
				min: 0,
				title: {
					text: '# of Occurrences',
					display: true
				}
			}
		},
		plugins: {
			tooltip: { enabled: false },
			legend: { display: false }
		}
	}
};
var chart_weekly_scores = new Chart(
	document.getElementById('chart_weekly_scores'),
	config
);
</script>
<?php
$page->setScripts(ob_get_clean());

ob_start();
?>
<div class="container py-4">

	<div class="card mb-3">
		<h4 class="card-header">
			<div style="float: left">Leaderboard</div>
			<div style="float: right">
				<?php if (Auth::isAdmin()): ?>
					<a class="btn btn-outline-info toggle-userIdCol">Toggle User ID Column</a>
				<?php endif; ?>
			</div>
			<div class="clear"></div>
		</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped table-sortable">
				<thead>
					<tr class="text-center">
						<th data-sort="int">Place</th>
						<th data-sort="int" class="hidden userIdCol">User ID</th>
						<th data-sort="string">Player</th>
						<th data-sort="int" data-sort-default="desc">Pts</th>
						<th data-sort="float" data-sort-default="desc">Pct</th>
						<th data-sort="float" data-sort-default="desc">Picks</th>
						<th data-sort="int" data-sort-default="desc">Winnings</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($stats_by_user_id as $user_id => $stats): ?>
						<tr class="text-center <?=$user_id == $me->id ? 'bg-me' : ''?>">
							<td><?=RankSnippet::build(['rank' => $stats['rank']])?></td>
							<td class="hidden userIdCol"><?=$user_id?></td>
							<td class="text-start fw-bold"><?=$users[$user_id]->getDisplayName()?></td>
							<td><?=$stats['points']?></td>
							<td><?=sprintf("%01.1f", $stats['points'] / max(1, $stats['max']) * 100)?>%</td>
							<td>
								<?=ScoreSnippet::build(['correct' => $stats['right'], 'incorrect' => $stats['wrong']])?>
							</td>
							<td data-sort-value="<?=$stats['winnings']?>"><?=$stats['winnings'] > 0 ? '$' . round($stats['winnings']) : ''?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="text-center fw-bold bg-light2">
						<td colspan="2">Totals</td>
						<td colspan="2"><?=$stats_total['points']?> / <?=$stats_total['max']?></td>
						<td><?=ScoreSnippet::build(['correct' => $stats_total['right'], 'incorrect' => $stats_total['wrong']])?></td>
						<td>$<?=$stats_total['winnings']?></td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Weekly Occurences of Scores</h4>
		<div class="card-body">
			<canvas id="chart_weekly_scores"></canvas>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">NFL vs NCAA</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped table-sortable">
				<thead>
					<tr class="text-center">
						<th></th>
						<th class="text-ncaa" colspan="3">NCAA</th>
						<th class="text-nfl" colspan="3">NFL</th>
					</tr>
					<tr class="text-end">
						<th class="text-center" data-sort="string">Player</th>

						<th class="text-ncaa" data-sort="float" data-sort-default="desc">Picks</th>
						<th class="text-ncaa" data-sort="int" data-sort-default="desc">Pts</th>
						<th class="text-ncaa" data-sort="float" data-sort-default="desc">Pts / Max</th>

						<th class="text-nfl" data-sort="float" data-sort-default="desc">Picks</th>
						<th class="text-nfl" data-sort="int" data-sort-default="desc">Pts</th>
						<th class="text-nfl" data-sort="float" data-sort-default="desc">Pts / Max</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($stats_by_user_id as $user_id => $stats): ?>
						<tr class="text-end <?=$user_id == $me->id ? 'bg-me' : ''?>">
							<td class="text-start fw-bold"><?=$users[$user_id]->getDisplayName()?></td>

							<td class="text-ncaa"><?=ScoreSnippet::build(['correct' => $stats['right_ncaa'], 'incorrect' => $stats['wrong_ncaa']])?></td>
							<td class="text-ncaa"><?=$stats['points_ncaa']?> </td>
							<td class="text-ncaa"><?=sprintf("%01.1f", $stats['points_ncaa'] / max(1, $stats['points_ncaa'] + $stats['points_wrong_ncaa']) * 100)?>%</td>

							<td class="text-nfl"><?=ScoreSnippet::build(['correct' => $stats['right_nfl'], 'incorrect' => $stats['wrong_nfl']])?></td>
							<td class="text-nfl"><?=$stats['points_nfl']?> </td>
							<td class="text-nfl"><?=sprintf("%01.1f", $stats['points_nfl'] / max(1, $stats['points_nfl'] + $stats['points_wrong_nfl']) * 100)?>%</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="text-end fw-bold bg-light2">
						<td class="text-center">Totals</td>

						<td class="text-ncaa"><?=ScoreSnippet::build(['correct' => $stats_total['right_ncaa'], 'incorrect' => $stats_total['wrong_ncaa']])?></td>
						<td class="text-ncaa"><?=$stats_total['points_ncaa']?> </td>
						<td class="text-ncaa"><?=sprintf("%01.1f", $stats_total['points_ncaa'] / max(1, $stats_total['points_ncaa'] + $stats_total['points_wrong_ncaa']) * 100)?>%</td>

						<td class="text-nfl"><?=ScoreSnippet::build(['correct' => $stats_total['right_nfl'], 'incorrect' => $stats_total['wrong_nfl']])?></td>
						<td class="text-nfl"><?=$stats_total['points_nfl']?> </td>
						<td class="text-nfl"><?=sprintf("%01.1f", $stats_total['points_nfl'] / max(1, $stats_total['points_nfl'] + $stats_total['points_wrong_nfl']) * 100)?>%</td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Over/Under vs Spread</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped table-sortable">
				<thead>
					<tr class="text-center">
						<th></th>
						<th class="text-ou" colspan="3">O/U</th>
						<th class="text-spread" colspan="3">Spread</th>
					</tr>
					<tr class="text-end">
						<th class="text-center" data-sort="string">Player</th>

						<th class="text-ou" data-sort="float" data-sort-default="desc">Picks</th>
						<th class="text-ou" data-sort="int" data-sort-default="desc">Pts</th>
						<th class="text-ou" data-sort="float" data-sort-default="desc">Pts / Max</th>

						<th class="text-spread" data-sort="float" data-sort-default="desc">Picks</th>
						<th class="text-spread" data-sort="int" data-sort-default="desc">Pts</th>
						<th class="text-spread" data-sort="float" data-sort-default="desc">Pts / Max</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($stats_by_user_id as $user_id => $stats): ?>
						<tr class="text-end <?=$user_id == $me->id ? 'bg-me' : ''?>">
							<td class="text-start fw-bold"><?=$users[$user_id]->getDisplayName()?></td>

							<td class="text-ou"><?=ScoreSnippet::build(['correct' => $stats['right_ou'], 'incorrect' => $stats['wrong_ou']])?></td>
							<td class="text-ou"><?=$stats['points_ou']?> </td>
							<td class="text-ou"><?=sprintf("%01.1f", $stats['points_ou'] / max(1, $stats['points_ou'] + $stats['points_wrong_ou']) * 100)?>%</td>

							<td class="text-spread"><?=ScoreSnippet::build(['correct' => $stats['right_spread'], 'incorrect' => $stats['wrong_spread']])?></td>
							<td class="text-spread"><?=$stats['points_spread']?> </td>
							<td class="text-spread"><?=sprintf("%01.1f", $stats['points_spread'] / max(1, $stats['points_spread'] + $stats['points_wrong_spread']) * 100)?>%</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="text-end fw-bold bg-light2">
						<td class="text-center">Totals</td>

						<td class="text-ou"><?=ScoreSnippet::build(['correct' => $stats_total['right_ou'], 'incorrect' => $stats_total['wrong_ou']])?></td>
						<td class="text-ou"><?=$stats_total['points_ou']?> </td>
						<td class="text-ou"><?=sprintf("%01.1f", $stats_total['points_ou'] / max(1, $stats_total['points_ou'] + $stats_total['points_wrong_ou']) * 100)?>%</td>

						<td class="text-spread"><?=ScoreSnippet::build(['correct' => $stats_total['right_spread'], 'incorrect' => $stats_total['wrong_spread']])?></td>
						<td class="text-spread"><?=$stats_total['points_spread']?> </td>
						<td class="text-spread"><?=sprintf("%01.1f", $stats_total['points_spread'] / max(1, $stats_total['points_spread'] + $stats_total['points_wrong_spread']) * 100)?>%</td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>

	<div class="card mb-3">
		<h4 class="card-header">Pick Value Stats</h4>
		<div class="table-responsive">
			<table class="table table-sm table-striped table-sortable">
				<thead>
					<tr class="text-center">
						<th data-sort="string">Player</th>
						<?php foreach (range(10, 0) as $i): ?>
							<th data-sort="int" data-sort-default="desc"><?=$i?></th>
						<?php endforeach; ?>
						<th colspan="2" data-sort="float" data-sort-default="desc">Pts / Pick</th>
						<th data-sort="float" data-sort-default="desc">Efficiency</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($stats_by_user_id as $user_id => $stats): ?>
						<tr class="text-center <?=$user_id == $me->id ? 'bg-me' : ''?>">
							<td class="text-start fw-bold"><?=$users[$user_id]->getDisplayName()?></td>
							<?php foreach (range(10, 0) as $i): ?>
								<td><?=$stats['multipliers'][$i]?></td>
							<?php endforeach; ?>
							<td><?=sprintf("%01.1f", $stats['points'] / max(1, $stats['right']))?></td>
							<td><?=sprintf("%01.1f", $stats['points'] / max(1, $stats['right'] + $stats['wrong']))?></td>
							<td><?=sprintf("%01.1f", $stats['points'] / max(1, $stats['right']) / (220 / 48) * 100)?>%</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="text-center fw-bold bg-light2">
						<td>Totals</td>
						<?php foreach (range(10, 0) as $i): ?>
							<td><?=$stats_total['multipliers'][$i]?></td>
						<?php endforeach; ?>
						<td><?=sprintf("%01.1f", $stats_total['points'] / max(1, $stats_total['right']))?></td>
						<td><?=sprintf("%01.1f", $stats_total['points'] / max(1, $stats_total['right'] + $stats_total['wrong']))?></td>
						<td><?=sprintf("%01.1f", $stats_total['points'] / max(1, $stats_total['right']) / (220 / 48) * 100)?>%</td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>
</div>
<?php
$page->setContent(ob_get_clean());
print $page->render();
