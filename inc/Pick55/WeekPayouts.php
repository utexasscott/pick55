<?php

namespace Pick55;

use Pick55\Models\Week;
use Pick55\Models\WeekFormat;
use Pick55\Models\WeekFormatPayout;
use Pick55\Models\WeekWinner;

/**
 * Turns a week format's payout rows into money per player for one standing
 * of the field: what the week pays if it ended with the current scores, and
 * (inside WeekResults' enumeration) what it pays in each possible outcome.
 *
 * Rules (docs/week-formats.md, "Payout semantics"):
 *   - a player receives the single largest payout they qualify for, so the
 *     overall winner takes the overall amount and their pool's 1st-place
 *     amount goes unpaid;
 *   - places are competition ranks; players tied over places r..r+t-1 split
 *     the sum of those places' amounts equally (owner, 2026-09-25);
 *   - a "min_points" row also pays anyone at or above that many points;
 *   - a split pot is shared equally by everyone who qualifies for its row.
 *
 * Standings are given as WeekResults' integer sort keys
 * (points << SHIFT_POINTS | right << SHIFT_RIGHT | bit_mult), sorted
 * descending with the player index preserved, so ties are exact key ties.
 */
class WeekPayouts
{
	// Weeks whose last kickoff is older than this keep their recorded winners.
	const RESYNC_DAYS = 14;

	/**
	 * The payout tables of a week's format, one for the overall standing and
	 * one per pool number, in the compact shape assign() consumes:
	 *
	 *   [
	 *     'places' => [place => amount] for per-player place rows,
	 *     'thresholds' => [[min_points, amount], ...] for per-player rows
	 *                     that pay on points alone,
	 *     'splits' => [[min_place, max_place|null, min_points|null, pot], ...],
	 *     'any' => bool  whether the table pays anything at all,
	 *   ]
	 *
	 * @param WeekFormat|null $format
	 * @param int $num_players  players in the overall standing (caps open-ended rows)
	 * @param array $pool_nums  the pool numbers in play
	 * @return array ['overall' => table, 'pools' => [pool_num => table], 'any' => bool]
	 */
	public static function tables(WeekFormat $format = null, $num_players = 0, array $pool_nums = [])
	{
		$overall_rows = [];
		$every_pool_rows = [];
		$one_pool_rows = [];
		if ($format) {
			foreach ($format->payouts as $row) {
				if ($row->place_type == WeekFormatPayout::PLACE_OVERALL) {
					$overall_rows[] = $row;
				}
				elseif ($row->pool_num === null) {
					$every_pool_rows[] = $row;
				}
				else {
					$one_pool_rows[(int) $row->pool_num][] = $row;
				}
			}
		}
		$overall = self::table($overall_rows, $num_players);
		$pools = [];
		$any = $overall['any'];
		foreach ($pool_nums as $pool_num) {
			$rows = $every_pool_rows;
			if (isset($one_pool_rows[(int) $pool_num])) {
				$rows = array_merge($rows, $one_pool_rows[(int) $pool_num]);
			}
			$pools[(int) $pool_num] = self::table($rows, $num_players);
			$any = $any || $pools[(int) $pool_num]['any'];
		}
		return [
			'overall' => $overall,
			'pools' => $pools,
			'any' => $any,
		];
	}

	/**
	 * @param array $rows  list of WeekFormatPayout
	 * @param int $num_players
	 * @return array  see tables()
	 */
	public static function table(array $rows, $num_players)
	{
		$table = [
			'places' => [],
			'thresholds' => [],
			'splits' => [],
			'any' => false,
		];
		$num_players = max(1, (int) $num_players);
		foreach ($rows as $row) {
			$min_place = (int) $row->min_place;
			$max_place = $row->max_place === null ? null : (int) $row->max_place;
			$min_points = $row->min_points === null ? null : (int) $row->min_points;
			if ($row->payout === null) {
				$pot = (float) $row->total_payout;
				if ($pot > 0) {
					$table['splits'][] = [$min_place, $max_place, $min_points, $pot];
					$table['any'] = true;
				}
				continue;
			}
			$amount = (float) $row->payout;
			if ($amount <= 0) {
				continue;
			}
			$table['any'] = true;
			if ($min_place > 0) {
				$last = $max_place === null ? $num_players : min($max_place, $num_players);
				for ($place = $min_place; $place <= $last; $place++) {
					$table['places'][$place] = max($amount, isset($table['places'][$place]) ? $table['places'][$place] : 0);
				}
			}
			if ($min_points !== null) {
				$table['thresholds'][] = [$min_points, $amount];
			}
		}
		return $table;
	}

	/**
	 * Money per player for one standing under one table.
	 *
	 * @param array $order  player index => sort key, sorted descending (arsort)
	 * @param array $table  see table()
	 * @return array  player index => amount (every index present)
	 */
	public static function assign(array $order, array $table)
	{
		$pay = [];
		if (!$table['any']) {
			foreach ($order as $i => $k) {
				$pay[$i] = 0;
			}
			return $pay;
		}
		$places = $table['places'];
		$thresholds = $table['thresholds'];
		$splits = $table['splits'];
		$idx = array_keys($order);
		$vals = array_values($order);
		$n = sizeof($vals);
		$split_members = [];
		$place = 1;
		for ($a = 0; $a < $n;) {
			$b = $a;
			while ($b + 1 < $n && $vals[$b + 1] == $vals[$a]) {
				$b++;
			}
			$t = $b - $a + 1;
			$sum = 0;
			for ($p = $place; $p < $place + $t; $p++) {
				if (isset($places[$p])) {
					$sum += $places[$p];
				}
			}
			$amount = $sum / $t;
			$points = $vals[$a] >> WeekResults::SHIFT_POINTS;
			foreach ($thresholds as $row) {
				if ($points >= $row[0] && $row[1] > $amount) {
					$amount = $row[1];
				}
			}
			for ($c = $a; $c <= $b; $c++) {
				$pay[$idx[$c]] = $amount;
			}
			foreach ($splits as $r => $row) {
				$by_place = $row[0] > 0 && $place >= $row[0] && ($row[1] === null || $place <= $row[1]);
				$by_points = $row[2] !== null && $points >= $row[2];
				if ($by_place || $by_points) {
					for ($c = $a; $c <= $b; $c++) {
						$split_members[$r][] = $idx[$c];
					}
				}
			}
			$place += $t;
			$a = $b + 1;
		}
		foreach ($split_members as $r => $members) {
			$each = $splits[$r][3] / sizeof($members);
			foreach ($members as $i) {
				if ($each > $pay[$i]) {
					$pay[$i] = $each;
				}
			}
		}
		return $pay;
	}

	/**
	 * Money per player for one standing of the whole field: the larger of the
	 * overall payout and the player's pool payout.
	 *
	 * @param array $order  player index => sort key, sorted descending
	 * @param array $tables  from tables()
	 * @param array $pool_of  player index => pool_num (players not in a pool omitted)
	 * @return array  player index => amount
	 */
	public static function assignAll(array $order, array $tables, array $pool_of)
	{
		$pay = self::assign($order, $tables['overall']);
		if (!sizeof($tables['pools'])) {
			return $pay;
		}
		$subs = [];
		foreach ($order as $i => $k) {
			if (isset($pool_of[$i])) {
				$subs[$pool_of[$i]][$i] = $k;
			}
		}
		foreach ($subs as $pool_num => $sub) {
			if (!isset($tables['pools'][$pool_num])) {
				continue;
			}
			foreach (self::assign($sub, $tables['pools'][$pool_num]) as $i => $amount) {
				if ($amount > $pay[$i]) {
					$pay[$i] = $amount;
				}
			}
		}
		return $pay;
	}

	/**
	 * Records a finished week's payouts in football_week_winners so the
	 * season page, standings and all-time stats see them, replacing the
	 * admin's hand entry (owner, 2026-09-25: winnings are determined by the
	 * week format, not typed in).
	 *
	 * Writes only when the rows differ from the amounts given, and only while
	 * the week is recent (its last game within RESYNC_DAYS), so a corrected
	 * score still flows through but the paid history of older seasons, some
	 * of which was paid differently from its format, is never rewritten. A
	 * week with no rows yet is always written.
	 *
	 * @param Week $week
	 * @param array $amounts  user_id => amount (zero or missing = no row)
	 * @param string|null $last_game_at  'Y-m-d H:i:s' of the last kickoff
	 * @return bool  whether anything was written
	 */
	public static function syncWinners(Week $week, array $amounts, $last_game_at = null)
	{
		$wanted = [];
		foreach ($amounts as $user_id => $amount) {
			$amount = round((float) $amount, 2);
			if ($amount > 0) {
				$wanted[(int) $user_id] = $amount;
			}
		}
		$existing = [];
		foreach (WeekWinner::where('week_id', '=', $week->id)->get() as $row) {
			$existing[(int) $row->er_user_id][] = $row;
		}
		$recent = $last_game_at && strtotime($last_game_at) > time() - self::RESYNC_DAYS * 86400;
		if (!$recent) {
			if (sizeof($existing)) {
				return false;
			}
			// A week with no rows yet is written only while its season is
			// active, so viewing an old week never adds history to it.
			if (!$week->season || !$week->season->is_active) {
				return false;
			}
		}
		$changed = false;
		foreach ($wanted as $user_id => $amount) {
			if (isset($existing[$user_id])) {
				$rows = $existing[$user_id];
				$row = array_shift($rows);
				if (abs((float) $row->amount - $amount) >= 0.005) {
					$row->amount = $amount;
					$row->save();
					$changed = true;
				}
				foreach ($rows as $dup) {
					$dup->delete();
					$changed = true;
				}
				unset($existing[$user_id]);
				continue;
			}
			WeekWinner::create([
				'week_id' => $week->id,
				'er_user_id' => $user_id,
				'amount' => $amount,
			]);
			$changed = true;
		}
		foreach ($existing as $user_id => $rows) {
			foreach ($rows as $row) {
				$row->delete();
				$changed = true;
			}
		}
		return $changed;
	}
}
