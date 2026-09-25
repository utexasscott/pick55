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
	 * @return array see compute()
	 */
	public static function get(Week $week, array $focus_user_ids, array $what_ifs_by_game_id = [], $pool_num = null)
	{
		$focus_user_ids = array_values(array_unique(array_map('intval', $focus_user_ids)));
		sort($focus_user_ids);
		$what_ifs = [];
		foreach ($what_ifs_by_game_id as $game_id => $option) {
			$what_ifs[(int) $game_id] = (string) $option;
		}
		ksort($what_ifs);
		$pool_num = $pool_num === null ? null : (int) $pool_num;

		$fp = self::fingerprint($week, $pool_num);
		$variant = md5(json_encode([$focus_user_ids, $what_ifs]));
		$prefix = 'results-' . $week->id . '-';
		$key = $prefix . $fp . '-' . $variant;

		return Cache::remember($key, $prefix . 'lock', function () use ($week, $focus_user_ids, $what_ifs, $pool_num, $prefix, $fp) {
			$results = self::compute($week, $focus_user_ids, $what_ifs, $pool_num);
			// Drop entries built from an older fingerprint of this week.
			Cache::forgetPrefix($prefix, $prefix . $fp . '-');
			return $results;
		});
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
			'v2',
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
	 * @return array [
	 *   'games' => list of football_games attribute arrays, kickoff order,
	 *   'bets_by_game_id' => game_id => list of ['id','user_id','option','multiplier'] for focus users,
	 *   'stats_by_user_id' => 'u<id>' => stats, sorted and ranked (no winnings),
	 *   'unknown_game_ids' => list, 'num_unknowns' => int, 'num_predictions' => int,
	 *   'show_auto_column' => bool,
	 * ]
	 */
	public static function compute(Week $week, array $focus_user_ids, array $what_ifs_by_game_id = [], $pool_num = null)
	{
		$num_winners = (int) $week->getNumWinners($pool_num);
		$threshold = (int) $week->getMinScoreThreshold($pool_num);

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

		// Predictions: enumerate every outcome of the undecided games
		$num_predictions = 0;
		if (sizeof($unknown_game_ids)) {
			$num_predictions = pow(2, sizeof($unknown_game_ids));
			self::enumeratePredictions($stats_by_user_id, $bets_by_game_id, $unknown_game_ids, $num_winners, $threshold);
			foreach ($stats_by_user_id as $u_user_id => $stats) {
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
		];
	}

	/**
	 * Walks all 2^n outcomes of the undecided games in Gray-code order, so each
	 * step flips one game and adjusts every user's integer sort key by a
	 * precomputed delta. Rank ties are exact ties on (points, right, bit_mult),
	 * which is what the float sort_score comparison expresses.
	 *
	 * Fills prediction_ranks, prediction_gte_threshold and either_threshold.
	 *
	 * @param array $stats_by_user_id  by reference
	 * @param array $bets_by_game_id
	 * @param array $unknown_game_ids
	 * @param int $num_winners
	 * @param int $threshold
	 */
	private static function enumeratePredictions(array &$stats_by_user_id, array $bets_by_game_id, array $unknown_game_ids, $num_winners, $threshold)
	{
		$u_keys = array_keys($stats_by_user_id);
		$num_users = sizeof($u_keys);
		if (!$num_users) {
			return;
		}
		$index_of = array_flip($u_keys);

		$keys = [];
		foreach ($u_keys as $i => $u_user_id) {
			$s = $stats_by_user_id[$u_user_id];
			$keys[$i] = ((int) $s['points'] << self::SHIFT_POINTS)
				+ ((int) $s['right'] << self::SHIFT_RIGHT)
				+ (int) $s['bit_mult'];
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

			// Competition rank: 1 + number of users with a strictly greater key
			$sorted = $keys;
			rsort($sorted);
			$cutoff = $winners > 0 ? $sorted[$winners - 1] : PHP_INT_MAX;
			$rank_of = [];
			foreach ($sorted as $idx => $v) {
				if ($v < $cutoff) {
					break;
				}
				if (!isset($rank_of[$v])) {
					$rank_of[$v] = $idx + 1;
				}
			}
			foreach ($keys as $i => $k) {
				$in_top = $k >= $cutoff;
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
					$ranks[$i][$rank_of[$k]]++;
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
