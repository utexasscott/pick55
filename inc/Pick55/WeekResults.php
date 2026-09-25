<?php

namespace Pick55;

use Pick55\Models\Week;

/**
 * Computes everything season/week/results.php shows for one week and one set
 * of players ("focus" users: all season players, or one pool), including the
 * "what if" win-probability enumeration over every undecided game.
 *
 * Results are cached (see Cache). The cache key carries a fingerprint of the
 * week's games, bets, and format (its id, the selected pool and its payout
 * rows), so any score update, pick change, or format edit produces a new key
 * and the stale entry is swept. No invalidation hooks are needed anywhere
 * else.
 */
class WeekResults
{
	// Integer sort key layout used by the prediction enumeration:
	// points << SHIFT_POINTS | right << SHIFT_RIGHT | bit_mult
	const SHIFT_POINTS = 24;
	const SHIFT_RIGHT = 16;

	/**
	 * @param Week $week
	 * @param array $focus_user_ids
	 * @param array $what_ifs_by_game_id  game_id => '1' | '2'
	 * @param int|null $pool_num  the selected pool's pool_num, null for the overall view
	 * @param array $options  see compute()
	 * @return array see compute()
	 */
	public static function get(Week $week, array $focus_user_ids, array $what_ifs_by_game_id = [], $pool_num = null, array $options = [])
	{
		$focus_user_ids = array_values(array_unique(array_map('intval', $focus_user_ids)));
		sort($focus_user_ids);
		$what_ifs = [];
		foreach ($what_ifs_by_game_id as $game_id => $option) {
			$what_ifs[(int) $game_id] = (string) $option;
		}
		ksort($what_ifs);
		$pool_num = $pool_num === null ? null : (int) $pool_num;
		$options = self::normalizeOptions($options);

		$fp = self::fingerprint($week, $pool_num);
		$variant = md5(json_encode([$focus_user_ids, $what_ifs, $options]));
		$prefix = 'results-' . $week->id . '-';
		$key = $prefix . $fp . '-' . $variant;

		return Cache::remember($key, $prefix . 'lock', function () use ($week, $focus_user_ids, $what_ifs, $pool_num, $options, $prefix, $fp) {
			$results = self::compute($week, $focus_user_ids, $what_ifs, $pool_num, $options);
			// Drop entries built from an older fingerprint of this week.
			Cache::forgetPrefix($prefix, $prefix . $fp . '-');
			return $results;
		});
	}

	/**
	 * Expected payout per player after each kickoff slot of the week, for the
	 * "how the money moved" chart on the results page: point 0 is before any
	 * game, point k is after every game in slots 1..k (games with the same
	 * date and time are one slot) with the later slots still undecided. The
	 * series stops at the last slot whose games, and all earlier games, are
	 * decided. Cached under the week's fingerprint like get().
	 *
	 * @param Week $week
	 * @param array $user_ids  every player in the standing
	 * @param array $pool_by_user_id  user_id => pool_num
	 * @return array [
	 *   'labels' => list of slot labels ('Start', 'Sat 11:00 AM', ...),
	 *   'series' => user_id => list of expected payouts, one per label,
	 *   'final' => bool  whether the last point is the finished week,
	 * ]
	 */
	public static function timeline(Week $week, array $user_ids, array $pool_by_user_id = [])
	{
		$user_ids = array_values(array_unique(array_map('intval', $user_ids)));
		sort($user_ids);
		$options = self::normalizeOptions(['pool_by_user_id' => $pool_by_user_id]);
		$fp = self::fingerprint($week, null);
		$prefix = 'results-' . $week->id . '-';
		$key = $prefix . $fp . '-timeline-' . md5(json_encode([$user_ids, $options]));

		return Cache::remember($key, $prefix . 'lock', function () use ($week, $user_ids, $options) {
			$q = $week->games()
				->orderBy('date', 'ASC')
				->orderBy('time', 'ASC');
			$slots = [];
			foreach ($q->get() as $game) {
				$slot = $game->date . ' ' . $game->time;
				$slots[$slot][] = ['id' => (int) $game->id, 'decided' => $game->correct_option != '0'];
			}
			$labels = ['Start'];
			$series = [];
			foreach ($user_ids as $user_id) {
				$series[$user_id] = [];
			}
			$remaining = [];
			foreach ($slots as $slot => $games) {
				foreach ($games as $g) {
					$remaining[] = $g['id'];
				}
			}
			$final = false;
			$step = 0;
			foreach (array_merge([null], array_keys($slots)) as $slot) {
				if ($slot !== null) {
					foreach ($slots[$slot] as $g) {
						if (!$g['decided']) {
							break 2;
						}
					}
					$remaining = array_values(array_diff($remaining, array_column($slots[$slot], 'id')));
					$labels[] = date('D g:i A', strtotime($slot));
				}
				$opts = $options;
				$opts['undecided_game_ids'] = $remaining;
				$results = self::compute($week, $user_ids, [], null, $opts);
				foreach ($results['stats_by_user_id'] as $u_user_id => $stats) {
					$series[(int) substr($u_user_id, 1)][$step] = round($stats['expected_payout'], 2);
				}
				$final = !sizeof($remaining);
				$step++;
			}
			return [
				'labels' => $labels,
				'series' => $series,
				'final' => $final,
			];
		});
	}

	/**
	 * @param array $options
	 * @return array  canonical form, safe to hash into a cache key
	 */
	private static function normalizeOptions(array $options)
	{
		$pool_by_user_id = [];
		if (!empty($options['pool_by_user_id'])) {
			foreach ($options['pool_by_user_id'] as $user_id => $pool_num) {
				$pool_by_user_id[(int) $user_id] = (int) $pool_num;
			}
			ksort($pool_by_user_id);
		}
		$undecided = [];
		if (!empty($options['undecided_game_ids'])) {
			$undecided = array_values(array_unique(array_map('intval', $options['undecided_game_ids'])));
			sort($undecided);
		}
		return [
			'pool_by_user_id' => $pool_by_user_id,
			'undecided_game_ids' => $undecided,
		];
	}

	/**
	 * A short hash of every stored value that can change the results: the
	 * week's games (score, teams, kickoff), every bet on those games, and the
	 * week's format (its id, the selected pool number, and the format's payout
	 * rows, which decide the paying places and point threshold). Three cheap
	 * aggregate queries.
	 *
	 * @param Week $week
	 * @param int|null $pool_num
	 * @return string
	 */
	public static function fingerprint(Week $week, $pool_num = null)
	{
		$games = DB::table('football_games')
			->where('football_week_id', '=', $week->id)
			->selectRaw("
				COUNT(*) AS n,
				IFNULL(BIT_XOR(CRC32(CONCAT_WS('|', id, correct_option, `date`, `time`, title, option_1, option_2))), 0) AS x,
				IFNULL(SUM(CRC32(CONCAT_WS('|', id, correct_option, `date`, `time`, title, option_1, option_2))), 0) AS s
			")
			->first();
		$bets = DB::table('football_bets AS b')
			->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
			->where('g.football_week_id', '=', $week->id)
			->selectRaw("
				COUNT(*) AS n,
				IFNULL(BIT_XOR(CRC32(CONCAT_WS('|', b.id, b.user_id, b.option, b.multiplier))), 0) AS x,
				IFNULL(SUM(CRC32(CONCAT_WS('|', b.id, b.user_id, b.option, b.multiplier))), 0) AS s
			")
			->first();
		$format_id = (int) $week->football_week_format_id;
		$payouts = DB::table('football_week_format_payouts')
			->where('football_week_format_id', '=', $format_id)
			->selectRaw("
				COUNT(*) AS n,
				IFNULL(BIT_XOR(CRC32(CONCAT_WS('|', id, place_type, IFNULL(pool_num, ''), min_place, IFNULL(max_place, ''), IFNULL(min_points, ''), IFNULL(payout, ''), IFNULL(total_payout, '')))), 0) AS x,
				IFNULL(SUM(CRC32(CONCAT_WS('|', id, place_type, IFNULL(pool_num, ''), min_place, IFNULL(max_place, ''), IFNULL(min_points, ''), IFNULL(payout, ''), IFNULL(total_payout, '')))), 0) AS s
			")
			->first();
		return substr(md5(json_encode([
			'v3',
			$format_id,
			$pool_num === null ? null : (int) $pool_num,
			(int) $payouts->n, (string) $payouts->x, (string) $payouts->s,
			(int) $games->n, (string) $games->x, (string) $games->s,
			(int) $bets->n, (string) $bets->x, (string) $bets->s,
		])), 0, 16);
	}

	/**
	 * Uncached computation.
	 *
	 * @param Week $week
	 * @param array $focus_user_ids
	 * @param array $what_ifs_by_game_id
	 * @param int|null $pool_num  the selected pool's pool_num, null for the overall view
	 * @param array $options [
	 *   'pool_by_user_id' => user_id => pool_num, so the overall view can pay
	 *       the format's pool rows as well as its overall rows,
	 *   'undecided_game_ids' => games to treat as undecided whatever their
	 *       stored result (the timeline chart),
	 * ]
	 * @return array [
	 *   'games' => list of football_games attribute arrays, kickoff order,
	 *   'bets_by_game_id' => game_id => list of ['id','user_id','option','multiplier'] for focus users,
	 *   'stats_by_user_id' => 'u<id>' => stats, sorted and ranked (no winnings),
	 *   'unknown_game_ids' => list, 'num_unknowns' => int, 'num_predictions' => int,
	 *   'show_auto_column' => bool,
	 *   'has_payouts' => bool  whether the format pays anything in this view,
	 * ]
	 * Each stats entry carries 'payout' (what the week pays the player if it
	 * ended with the current standing) and 'expected_payout' (the mean payout
	 * over every outcome of the undecided games). Both are computed only for
	 * the overall view (pool_num null); a pool view reads them from the
	 * overall results.
	 */
	public static function compute(Week $week, array $focus_user_ids, array $what_ifs_by_game_id = [], $pool_num = null, array $options = [])
	{
		$options = self::normalizeOptions($options);
		$num_winners = (int) $week->getNumWinners($pool_num);
		$threshold = (int) $week->getMinScoreThreshold($pool_num);
		$pretend_undecided = array_flip($options['undecided_game_ids']);

		// Payout tables, overall view only.
		$tables = null;
		$pool_of = [];
		if ($pool_num === null) {
			$pool_nums = array_values(array_unique($options['pool_by_user_id']));
			$tables = WeekPayouts::tables($week->getFormat(), sizeof($focus_user_ids), $pool_nums);
			if (!$tables['any']) {
				$tables = null;
			}
		}

		$stats_base = [
			'user_id' => null,
			'rank' => null,
			'points' => 0,
			'possible' => 0,
			'sort_score' => 0,
			'right' => 0,
			'wrong' => 0,
			'unknown' => 0,
			'bit_mult' => 0,
			'by_multiplier' => array_fill_keys(range(1, 10), 0),
			'prediction_ranks' => [],
			'prediction_ranks_pct' => [],
			'prediction_gte_threshold' => 0,
			'prediction_gte_threshold_pct' => 0,
			'either_threshold' => 0,
			'either_threshold_pct' => 0,
			'payout' => 0,
			'expected_payout' => 0,
		];
		foreach (range(1, max(1, $num_winners)) as $rank) {
			$stats_base['prediction_ranks']['r' . $rank] = 0;
			$stats_base['prediction_ranks_pct']['r' . $rank] = 0;
		}

		$stats_by_user_id = [];
		foreach ($focus_user_ids as $user_id) {
			$stats_by_user_id['u' . $user_id] = $stats_base;
			$stats_by_user_id['u' . $user_id]['user_id'] = $user_id;
		}

		// Games in kickoff order
		$games = [];
		$q = $week->games()
			->orderBy('date', 'ASC')
			->orderBy('time', 'ASC');
		foreach ($q->get() as $game) {
			$games[] = $game->getAttributes();
		}

		// Every focus user's bet on every game, one query
		$bets_by_game_id = [];
		foreach ($games as $game) {
			$bets_by_game_id[$game['id']] = [];
		}
		if (sizeof($focus_user_ids)) {
			$q = DB::table('football_bets AS b')
				->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
				->where('g.football_week_id', '=', $week->id)
				->whereIn('b.user_id', $focus_user_ids)
				->select(['b.id', 'b.user_id', 'b.football_game_id', 'b.option', 'b.multiplier'])
				->orderBy('b.football_game_id', 'ASC')
				->orderBy('b.id', 'ASC');
			foreach ($q->cursor() as $row) {
				if (!isset($bets_by_game_id[$row->football_game_id])) {
					continue;
				}
				$bets_by_game_id[$row->football_game_id][] = [
					'id' => (int) $row->id,
					'user_id' => (int) $row->user_id,
					'option' => (string) $row->option,
					'multiplier' => (int) $row->multiplier,
				];
			}
		}

		// Score the decided games (what-ifs count as decided)
		$show_auto_column = false;
		$num_unknowns = 0;
		$unknown_game_ids = [];
		foreach ($games as $game) {
			$game_id = $game['id'];
			$correct_option = $game['correct_option'];
			if (isset($pretend_undecided[(int) $game_id])) {
				$correct_option = '0';
			}
			if ($correct_option == '0') {
				$num_unknowns++;
				if (isset($what_ifs_by_game_id[$game_id])) {
					$correct_option = $what_ifs_by_game_id[$game_id];
				}
				else {
					$unknown_game_ids[] = $game_id;
				}
			}
			foreach ($bets_by_game_id[$game_id] as $bet) {
				$s = &$stats_by_user_id['u' . $bet['user_id']];
				$s['possible'] += $bet['multiplier'];
				if ($bet['option'] == '3') {
					$show_auto_column = true;
					$s['points'] += $bet['multiplier'];
					$s['sort_score'] += $bet['multiplier'] + pow(10, -(13 - $bet['multiplier'])) + 0.01;
					$s['right']++;
					$s['by_multiplier'][$bet['multiplier']] = 3;
					$s['bit_mult'] += pow(2, $bet['multiplier']);
				}
				elseif ($correct_option == '0') {
					$s['unknown']++;
				}
				elseif ($correct_option == $bet['option']) {
					$s['points'] += $bet['multiplier'];
					$s['sort_score'] += $bet['multiplier'] + pow(10, -(13 - $bet['multiplier'])) + 0.01;
					$s['right']++;
					$s['right_multipliers'][] = $bet['multiplier'];
					$s['by_multiplier'][$bet['multiplier']] = 1;
					$s['bit_mult'] += pow(2, $bet['multiplier']);
				}
				else {
					$s['wrong']++;
					$s['by_multiplier'][$bet['multiplier']] = -1;
				}
				unset($s);
			}
		}

		self::sortStats($stats_by_user_id);
		self::applyRank($stats_by_user_id);

		// What the week pays on the current standing
		if ($tables) {
			$order = [];
			foreach ($stats_by_user_id as $u_user_id => $s) {
				$order[$u_user_id] = self::key($s);
				$user_id = (int) substr($u_user_id, 1);
				if (isset($options['pool_by_user_id'][$user_id])) {
					$pool_of[$u_user_id] = $options['pool_by_user_id'][$user_id];
				}
			}
			arsort($order);
			foreach (WeekPayouts::assignAll($order, $tables, $pool_of) as $u_user_id => $amount) {
				$stats_by_user_id[$u_user_id]['payout'] = round($amount, 2);
				$stats_by_user_id[$u_user_id]['expected_payout'] = round($amount, 2);
			}
		}

		// Predictions: enumerate every outcome of the undecided games
		$num_predictions = 0;
		if (sizeof($unknown_game_ids)) {
			$num_predictions = pow(2, sizeof($unknown_game_ids));
			self::enumeratePredictions($stats_by_user_id, $bets_by_game_id, $unknown_game_ids, $num_winners, $threshold, $tables, $pool_of);
			foreach ($stats_by_user_id as $u_user_id => $stats) {
				if ($tables) {
					$stats_by_user_id[$u_user_id]['expected_payout'] = round($stats['expected_payout'] / $num_predictions, 2);
				}
				foreach (range(1, max(1, $num_winners)) as $rank) {
					$tmp_rank = $stats['prediction_ranks']['r' . $rank];
					$stats_by_user_id[$u_user_id]['prediction_ranks_pct']['r' . $rank] = round($tmp_rank / $num_predictions * 100, 3);
				}
				if ($threshold) {
					$tmp_num = $stats['prediction_gte_threshold'];
					$stats_by_user_id[$u_user_id]['prediction_gte_threshold_pct'] = round($tmp_num / $num_predictions * 100, 3);
					$tmp_num = $stats['either_threshold'];
					$stats_by_user_id[$u_user_id]['either_threshold_pct'] = round($tmp_num / $num_predictions * 100, 3);
				}
			}
		}

		return [
			'games' => $games,
			'bets_by_game_id' => $bets_by_game_id,
			'stats_by_user_id' => $stats_by_user_id,
			'unknown_game_ids' => $unknown_game_ids,
			'num_unknowns' => $num_unknowns,
			'num_predictions' => $num_predictions,
			'show_auto_column' => $show_auto_column,
			'has_payouts' => (bool) $tables,
		];
	}

	/**
	 * @param array $stats
	 * @return int  the integer sort key of one player's standing
	 */
	public static function key(array $stats)
	{
		return ((int) $stats['points'] << self::SHIFT_POINTS)
			+ ((int) $stats['right'] << self::SHIFT_RIGHT)
			+ (int) $stats['bit_mult'];
	}

	/**
	 * Walks all 2^n outcomes of the undecided games in Gray-code order, so each
	 * step flips one game and adjusts every user's integer sort key by a
	 * precomputed delta. Rank ties are exact ties on (points, right, bit_mult),
	 * which is what the float sort_score comparison expresses.
	 *
	 * Fills prediction_ranks, prediction_gte_threshold and either_threshold,
	 * and, when payout tables are given, sums each player's payout over every
	 * outcome into expected_payout (the caller divides by the outcome count).
	 *
	 * @param array $stats_by_user_id  by reference
	 * @param array $bets_by_game_id
	 * @param array $unknown_game_ids
	 * @param int $num_winners
	 * @param int $threshold
	 * @param array|null $tables  WeekPayouts::tables(), or null for no payouts
	 * @param array $pool_of  'u<id>' => pool_num
	 */
	private static function enumeratePredictions(array &$stats_by_user_id, array $bets_by_game_id, array $unknown_game_ids, $num_winners, $threshold, array $tables = null, array $pool_of = [])
	{
		$u_keys = array_keys($stats_by_user_id);
		$num_users = sizeof($u_keys);
		if (!$num_users) {
			return;
		}
		$index_of = array_flip($u_keys);

		$keys = [];
		$pool_of_index = [];
		foreach ($u_keys as $i => $u_user_id) {
			$keys[$i] = self::key($stats_by_user_id[$u_user_id]);
			if (isset($pool_of[$u_user_id])) {
				$pool_of_index[$i] = $pool_of[$u_user_id];
			}
		}

		// diff[j][i]: key change for user i when game j flips from option 2 winning to option 1 winning
		$diffs = [];
		foreach ($unknown_game_ids as $j => $game_id) {
			$diff = array_fill(0, $num_users, 0);
			foreach ($bets_by_game_id[$game_id] as $bet) {
				if ($bet['option'] != '1' && $bet['option'] != '2') {
					continue;
				}
				$i = $index_of['u' . $bet['user_id']];
				$delta = ((int) $bet['multiplier'] << self::SHIFT_POINTS)
					+ (1 << self::SHIFT_RIGHT)
					+ (1 << (int) $bet['multiplier']);
				if ($bet['option'] == '1') {
					$diff[$i] += $delta;
				}
				else {
					// Start state is "option 2 wins" for every undecided game
					$keys[$i] += $delta;
					$diff[$i] -= $delta;
				}
			}
			$diffs[$j] = $diff;
		}

		$num_games = sizeof($unknown_game_ids);
		$num_states = 1 << $num_games;
		$winners = min($num_winners, $num_users);
		$ranks = array_fill(0, $num_users, array_fill(1, max(1, $winners), 0));
		$gte = array_fill(0, $num_users, 0);
		$either = array_fill(0, $num_users, 0);
		$expected = array_fill(0, $num_users, 0);

		for ($state = 0; $state < $num_states; $state++) {
			if ($state) {
				// Flip the lowest set bit of $state (Gray code step)
				$j = 0;
				while (!(($state >> $j) & 1)) {
					$j++;
				}
				$gray = $state ^ ($state >> 1);
				$diff = $diffs[$j];
				if (($gray >> $j) & 1) {
					foreach ($diff as $i => $d) {
						if ($d) $keys[$i] += $d;
					}
				}
				else {
					foreach ($diff as $i => $d) {
						if ($d) $keys[$i] -= $d;
					}
				}
			}

			// Competition rank: 1 + number of users with a strictly greater key.
			// Walk the standing in tie groups; a group whose rank is within the
			// paying places is "in the top" whatever its size.
			$order = $keys;
			arsort($order);
			$place = 1;
			$group_key = null;
			$group_rank = 1;
			foreach ($order as $i => $k) {
				if ($k !== $group_key) {
					$group_key = $k;
					$group_rank = $place;
				}
				$place++;
				$in_top = $group_rank <= $winners;
				if ($threshold) {
					if (($k >> self::SHIFT_POINTS) >= $threshold) {
						$gte[$i]++;
						$either[$i]++;
					}
					elseif ($in_top) {
						$either[$i]++;
					}
				}
				if ($in_top) {
					$ranks[$i][$group_rank]++;
				}
			}
			if ($tables) {
				foreach (WeekPayouts::assignAll($order, $tables, $pool_of_index) as $i => $amount) {
					$expected[$i] += $amount;
				}
			}
		}

		foreach ($u_keys as $i => $u_user_id) {
			foreach ($ranks[$i] as $rank => $n) {
				if (isset($stats_by_user_id[$u_user_id]['prediction_ranks']['r' . $rank])) {
					$stats_by_user_id[$u_user_id]['prediction_ranks']['r' . $rank] = $n;
				}
			}
			$stats_by_user_id[$u_user_id]['prediction_gte_threshold'] = $gte[$i];
			$stats_by_user_id[$u_user_id]['either_threshold'] = $either[$i];
			$stats_by_user_id[$u_user_id]['expected_payout'] = $expected[$i];
		}
	}

	/**
	 * Sort by points, then number correct, then bit_mult. Keys must be the
	 * string 'u<user_id>' so array_multisort keeps them.
	 *
	 * @param array $stats_input
	 */
	public static function sortStats(array &$stats_input)
	{
		$sort_pts = [];
		$sort_correct = [];
		$sort_bit_mult = [];
		foreach ($stats_input as $u_user_id => $stats) {
			$sort_pts[$u_user_id] = $stats['points'];
			$sort_correct[$u_user_id] = $stats['right'];
			$sort_bit_mult[$u_user_id] = $stats['bit_mult'];
		}
		array_multisort(
			$sort_pts,
			SORT_DESC,
			$sort_correct,
			SORT_DESC,
			$sort_bit_mult,
			SORT_DESC,
			$stats_input
		);
	}

	/**
	 * Competition ranking over an already sorted stats array; ties are equal sort_score.
	 *
	 * @param array $stats_input
	 */
	public static function applyRank(array &$stats_input)
	{
		$rank = 0;
		$prev_score = 0;
		$tied = 0;
		foreach ($stats_input as $user_id => $stats) {
			if ($stats_input[$user_id]['sort_score'] != $prev_score) {
				$rank++;
				$rank += $tied;
				$tied = 0;
			}
			else {
				$tied++;
				if ($rank == 0) {
					$rank = 1;
					$tied = 0;
				}
			}
			$prev_score = $stats_input[$user_id]['sort_score'];
			$stats_input[$user_id]['rank'] = $rank;
		}
	}
}
