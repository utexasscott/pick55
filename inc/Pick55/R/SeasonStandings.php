<?php

namespace Pick55\R;

use Pick55\Cache;
use Pick55\DB;
use Pick55\Models\Game;
use Pick55\Models\Season;

/**
 * The season standings (r/season/standings.php), with exactly the meaning of
 * the classic season/standings.php, computed with a few grouped queries
 * instead of one query per player, and cached. Also the viewer's week
 * timeline and points breakdown for My Season (r/season/index.php), and the
 * season picker both pages share (picker()).
 *
 * The standings rule (identical to the classic page):
 *   - regular-season weeks only: a week counts unless its format has
 *     is_playoffs = 1 (a week without a format counts as regular season);
 *   - only games with a result (correct_option <> '0');
 *   - roster: paid players while the season is active, every linked player
 *     once it is not (Season::getPlayers($paid_only));
 *   - a guaranteed pick (option '3') counts as right and its points as `auto`;
 *     any other pick that is not the result (including no side, option '0')
 *     counts as wrong;
 *   - `max` is the sum of the multipliers of every counted bet;
 *   - rank by points, then number right (then user id, which is where the
 *     classic array_multisort breaks a full tie);
 *   - winnings are the sum of the player's football_week_winners rows for
 *     the season's weeks (every week, playoffs included).
 *
 * get() returns [
 *   'season_id' => int,
 *   'is_active' => bool,
 *   'generated_at' => 'Y-m-d H:i:s',
 *   'has_playoffs' => bool  any week of the season has a playoff format,
 *   'weeks' => list of counted regular-season weeks (at least one decided
 *              game), by week_num: [num, id, name, games, decided, complete (every game decided)],
 *   'completed_week_nums' => list of week_num whose every game is decided,
 *   'order' => list of user_id in rank order,
 *   'players' => user_id => [
 *     user_id, rank, bets, right, wrong, points, auto, max,
 *     multipliers => [0..10 => right non-guaranteed picks at that value],
 *     right_nfl, wrong_nfl, points_nfl, points_wrong_nfl, (same for _ncaa, _ou, _spread),
 *     winnings, free_points,
 *     score_by_week_num => week_num => points (weeks the player has a counted bet in),
 *     right_by_week_num => week_num => right,
 *     rank_by_week_num => week_num => rank on the cumulative standings through that week (every counted week),
 *   ],
 *   'totals' => the classic stats_total: the sums of every numeric stat above
 *               (+ multipliers), max_user_points, min_user_points, num_users,
 *   'free_points_by_rank' => rank => points (the classic table),
 *   'week_scores_count' => score => number of player-weeks with that score, 0..max(55, top score),
 *   'leader_points' => int,
 * ]
 */
class SeasonStandings
{
	const FREE_POINTS_BY_RANK = [
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
		12 => 10,
	];

	private function __construct() {}

	/**
	 * @param Season $season
	 * @return array see the class docblock
	 */
	public static function get(Season $season)
	{
		$prefix = 'standings-' . (int) $season->id . '-';
		$key = $prefix . self::fingerprint($season);
		return Cache::remember($key, $prefix . 'lock', function () use ($season, $prefix, $key) {
			$data = self::compute($season);
			Cache::forgetPrefix($prefix, $key);
			return $data;
		});
	}

	/**
	 * A short hash over everything of this season the standings depend on:
	 * its bets, games, weeks, formats, winners, roster links and the season row.
	 *
	 * @param Season $season
	 * @return string
	 */
	public static function fingerprint(Season $season)
	{
		$sid = (int) $season->id;
		$parts = ['v1', $sid];
		$agg = function ($q, $expr) use (&$parts) {
			$row = $q->selectRaw("COUNT(*) AS n, IFNULL(BIT_XOR(CRC32($expr)), 0) AS x, IFNULL(SUM(CRC32($expr)), 0) AS s")->first();
			$parts[] = [(int) $row->n, (string) $row->x, (string) $row->s];
		};
		$agg(
			DB::table('football_bets AS b')
				->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
				->join('football_weeks AS w', 'w.id', '=', 'g.football_week_id')
				->where('w.football_season_id', '=', $sid),
			"CONCAT_WS('|', b.id, b.user_id, b.football_game_id, b.`option`, b.multiplier)"
		);
		$agg(
			DB::table('football_games AS g')
				->join('football_weeks AS w', 'w.id', '=', 'g.football_week_id')
				->where('w.football_season_id', '=', $sid),
			"CONCAT_WS('|', g.id, g.football_week_id, IFNULL(g.type, ''), IFNULL(g.bet_type, ''), g.correct_option)"
		);
		$agg(
			DB::table('football_weeks AS w')
				->leftJoin('football_week_formats AS f', 'f.id', '=', 'w.football_week_format_id')
				->where('w.football_season_id', '=', $sid),
			"CONCAT_WS('|', w.id, w.week_num, IFNULL(w.football_week_format_id, ''), IFNULL(f.name, ''), IFNULL(f.is_playoffs, ''))"
		);
		$agg(
			DB::table('football_week_winners AS ww')
				->join('football_weeks AS w', 'w.id', '=', 'ww.week_id')
				->where('w.football_season_id', '=', $sid),
			"CONCAT_WS('|', ww.id, ww.week_id, ww.er_user_id, ww.amount)"
		);
		$agg(
			DB::table('football_users_seasons')->where('football_season_id', '=', $sid),
			"CONCAT_WS('|', id, er_user_id, IFNULL(paid_at, ''))"
		);
		$parts[] = (int) $season->is_active;
		return substr(md5(json_encode($parts)), 0, 16);
	}

	/**
	 * @return array  the classic page's per-player stats, zeroed
	 */
	private static function base()
	{
		return [
			'user_id' => null,
			'bets' => 0,
			'right' => 0,
			'wrong' => 0,
			'points' => 0,
			'auto' => 0,
			'max' => 0,
			'multipliers' => array_fill_keys(range(0, 10), 0),
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
	}

	/**
	 * Uncached computation. Seven queries: roster (two), weeks, winnings,
	 * bets by value/league/type, bets by week, and nothing per player.
	 *
	 * @param Season $season
	 * @return array
	 */
	public static function compute(Season $season)
	{
		$sid = (int) $season->id;
		$is_active = (bool) $season->is_active;

		// Roster, as the classic page: Season::getPlayers($paid_only).
		$players = [];
		foreach ($season->getPlayers($is_active) as $user) {
			$s = self::base();
			$s['user_id'] = (int) $user->id;
			$s['score_by_week_num'] = [];
			$s['right_by_week_num'] = [];
			$s['rank_by_week_num'] = [];
			$players[(int) $user->id] = $s;
		}

		// Weeks: regular-season weeks with a decided game, and whether any week is a playoff week.
		$weeks = [];
		$completed = [];
		$has_playoffs = false;
		$q = DB::table('football_weeks AS w')
			->leftJoin('football_week_formats AS f', 'f.id', '=', 'w.football_week_format_id')
			->leftJoin('football_games AS g', 'g.football_week_id', '=', 'w.id')
			->where('w.football_season_id', '=', $sid)
			->groupBy('w.id', 'w.week_num', 'f.id', 'f.name', 'f.is_playoffs')
			->orderBy('w.week_num')
			->orderBy('w.id')
			->selectRaw("
				w.id, w.week_num, f.id AS format_id, f.name AS format_name, f.is_playoffs,
				COUNT(g.id) AS games,
				IFNULL(SUM(g.correct_option <> '0'), 0) AS decided
			");
		foreach ($q->get() as $row) {
			if ($row->format_id !== null && (int) $row->is_playoffs) {
				$has_playoffs = true;
				continue;
			}
			if ((int) $row->decided < 1) {
				continue;
			}
			$num = (int) $row->week_num;
			$complete = (int) $row->decided >= (int) $row->games;
			$weeks[$num] = [
				'num' => $num,
				'id' => (int) $row->id,
				'name' => $row->format_name !== null && strlen($row->format_name) ? (string) $row->format_name : 'Week ' . $num,
				'games' => (int) $row->games,
				'decided' => (int) $row->decided,
				'complete' => $complete,
			];
			if ($complete) {
				$completed[] = $num;
			}
		}
		ksort($weeks);

		// Winnings (every week of the season).
		$q = DB::table('football_week_winners AS winner')
			->join('football_weeks AS week', 'winner.week_id', '=', 'week.id')
			->where('week.football_season_id', '=', $sid)
			->groupBy('winner.er_user_id')
			->selectRaw('winner.er_user_id, SUM(winner.amount) AS amount');
		foreach ($q->get() as $row) {
			$uid = (int) $row->er_user_id;
			if (isset($players[$uid])) {
				$players[$uid]['winnings'] += (float) $row->amount;
			}
		}

		$counted = function ($q) use ($sid) {
			return $q->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
				->join('football_weeks AS w', 'w.id', '=', 'g.football_week_id')
				->leftJoin('football_week_formats AS f', 'f.id', '=', 'w.football_week_format_id')
				->where('w.football_season_id', '=', $sid)
				->where('g.correct_option', '!=', '0')
				// A week without a format counts as regular season.
				->whereRaw('(f.is_playoffs = 0 OR f.id IS NULL)');
		};

		// Bets by value, league and bet type.
		$q = $counted(DB::table('football_bets AS b'))
			->groupBy('b.user_id', 'g.type', 'g.bet_type', 'b.multiplier')
			->selectRaw("
				b.user_id, g.type, g.bet_type, b.multiplier,
				COUNT(*) AS n,
				SUM(b.`option` = '3') AS auto_n,
				SUM(b.`option` <> '3' AND b.`option` = g.correct_option) AS right_n,
				SUM(b.`option` <> '3' AND b.`option` <> g.correct_option) AS wrong_n
			");
		foreach ($q->get() as $row) {
			$uid = (int) $row->user_id;
			if (!isset($players[$uid])) {
				continue;
			}
			$s = &$players[$uid];
			$m = (int) $row->multiplier;
			$n = (int) $row->n;
			$auto = (int) $row->auto_n;
			$right = (int) $row->right_n + $auto;
			$wrong = (int) $row->wrong_n;
			$league = $row->type == Game::LEAGUE_NFL ? 'nfl' : 'ncaa';
			$type = $row->bet_type == Game::BET_TYPE_OVER_UNDER ? 'ou' : 'spread';

			$s['bets'] += $n;
			$s['max'] += $m * $n;
			$s['right'] += $right;
			$s['wrong'] += $wrong;
			$s['points'] += $m * $right;
			$s['auto'] += $m * $auto;
			if (!isset($s['multipliers'][$m])) {
				$s['multipliers'][$m] = 0;
			}
			$s['multipliers'][$m] += (int) $row->right_n;
			foreach ([$league, $type] as $k) {
				$s['right_' . $k] += $right;
				$s['points_' . $k] += $m * $right;
				$s['wrong_' . $k] += $wrong;
				$s['points_wrong_' . $k] += $m * $wrong;
			}
			unset($s);
		}

		// Bets by week.
		$q = $counted(DB::table('football_bets AS b'))
			->groupBy('b.user_id', 'w.week_num')
			->selectRaw("
				b.user_id, w.week_num,
				SUM(IF(b.`option` = '3' OR b.`option` = g.correct_option, b.multiplier, 0)) AS points,
				SUM(b.`option` = '3' OR b.`option` = g.correct_option) AS right_n
			");
		foreach ($q->get() as $row) {
			$uid = (int) $row->user_id;
			if (!isset($players[$uid])) {
				continue;
			}
			$num = (int) $row->week_num;
			$players[$uid]['score_by_week_num'][$num] = (int) $row->points;
			$players[$uid]['right_by_week_num'][$num] = (int) $row->right_n;
		}
		foreach ($players as &$s) {
			ksort($s['score_by_week_num']);
			ksort($s['right_by_week_num']);
		}
		unset($s);

		// Rank now, and after each counted week (cumulative through it).
		$order = self::rankOrder($players, null);
		foreach ($order as $i => $uid) {
			$rank = $i + 1;
			$players[$uid]['rank'] = $rank;
			if (isset(self::FREE_POINTS_BY_RANK[$rank])) {
				$players[$uid]['free_points'] = self::FREE_POINTS_BY_RANK[$rank];
			}
		}
		foreach (array_keys($weeks) as $num) {
			foreach (self::rankOrder($players, $num) as $i => $uid) {
				$players[$uid]['rank_by_week_num'][$num] = $i + 1;
			}
		}
		$sorted = [];
		foreach ($order as $uid) {
			$sorted[$uid] = $players[$uid];
		}
		$players = $sorted;

		// Totals, as the classic stats_total.
		$totals = array_merge(self::base(), [
			'max_user_points' => null,
			'min_user_points' => null,
			'num_users' => sizeof($players),
		]);
		unset($totals['user_id']);
		foreach ($players as $s) {
			foreach ($s as $k => $v) {
				if (!array_key_exists($k, $totals) || in_array($k, ['max_user_points', 'min_user_points', 'num_users'], true)) {
					continue;
				}
				if (is_array($v)) {
					foreach ($v as $k2 => $v2) {
						if (!isset($totals[$k][$k2])) {
							$totals[$k][$k2] = 0;
						}
						$totals[$k][$k2] += $v2;
					}
				}
				else {
					$totals[$k] += $v;
				}
			}
			if ($totals['max_user_points'] === null || $s['points'] > $totals['max_user_points']) {
				$totals['max_user_points'] = $s['points'];
			}
			if ($totals['min_user_points'] === null || $s['points'] < $totals['min_user_points']) {
				$totals['min_user_points'] = $s['points'];
			}
		}

		// Weekly score distribution.
		$counts = [];
		foreach ($players as $s) {
			foreach ($s['score_by_week_num'] as $score) {
				$counts[$score] = (isset($counts[$score]) ? $counts[$score] : 0) + 1;
			}
		}
		$top = 55;
		if (sizeof($counts)) {
			$top = max($top, max(array_keys($counts)));
		}
		foreach (range(0, $top) as $score) {
			if (!isset($counts[$score])) {
				$counts[$score] = 0;
			}
		}
		ksort($counts);

		$first = reset($players);
		return [
			'season_id' => $sid,
			'is_active' => $is_active,
			'generated_at' => date('Y-m-d H:i:s'),
			'has_playoffs' => $has_playoffs,
			'weeks' => array_values($weeks),
			'completed_week_nums' => $completed,
			'order' => $order,
			'players' => $players,
			'totals' => $totals,
			'free_points_by_rank' => self::FREE_POINTS_BY_RANK,
			'week_scores_count' => $counts,
			'leader_points' => $first ? (int) $first['points'] : 0,
		];
	}

	/**
	 * @param array $players  user_id => stats with score_by_week_num / right_by_week_num
	 * @param int|null $through  count only weeks up to this week_num; null for all
	 * @return array list of user_id in rank order (points desc, right desc, user_id asc)
	 */
	private static function rankOrder(array $players, $through)
	{
		$pts = [];
		$right = [];
		$ids = [];
		foreach ($players as $uid => $s) {
			if ($through === null) {
				$p = $s['points'];
				$r = $s['right'];
			}
			else {
				$p = 0;
				$r = 0;
				foreach ($s['score_by_week_num'] as $num => $v) {
					if ($num <= $through) {
						$p += $v;
						$r += $s['right_by_week_num'][$num];
					}
				}
			}
			$pts[] = $p;
			$right[] = $r;
			$ids[] = (int) $uid;
		}
		if (!sizeof($ids)) {
			return [];
		}
		array_multisort($pts, SORT_DESC, $right, SORT_DESC, $ids, SORT_ASC);
		return $ids;
	}

	// ------------------------------------------------------------------
	// Shared markup

	/**
	 * The season picker for the standings and My Season pages: a segmented
	 * control of links for up to four seasons, a select (changed by
	 * season.js, a GET form without JS) for more. '' for a single season.
	 *
	 * @param Shell $shell
	 * @param Season $season  the season shown
	 * @param int $user_id
	 * @param string $rel  the page, e.g. 'season/standings.php'
	 * @return string HTML
	 */
	public static function picker(Shell $shell, Season $season, $user_id, $rel)
	{
		$seasons = Season::getListForUser((int) $user_id);
		if (!isset($seasons[$season->id])) {
			$seasons[$season->id] = $season;
		}
		if (sizeof($seasons) <= 1) {
			return '';
		}
		ob_start();
		if (sizeof($seasons) <= 4): ?>
			<nav class="seg seg-sm season-seg" aria-label="Season">
				<?php foreach ($seasons as $s): ?>
					<a href="<?=h($shell->link($rel . '?id=' . (int) $s->id))?>"<?=(int) $s->id === (int) $season->id ? ' aria-current="page"' : ''?>><?=h($s->name)?></a>
				<?php endforeach; ?>
			</nav>
		<?php else: ?>
			<form class="season-pick" method="get" action="<?=h($shell->link($rel))?>" data-season-pick>
				<label class="sr-only" for="season-select">Season</label>
				<select class="select" id="season-select" name="id">
					<?php foreach ($seasons as $s): ?>
						<option value="<?=(int) $s->id?>"<?=(int) $s->id === (int) $season->id ? ' selected' : ''?>><?=h($s->name)?></option>
					<?php endforeach; ?>
				</select>
				<button class="btn btn-sm btn-ghost season-go" type="submit">Go</button>
			</form>
		<?php endif;
		return ob_get_clean();
	}

	// ------------------------------------------------------------------
	// My Season

	/**
	 * One row per week of the season for a player, with the classic My
	 * Season semantics: state from canUserPick / canUserSeeResults / picks
	 * open date; rank as Week::getUserRank (every bettor of the week, right
	 * non-guaranteed picks only, by points, number right, then the value
	 * bitmask); points as Week::getUserScore; results per value 1..10 as
	 * Week::getUserPickResult (-1 wrong, 0 undecided or no pick, 1 right,
	 * 3 guaranteed). Four queries in all.
	 *
	 * @param Season $season
	 * @param int $user_id
	 * @param int|null $now
	 * @return array list of [id, num, name, is_playoffs, games, undecided, opens_at, first_game_at,
	 *   state (pick|live|complete|upcoming|tbd), rank (0 = none), points (int), right, wrong,
	 *   results => [10 => r, ..., 1 => r]]
	 */
	public static function timeline(Season $season, $user_id, $now = null)
	{
		$now = $now === null ? time() : (int) $now;
		$user_id = (int) $user_id;
		$weeks = $season->weeks()->with('format')->get();
		if (!sizeof($weeks)) {
			return [];
		}
		$ids = [];
		foreach ($weeks as $week) {
			$ids[] = (int) $week->id;
		}

		$agg = [];
		$q = DB::table('football_games')
			->whereIn('football_week_id', $ids)
			->groupBy('football_week_id')
			->selectRaw("
				football_week_id AS week_id,
				COUNT(*) AS n,
				SUM(correct_option = '0') AS undecided,
				MIN(CONCAT(`date`, ' ', IFNULL(`time`, '00:00:00'))) AS first_at
			");
		foreach ($q->get() as $row) {
			$agg[(int) $row->week_id] = $row;
		}

		// Week::getUserRank for every week at once.
		$ranks = [];
		$q = DB::table('football_bets AS b')
			->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
			->whereIn('g.football_week_id', $ids)
			->where('g.correct_option', '!=', '0')
			->whereRaw('b.`option` = g.correct_option')
			->groupBy('g.football_week_id', 'b.user_id')
			->selectRaw('
				g.football_week_id AS week_id, b.user_id,
				SUM(b.multiplier) AS points,
				COUNT(*) AS num_correct,
				SUM(POWER(2, b.multiplier)) AS bit_mult
			');
		$by_week = [];
		foreach ($q->get() as $row) {
			$by_week[(int) $row->week_id][] = [(int) $row->points, (int) $row->num_correct, (float) $row->bit_mult, (int) $row->user_id];
		}
		$scores = [];
		foreach ($by_week as $wid => $rows) {
			usort($rows, function ($a, $b) {
				if ($a[0] !== $b[0]) {
					return $b[0] - $a[0];
				}
				if ($a[1] !== $b[1]) {
					return $b[1] - $a[1];
				}
				if ($a[2] != $b[2]) {
					return $a[2] < $b[2] ? 1 : -1;
				}
				return $a[3] - $b[3];
			});
			foreach ($rows as $i => $r) {
				if ($r[3] === $user_id) {
					$ranks[$wid] = $i + 1;
					$scores[$wid] = $r[0];
					break;
				}
			}
		}

		// Week::getUserPickResult for every week and value at once.
		$results = [];
		$right = [];
		$wrong = [];
		$q = DB::table('football_bets AS b')
			->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
			->whereIn('g.football_week_id', $ids)
			->where('b.user_id', '=', $user_id)
			->orderBy('b.id')
			->selectRaw("
				g.football_week_id AS week_id, b.multiplier,
				IF(b.`option` = '3', 3, IF(g.correct_option = '0', 0, IF(g.correct_option = b.`option`, 1, -1))) AS result
			");
		foreach ($q->get() as $row) {
			$wid = (int) $row->week_id;
			$m = (int) $row->multiplier;
			$r = (int) $row->result;
			if ($m >= 1 && $m <= 10 && !isset($results[$wid][$m])) {
				$results[$wid][$m] = $r;
			}
			if ($r === 1 || $r === 3) {
				$right[$wid] = (isset($right[$wid]) ? $right[$wid] : 0) + 1;
			}
			elseif ($r === -1) {
				$wrong[$wid] = (isset($wrong[$wid]) ? $wrong[$wid] : 0) + 1;
			}
		}

		$is_player = $season->hasPlayer($user_id);
		$rows = [];
		foreach ($weeks as $week) {
			$wid = (int) $week->id;
			$row = isset($agg[$wid]) ? $agg[$wid] : null;
			$games = $row ? (int) $row->n : 0;
			$first = ($row && $row->first_at && strtotime($row->first_at)) ? date('Y-m-d H:i:s', strtotime($row->first_at)) : null;
			$opens = $week->getPicksAvailableAt();
			// Week::canPick() / canSeeResults(), from the aggregate.
			$can_pick = $is_player && $opens && $first && strtotime($opens) <= $now && strtotime($first) >= $now;
			$can_see = $is_player && $games > 0 && $first && strtotime($first) <= $now;
			$undecided = $row ? (int) $row->undecided : 0;
			if ($can_pick) {
				$state = 'pick';
			}
			elseif ($can_see) {
				$state = $undecided > 0 ? 'live' : 'complete';
			}
			elseif ($opens) {
				$state = 'upcoming';
			}
			else {
				$state = 'tbd';
			}
			$res = [];
			foreach (range(10, 1) as $m) {
				$res[$m] = isset($results[$wid][$m]) ? $results[$wid][$m] : 0;
			}
			$rows[] = [
				'id' => $wid,
				'num' => (int) $week->week_num,
				'name' => $week->getName(),
				'is_playoffs' => $week->isPlayoffs(),
				'games' => $games,
				'undecided' => $undecided,
				'opens_at' => $opens,
				'first_game_at' => $first,
				'state' => $state,
				'rank' => isset($ranks[$wid]) ? $ranks[$wid] : 0,
				'points' => isset($scores[$wid]) ? $scores[$wid] : 0,
				'right' => isset($right[$wid]) ? $right[$wid] : 0,
				'wrong' => isset($wrong[$wid]) ? $wrong[$wid] : 0,
				'results' => $res,
			];
		}
		return $rows;
	}

	/**
	 * The classic My Season "Points Breakdown": points from right picks
	 * (guaranteed picks excluded, every week including playoffs) by league
	 * and by bet type. One query.
	 *
	 * @param Season $season
	 * @param int $user_id
	 * @return array ['nfl', 'ncaa', 'ou', 'spread'] => points
	 */
	public static function breakdown(Season $season, $user_id)
	{
		$out = ['nfl' => 0, 'ncaa' => 0, 'ou' => 0, 'spread' => 0];
		$q = DB::table('football_bets AS b')
			->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
			->join('football_weeks AS w', 'w.id', '=', 'g.football_week_id')
			->where('w.football_season_id', '=', (int) $season->id)
			->where('b.user_id', '=', (int) $user_id)
			->where('g.correct_option', '!=', '0')
			->whereRaw('b.`option` = g.correct_option')
			->groupBy('g.type', 'g.bet_type')
			->selectRaw('g.type, g.bet_type, SUM(b.multiplier) AS points');
		foreach ($q->get() as $row) {
			if ($row->type === Game::LEAGUE_NFL) {
				$out['nfl'] += (int) $row->points;
			}
			elseif ($row->type === Game::LEAGUE_NCAA) {
				$out['ncaa'] += (int) $row->points;
			}
			if ($row->bet_type === Game::BET_TYPE_OVER_UNDER) {
				$out['ou'] += (int) $row->points;
			}
			elseif ($row->bet_type === Game::BET_TYPE_SPREAD) {
				$out['spread'] += (int) $row->points;
			}
		}
		return $out;
	}
}
