<?php

namespace Pick55\R;

use Pick55\AllTimeStats;
use Pick55\DB;
use Pick55\Models\Game;
use Pick55\Models\GameScore;
use Pick55\Models\Team;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\Week;
use Pick55\Models\WeekWinner;

/**
 * Data for the Today page (r/index.php) and its refresh (r/api/today.php),
 * beyond what Context already knows. Every method is a few queries at most
 * and reuses Context's memoized weeks, players and WeekResults.
 */
class Today
{
	private function __construct() {}

	/**
	 * The season leaderboard by the standings page's rule (regular-season
	 * weeks only, decided games, guaranteed picks count as right, paid
	 * players while the season is active; points, then number right), with
	 * each player's movement since before the latest counted week. One query.
	 *
	 * @param Context $ctx
	 * @param int $top  rows to return besides the viewer
	 * @return array [
	 *   'players' => int,
	 *   'rows' => list of [rank, user_id, name, points, right, wrong, delta, me, friend] (top $top, plus the viewer when lower),
	 *   'me' => the viewer's row or null,
	 *   'my_weeks' => list of ['num' => week_num, 'points' => int] for every counted week,
	 *   'last_week_num' => int|null,
	 * ]
	 */
	public static function board(Context $ctx, $top = 5)
	{
		$out = ['players' => 0, 'rows' => [], 'me' => null, 'my_weeks' => [], 'last_week_num' => null];
		if (!$ctx->season || !$ctx->user) {
			return $out;
		}
		$season = $ctx->season;
		$me_id = (int) $ctx->user->id;

		// Roster: paid players while the season is active, everyone after.
		$roster = [];
		$q = UsersSeasonsLink::where('football_season_id', '=', $season->id);
		foreach ($q->get(['er_user_id', 'paid_at']) as $link) {
			if ($season->is_active && !$link->paid_at) {
				continue;
			}
			$roster[(int) $link->er_user_id] = true;
		}
		if (!sizeof($roster)) {
			return $out;
		}

		$q = DB::table('football_bets AS b')
			->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
			->join('football_weeks AS w', 'w.id', '=', 'g.football_week_id')
			->leftJoin('football_week_formats AS f', 'w.football_week_format_id', '=', 'f.id')
			->where('w.football_season_id', '=', $season->id)
			->where('g.correct_option', '!=', '0')
			// A week without a format counts as regular season.
			->whereRaw('(f.is_playoffs = 0 OR f.id IS NULL)')
			->groupBy('b.user_id', 'w.week_num')
			->selectRaw("
				b.user_id,
				w.week_num,
				SUM(IF(b.option = '3' OR b.option = g.correct_option, b.multiplier, 0)) AS points,
				SUM(b.option = '3' OR b.option = g.correct_option) AS right_n,
				SUM(b.option <> '3' AND b.option <> g.correct_option) AS wrong_n
			");
		$by_user = [];
		$week_nums = [];
		foreach (array_keys($roster) as $user_id) {
			$by_user[$user_id] = ['points' => 0, 'right' => 0, 'wrong' => 0, 'weeks' => []];
		}
		foreach ($q->get() as $row) {
			$user_id = (int) $row->user_id;
			$num = (int) $row->week_num;
			$week_nums[$num] = true;
			if (!isset($by_user[$user_id])) {
				continue;
			}
			$by_user[$user_id]['points'] += (int) $row->points;
			$by_user[$user_id]['right'] += (int) $row->right_n;
			$by_user[$user_id]['wrong'] += (int) $row->wrong_n;
			$by_user[$user_id]['weeks'][$num] = ['points' => (int) $row->points, 'right' => (int) $row->right_n];
		}
		ksort($week_nums);
		$week_nums = array_keys($week_nums);
		$last = sizeof($week_nums) ? end($week_nums) : null;

		$rank_now = self::rankOrder($by_user, null);
		$rank_before = sizeof($week_nums) > 1 ? self::rankOrder($by_user, $last) : [];

		$players = $ctx->players();
		$friends = $ctx->friendIds();
		$rows = [];
		foreach ($rank_now as $user_id => $rank) {
			$s = $by_user[$user_id];
			$rows[$user_id] = [
				'rank' => $rank,
				'user_id' => $user_id,
				'name' => isset($players[$user_id]) ? $players[$user_id]->getDisplayName() : 'Player #' . $user_id,
				'points' => $s['points'],
				'right' => $s['right'],
				'wrong' => $s['wrong'],
				'delta' => isset($rank_before[$user_id]) ? $rank_before[$user_id] - $rank : 0,
				'me' => $user_id === $me_id,
				'friend' => isset($friends[$user_id]),
			];
		}

		$list = [];
		foreach ($rows as $user_id => $row) {
			if (sizeof($list) >= $top) {
				break;
			}
			$list[] = $row;
		}
		$me_row = isset($rows[$me_id]) ? $rows[$me_id] : null;
		if ($me_row && $me_row['rank'] > $top) {
			$list[] = $me_row;
		}

		$my_weeks = [];
		foreach ($week_nums as $num) {
			$my_weeks[] = [
				'num' => $num,
				'points' => isset($by_user[$me_id]['weeks'][$num]) ? $by_user[$me_id]['weeks'][$num]['points'] : 0,
			];
		}

		return [
			'players' => sizeof($rows),
			'rows' => $list,
			'me' => $me_row,
			'my_weeks' => $my_weeks,
			'last_week_num' => $last,
		];
	}

	/**
	 * @param array $by_user  user_id => ['points', 'right', 'weeks' => num => [points, right]]
	 * @param int|null $without_week  leave this week_num out
	 * @return array user_id => rank (1-based, sequential as on the standings page)
	 */
	private static function rankOrder(array $by_user, $without_week)
	{
		$pts = [];
		$right = [];
		$ids = [];
		foreach ($by_user as $user_id => $s) {
			$p = $s['points'];
			$r = $s['right'];
			if ($without_week !== null && isset($s['weeks'][$without_week])) {
				$p -= $s['weeks'][$without_week]['points'];
				$r -= $s['weeks'][$without_week]['right'];
			}
			$pts[] = $p;
			$right[] = $r;
			$ids[] = $user_id;
		}
		array_multisort($pts, SORT_DESC, $right, SORT_DESC, $ids, SORT_ASC);
		$ranks = [];
		foreach ($ids as $i => $user_id) {
			$ranks[$user_id] = $i + 1;
		}
		return $ranks;
	}

	/**
	 * This season's newest wall entries (the stats pages' rules: complete
	 * weeks, real picks only, AllTimeStats thresholds). One query.
	 *
	 * @param Context $ctx
	 * @param int $limit
	 * @return array list of [kind: perfect|honor|zero|dishonor, user_id, name, points, week_num, week_id, me, friend]
	 */
	public static function walls(Context $ctx, $limit = 4)
	{
		if (!$ctx->season) {
			return [];
		}
		$complete = [];
		foreach ($ctx->weeks() as $id => $info) {
			if ($info['games'] > 0 && $info['undecided'] == 0) {
				$complete[$id] = $info;
			}
		}
		if (!sizeof($complete)) {
			return [];
		}
		$q = DB::table('football_bets AS b')
			->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
			->whereIn('g.football_week_id', array_keys($complete))
			->groupBy('g.football_week_id', 'b.user_id')
			->selectRaw("
				g.football_week_id AS week_id,
				b.user_id,
				SUM(b.option IN ('1','2')) AS picks,
				SUM(b.option = '3') AS autos,
				SUM(IF(g.correct_option <> '0' AND b.option = g.correct_option, b.multiplier, 0)) AS points
			");
		$players = $ctx->players();
		$friends = $ctx->friendIds();
		$entries = [];
		foreach ($q->get() as $row) {
			if ((int) $row->autos > 0 || (int) $row->picks == 0) {
				continue;
			}
			$points = (int) $row->points;
			if ($points >= AllTimeStats::PERFECT) {
				$kind = 'perfect';
			}
			elseif ($points >= AllTimeStats::HONOR_MIN) {
				$kind = 'honor';
			}
			elseif ($points == 0) {
				$kind = 'zero';
			}
			elseif ($points <= AllTimeStats::DISHONOR_MAX) {
				$kind = 'dishonor';
			}
			else {
				continue;
			}
			$user_id = (int) $row->user_id;
			$info = $complete[(int) $row->week_id];
			$entries[] = [
				'kind' => $kind,
				'user_id' => $user_id,
				'name' => isset($players[$user_id]) ? $players[$user_id]->getDisplayName() : 'Player #' . $user_id,
				'points' => $points,
				'week_num' => $info['num'],
				'week_id' => $info['id'],
				'me' => $ctx->user && $user_id === (int) $ctx->user->id,
				'friend' => isset($friends[$user_id]),
			];
		}
		usort($entries, function ($a, $b) {
			if ($a['week_num'] !== $b['week_num']) {
				return $b['week_num'] - $a['week_num'];
			}
			// Fame first, highest first; then shame, lowest first.
			$fa = in_array($a['kind'], ['perfect', 'honor'], true);
			$fb = in_array($b['kind'], ['perfect', 'honor'], true);
			if ($fa !== $fb) {
				return $fa ? -1 : 1;
			}
			return $fa ? $b['points'] - $a['points'] : $a['points'] - $b['points'];
		});
		return array_slice($entries, 0, $limit);
	}

	/**
	 * The viewer's finish in a complete week: rank, points, winnings, the
	 * week's winners, and season movement.
	 *
	 * @param Context $ctx
	 * @param Week $week
	 * @param array|null $board  Today::board() for the movement
	 * @return array|null [week_id, rank, players, points, right, wrong, winnings, winners (list of names), top_points, season_rank, season_delta]
	 */
	public static function recap(Context $ctx, Week $week, $board = null)
	{
		if (!$ctx->user) {
			return null;
		}
		$results = $ctx->results($week);
		$key = 'u' . $ctx->user->id;
		if (!isset($results['stats_by_user_id'][$key])) {
			return null;
		}
		$me = $results['stats_by_user_id'][$key];
		$players = $ctx->players();
		$winners = [];
		$top_points = null;
		foreach ($results['stats_by_user_id'] as $u_user_id => $stats) {
			if ((int) $stats['rank'] !== 1) {
				break;
			}
			$top_points = (int) $stats['points'];
			$user_id = (int) substr($u_user_id, 1);
			$winners[] = isset($players[$user_id]) ? $players[$user_id]->getDisplayName() : 'Player #' . $user_id;
		}
		// Recorded winnings when the results page has recorded them, else what the format pays.
		$recorded = WeekWinner::where('week_id', '=', $week->id)
			->where('er_user_id', '=', $ctx->user->id)
			->sum('amount');
		$winnings = (float) $recorded > 0 ? (float) $recorded : (float) $me['payout'];
		return [
			'week_id' => (int) $week->id,
			'rank' => (int) $me['rank'],
			'players' => sizeof($results['stats_by_user_id']),
			'points' => (int) $me['points'],
			'right' => (int) $me['right'],
			'wrong' => (int) $me['wrong'],
			'winnings' => $winnings,
			'winners' => $winners,
			'top_points' => $top_points,
			'season_rank' => $board && $board['me'] ? (int) $board['me']['rank'] : null,
			'season_delta' => $board && $board['me'] ? (int) $board['me']['delta'] : 0,
		];
	}

	/**
	 * The live week's games with the viewer's pick on each and whether it is
	 * winning: in play first, then upcoming, then finished, each in kickoff
	 * order. Uses the cached WeekResults for games and bets, plus the teams
	 * and live score rows (two queries).
	 *
	 * @param Context $ctx
	 * @param Week $week
	 * @return array list of [id, league, type, state (pre|in|post), label, kickoff, away, home, away_score, home_score,
	 *   line, my_option, my_mult, my_label, status (covering|trailing|even|pending|right|wrong|auto|none), decided]
	 */
	public static function strip(Context $ctx, Week $week)
	{
		$results = $ctx->results($week);
		$games = [];
		foreach (Game::hydrate($results['games']) as $game) {
			$games[(int) $game->id] = $game;
		}
		if (!sizeof($games)) {
			return [];
		}
		$team_ids = [];
		foreach ($games as $game) {
			$team_ids[(int) $game->away_team_id] = true;
			$team_ids[(int) $game->home_team_id] = true;
		}
		unset($team_ids[0]);
		$teams = [];
		if (sizeof($team_ids)) {
			foreach (Team::whereIn('id', array_keys($team_ids))->get() as $team) {
				$teams[(int) $team->id] = $team;
			}
		}
		$scores = [];
		try {
			$scores = GameScore::forWeek($week->id);
		}
		catch (\Throwable $e) {
			$scores = [];
		}
		$me_id = $ctx->user ? (int) $ctx->user->id : 0;

		$rows = [];
		$order = 0;
		foreach ($games as $id => $game) {
			$away = isset($teams[(int) $game->away_team_id]) ? $teams[(int) $game->away_team_id] : null;
			$home = isset($teams[(int) $game->home_team_id]) ? $teams[(int) $game->home_team_id] : null;
			if ($away) {
				$game->setRelation('awayTeam', $away);
			}
			if ($home) {
				$game->setRelation('homeTeam', $home);
			}
			$nfl = $game->type == Game::LEAGUE_NFL;
			$my = null;
			foreach ($results['bets_by_game_id'][$id] as $bet) {
				if ($bet['user_id'] === $me_id) {
					$my = $bet;
					break;
				}
			}
			$score = isset($scores[$id]) ? $scores[$id] : null;
			$state = GameScore::STATE_PRE;
			$label = '';
			$leading = null;
			if ($score) {
				$score->setRelation('game', $game);
				$state = (string) $score->state;
				$label = $score->getStatusLabel();
				$leading = $score->getLeadingOption();
			}
			$decided = $game->correct_option != '0';
			if ($decided && $state === GameScore::STATE_PRE) {
				$state = GameScore::STATE_POST;
				$label = 'Final';
			}
			$my_option = $my ? (string) $my['option'] : '0';
			if ($my_option === '3') {
				$status = 'auto';
			}
			elseif ($my_option === '0') {
				$status = 'none';
			}
			elseif ($decided) {
				$status = $game->correct_option == $my_option ? 'right' : 'wrong';
			}
			elseif ($leading !== null) {
				$status = $leading === $my_option ? 'covering' : 'trailing';
			}
			elseif ($score && $score->hasScore()) {
				$status = 'even';
			}
			else {
				$status = 'pending';
			}
			$my_label = '';
			if ($my_option === '1' || $my_option === '2') {
				$my_label = GameScore::optionLabel($my_option === '1' ? $game->option_1 : $game->option_2);
				if ($game->bet_type == Game::BET_TYPE_OVER_UNDER) {
					$my_label = ucfirst(strtolower($my_label)) . ' ' . (float) $game->value;
				}
				else {
					$text = $my_option === '1' ? $game->option_1 : $game->option_2;
					if (preg_match('/\(([^)]*)\)\s*$/', (string) $text, $m)) {
						$my_label .= ' ' . $m[1];
					}
				}
			}
			$bucket = $state === GameScore::STATE_IN ? 0 : ($state === GameScore::STATE_PRE ? 1 : 2);
			$rows[] = [
				'sort' => [$bucket, $order++],
				'id' => $id,
				'league' => (string) $game->type,
				'type' => $game->bet_type == Game::BET_TYPE_OVER_UNDER ? 'ou' : 'spread',
				'state' => $state,
				'label' => $label,
				'kickoff' => Fmt::kickoff($game->date, $game->time, $ctx->now),
				'kickoff_at' => Fmt::iso($game->date . ' ' . $game->time),
				'away' => $away ? ($nfl ? $away->nickname : $away->team) : 'Away',
				'home' => $home ? ($nfl ? $home->nickname : $home->team) : 'Home',
				'away_color' => self::teamColor($away),
				'home_color' => self::teamColor($home),
				'away_score' => $score && $score->hasScore() ? (int) $score->away_score : null,
				'home_score' => $score && $score->hasScore() ? (int) $score->home_score : null,
				'line' => $game->bet_type == Game::BET_TYPE_OVER_UNDER
					? 'O/U ' . (float) $game->value
					: self::spreadLine($game),
				'my_option' => $my_option,
				'my_mult' => $my ? (int) $my['multiplier'] : 0,
				'my_label' => $my_label,
				'status' => $status,
				'decided' => $decided,
			];
		}
		usort($rows, function ($a, $b) {
			return $a['sort'] <=> $b['sort'];
		});
		foreach ($rows as &$row) {
			unset($row['sort']);
		}
		unset($row);
		return $rows;
	}

	/**
	 * @param Team|null $team
	 * @return string  '#rrggbb' from color_1, or a neutral token
	 */
	public static function teamColor($team)
	{
		if ($team && preg_match('/^[0-9a-fA-F]{6}$/', (string) $team->color_1)) {
			return '#' . strtolower($team->color_1);
		}
		return 'var(--fg-faint)';
	}

	/**
	 * "Texas −4.5": the favored side's option text, or the away line.
	 *
	 * @param Game $game
	 * @return string
	 */
	private static function spreadLine(Game $game)
	{
		foreach ([$game->option_1, $game->option_2] as $text) {
			if (preg_match('/^(.*?)\s*\((-[\d.]+)\)\s*$/', (string) $text, $m)) {
				return trim($m[1]) . ' ' . str_replace('-', "\u{2212}", $m[2]);
			}
		}
		return GameScore::optionLabel($game->option_1);
	}

	/**
	 * A small inline SVG sparkline of weekly points (0..55 scale).
	 *
	 * @param array $values  list of ['num', 'points']
	 * @param int $width
	 * @param int $height
	 * @return string
	 */
	public static function sparkline(array $values, $width = 280, $height = 64)
	{
		$n = sizeof($values);
		if ($n < 1) {
			return '';
		}
		$max = 55;
		foreach ($values as $v) {
			$max = max($max, (int) $v['points']);
		}
		$pad = 6;
		$w = $width - $pad * 2;
		$h = $height - $pad * 2;
		$points = [];
		foreach (array_values($values) as $i => $v) {
			$x = $pad + ($n > 1 ? $w * $i / ($n - 1) : $w / 2);
			$y = $pad + $h - ($h * (int) $v['points'] / $max);
			$points[] = [round($x, 1), round($y, 1), (int) $v['points'], (int) $v['num']];
		}
		$line = implode(' ', array_map(function ($p) {
			return $p[0] . ',' . $p[1];
		}, $points));
		$area = 'M' . $points[0][0] . ',' . ($pad + $h) . ' L' . str_replace(' ', ' L', $line) . ' L' . $points[$n - 1][0] . ',' . ($pad + $h) . ' Z';
		$label = 'Weekly points: ' . implode(', ', array_map(function ($p) {
			return 'week ' . $p[3] . ' ' . $p[2];
		}, $points));
		$svg = '<svg class="spark" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . h($label) . '">';
		$svg .= '<line class="spark-base" x1="' . $pad . '" x2="' . ($pad + $w) . '" y1="' . ($pad + $h) . '" y2="' . ($pad + $h) . '"/>';
		$svg .= '<path class="spark-area" d="' . $area . '"/>';
		$svg .= '<polyline class="spark-line" points="' . $line . '"/>';
		foreach ($points as $i => $p) {
			$svg .= '<circle class="spark-dot' . ($i === $n - 1 ? ' is-last' : '') . '" cx="' . $p[0] . '" cy="' . $p[1] . '" r="' . ($i === $n - 1 ? 3.5 : 2.5) . '"><title>Week ' . $p[3] . ': ' . $p[2] . ' pts</title></circle>';
		}
		$svg .= '</svg>';
		return $svg;
	}
}
