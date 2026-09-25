<?php

namespace Pick55;

use Pick55\Models\Season;

/**
 * Everything the stats/ pages show: the Wall of Fame and Wall of Shame, the
 * all-time player leaderboards, the best (and worst) seasons, and the totals
 * and record book on the overview.
 *
 * One pass over every player-week in the database. The rules, which the
 * pages repeat in their footers:
 *   - a week counts once every one of its games is decided; the week in
 *     progress is invisible here until then;
 *   - a player-week counts when the player made at least one pick;
 *   - guaranteed picks (option '3', handed out in the Semifinals) are not
 *     picks: they are left out of every pick and points percentage, and a
 *     week in which a player held any guaranteed pick is excluded from the
 *     walls, which are about real picks only;
 *   - the entry fee of a finished season is football_seasons.fee; the
 *     active season charges (fee - FINALS_ALLOCATION) / num_weeks per
 *     completed regular week plus FINALS_ALLOCATION once its last week is
 *     complete (owner, 2026-09-25: "$10 per week and $20 to the finals");
 *   - a "full" season is one with num_weeks = 10 whose weeks are worth 55
 *     points; only full seasons whose regular season is over appear on the
 *     best-seasons page (2010 scored 13 a week; the 2024 CFP had 4 weeks).
 *
 * The result is cached (see Cache) under a fingerprint of every table that
 * feeds it, the same way WeekResults is, so a score entry, a pick, a paid
 * winner or a roster change invalidates it on the next view.
 */
class AllTimeStats
{
	const PERFECT = 55;
	const HONOR_MIN = 50;
	const DISHONOR_MAX = 9;
	const FINALS_ALLOCATION = 20;

	/**
	 * @return array see compute()
	 */
	public static function get()
	{
		$prefix = 'alltime-';
		$key = $prefix . self::fingerprint();
		return Cache::remember($key, $prefix . 'lock', function () use ($prefix, $key) {
			$stats = self::compute();
			Cache::forgetPrefix($prefix, $key);
			return $stats;
		});
	}

	/**
	 * A short hash over every stored value the stats depend on: seven cheap
	 * aggregate queries (count, BIT_XOR and SUM of a CRC32 per row).
	 *
	 * @return string
	 */
	public static function fingerprint()
	{
		// Bump the version tag whenever compute()'s output shape changes, so
		// cached entries from the old shape are never served.
		$parts = ['v2'];
		$tables = [
			'football_bets' => "CONCAT_WS('|', id, user_id, football_game_id, `option`, multiplier)",
			'football_games' => "CONCAT_WS('|', id, football_week_id, IFNULL(type, ''), IFNULL(bet_type, ''), correct_option)",
			'football_weeks' => "CONCAT_WS('|', id, football_season_id, week_num, IFNULL(football_week_format_id, ''))",
			'football_week_formats' => "CONCAT_WS('|', id, name, is_playoffs, IFNULL(advance, ''))",
			'football_seasons' => "CONCAT_WS('|', id, name, is_active, num_weeks, playoff_weeks, fee)",
			'football_week_winners' => "CONCAT_WS('|', id, week_id, er_user_id, amount)",
			'football_users_seasons' => "CONCAT_WS('|', id, football_season_id, er_user_id, IFNULL(paid_at, ''))",
		];
		foreach ($tables as $table => $expr) {
			$row = DB::table($table)
				->selectRaw("COUNT(*) AS n, IFNULL(BIT_XOR(CRC32($expr)), 0) AS x, IFNULL(SUM(CRC32($expr)), 0) AS s")
				->first();
			$parts[] = [(int) $row->n, (string) $row->x, (string) $row->s];
		}
		return substr(md5(json_encode($parts)), 0, 16);
	}

	/**
	 * Uncached computation.
	 *
	 * @return array [
	 *   'generated_at' => datetime,
	 *   'seasons' => season_id => [id, name, is_active, num_weeks, playoff_weeks, fee, full, regular_total,
	 *                              regular_complete, regular_done, players, fee_to_date, paid_out, champion_user_id],
	 *   'weeks' => week_id => [id, season_id, week_num, name, is_playoffs, complete, field, max_total, min_total, top_payout],
	 *   'totals' => [...],
	 *   'distribution' => points => number of player-weeks (real picks only),
	 *   'fame' => list of player-week rows scoring PERFECT or more,
	 *   'honor' => list of player-week rows scoring HONOR_MIN .. PERFECT-1,
	 *   'shame' => list of player-week rows scoring 0,
	 *   'dishonor' => list of player-week rows scoring 1 .. DISHONOR_MAX,
	 *   'players' => user_id => all-time aggregate (see $player_base),
	 *   'player_seasons' => list of per-season aggregates (see $ps_base), regular season only,
	 * ]
	 */
	public static function compute()
	{
		// ---- Seasons
		$seasons = [];
		foreach (Season::orderBy('id')->get() as $s) {
			$seasons[$s->id] = [
				'id' => (int) $s->id,
				'name' => (string) $s->name,
				'is_active' => (bool) $s->is_active,
				'num_weeks' => (int) $s->num_weeks,
				'playoff_weeks' => (int) $s->playoff_weeks,
				'fee' => (float) $s->fee,
				'full' => false,
				'regular_total' => 0,
				'regular_complete' => 0,
				'regular_done' => false,
				'last_week_complete' => false,
				'players' => 0,
				'fee_to_date' => 0,
				'paid_out' => 0,
				'champion_user_id' => null,
				'possible_counts' => [],
			];
		}

		// ---- Weeks (with their format and how many games are decided)
		$weeks = [];
		$q = DB::table('football_weeks AS w')
			->leftJoin('football_week_formats AS f', 'w.football_week_format_id', '=', 'f.id')
			->leftJoin('football_games AS g', 'g.football_week_id', '=', 'w.id')
			->select([
				'w.id',
				'w.football_season_id',
				'w.week_num',
				'f.name',
				DB::raw('IFNULL(f.is_playoffs, 0) AS is_playoffs'),
				'f.advance',
				DB::raw('COUNT(g.id) AS games'),
				DB::raw("IFNULL(SUM(g.correct_option <> '0'), 0) AS decided"),
			])
			->groupBy('w.id', 'w.football_season_id', 'w.week_num', 'f.name', 'f.is_playoffs', 'f.advance')
			->orderBy('w.football_season_id')
			->orderBy('w.week_num');
		foreach ($q->cursor() as $row) {
			if (!isset($seasons[$row->football_season_id])) {
				continue;
			}
			$complete = (int) $row->games > 0 && (int) $row->decided == (int) $row->games;
			$weeks[$row->id] = [
				'id' => (int) $row->id,
				'season_id' => (int) $row->football_season_id,
				'week_num' => (int) $row->week_num,
				'name' => strlen((string) $row->name) ? (string) $row->name : 'Week ' . $row->week_num,
				'is_playoffs' => (bool) $row->is_playoffs,
				'complete' => $complete,
				'field' => 0,
				'max_total' => null,
				'min_total' => null,
				'top_payout' => 0,
			];
			$season = &$seasons[$row->football_season_id];
			if (!$row->is_playoffs) {
				$season['regular_total']++;
				if ($complete) {
					$season['regular_complete']++;
				}
			}
			// Weeks arrive in week_num order, so the last one seen is the season's last.
			$season['last_week_complete'] = $complete;
			unset($season);
		}
		foreach ($seasons as &$season) {
			$season['regular_done'] = $season['regular_total'] > 0
				&& $season['regular_complete'] >= min($season['regular_total'], $season['num_weeks']);
		}
		unset($season);

		// ---- Winnings per player-week, and each week's top payout
		$winnings = [];
		$q = DB::table('football_week_winners')
			->select(['week_id', 'er_user_id', DB::raw('SUM(amount) AS amount')])
			->groupBy('week_id', 'er_user_id');
		$paid_out = 0;
		foreach ($q->cursor() as $row) {
			$amount = (float) $row->amount;
			$winnings[$row->week_id][$row->er_user_id] = $amount;
			$paid_out += $amount;
			if (isset($weeks[$row->week_id])) {
				$seasons[$weeks[$row->week_id]['season_id']]['paid_out'] += $amount;
				if ($amount > $weeks[$row->week_id]['top_payout']) {
					$weeks[$row->week_id]['top_payout'] = $amount;
				}
			}
		}

		// ---- Season rosters (the standings page's rule: paid players while active, everyone after)
		$roster = [];
		$links = [];
		$q = DB::table('football_users_seasons')
			->select(['football_season_id', 'er_user_id', 'paid_at']);
		foreach ($q->cursor() as $row) {
			if (!isset($seasons[$row->football_season_id])) {
				continue;
			}
			$links[] = [(int) $row->er_user_id, (int) $row->football_season_id];
			if ($seasons[$row->football_season_id]['is_active'] && !$row->paid_at) {
				continue;
			}
			$roster[$row->football_season_id][$row->er_user_id] = true;
		}
		foreach ($roster as $season_id => $ids) {
			$seasons[$season_id]['players'] = sizeof($ids);
		}

		// ---- Every player-week, one query
		$hit = "(g.correct_option <> '0' AND b.option = g.correct_option)";
		$dec = "(g.correct_option <> '0' AND b.option IN ('1','2'))";
		$miss = "($dec AND b.option <> g.correct_option)";
		$splits = [
			'all' => '1',
			'ou' => "g.bet_type = 'over-under'",
			'spread' => "g.bet_type = 'spread'",
			'nfl' => "g.type = 'NFL'",
			'ncaa' => "g.type = 'NCAA'",
		];
		$cols = [
			'g.football_week_id AS week_id',
			'b.user_id',
			"SUM(b.option IN ('1','2')) AS picks",
			"SUM(b.option = '3') AS autos",
			"SUM(IF(b.option = '3', b.multiplier, 0)) AS auto_points",
			"SUM(IF($hit OR b.option = '3', POWER(2, b.multiplier), 0)) AS bit_mult",
		];
		foreach ($splits as $suffix => $cond) {
			$cols[] = "SUM($cond AND $hit) AS right_$suffix";
			$cols[] = "SUM($cond AND $miss) AS wrong_$suffix";
			$cols[] = "SUM(IF($cond AND $hit, b.multiplier, 0)) AS points_$suffix";
			$cols[] = "SUM(IF($cond AND $dec, b.multiplier, 0)) AS possible_$suffix";
		}
		$q = DB::table('football_bets AS b')
			->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
			->selectRaw(implode(', ', $cols))
			->groupBy('g.football_week_id', 'b.user_id');

		$rows = [];
		$by_week = [];
		foreach ($q->cursor() as $r) {
			if (!isset($weeks[$r->week_id]) || !$weeks[$r->week_id]['complete']) {
				continue;
			}
			if ((int) $r->picks + (int) $r->autos == 0) {
				continue;
			}
			$week = $weeks[$r->week_id];
			$row = [
				'user_id' => (int) $r->user_id,
				'week_id' => (int) $r->week_id,
				'season_id' => $week['season_id'],
				'week_num' => $week['week_num'],
				'is_playoffs' => $week['is_playoffs'],
				'right' => (int) $r->right_all,
				'wrong' => (int) $r->wrong_all,
				'points' => (int) $r->points_all,
				'possible' => (int) $r->possible_all,
				'autos' => (int) $r->autos,
				'auto_points' => (int) $r->auto_points,
				'total' => (int) $r->points_all + (int) $r->auto_points,
				'bit_mult' => (float) $r->bit_mult,
				'rank' => 0,
				'field' => 0,
				'winnings' => isset($winnings[$r->week_id][$r->user_id]) ? $winnings[$r->week_id][$r->user_id] : 0,
			];
			foreach (['ou', 'spread', 'nfl', 'ncaa'] as $suffix) {
				$row[$suffix] = [
					'right' => (int) $r->{'right_' . $suffix},
					'wrong' => (int) $r->{'wrong_' . $suffix},
					'points' => (int) $r->{'points_' . $suffix},
					'possible' => (int) $r->{'possible_' . $suffix},
				];
			}
			$rows[] = $row;
			$by_week[$r->week_id][] = sizeof($rows) - 1;
			if (!$week['is_playoffs']) {
				$seasons[$week['season_id']]['possible_counts'][$row['possible']] =
					($seasons[$week['season_id']]['possible_counts'][$row['possible']] ?? 0) + 1;
			}
		}

		// ---- Rank within each week (the results page's order: points, correct, bit_mult)
		foreach ($by_week as $week_id => $idxs) {
			usort($idxs, function ($a, $b) use ($rows) {
				$ra = $rows[$a];
				$rb = $rows[$b];
				if ($ra['total'] != $rb['total']) return $rb['total'] - $ra['total'];
				if ($ra['right'] + $ra['autos'] != $rb['right'] + $rb['autos']) return ($rb['right'] + $rb['autos']) - ($ra['right'] + $ra['autos']);
				if ($ra['bit_mult'] != $rb['bit_mult']) return $rb['bit_mult'] < $ra['bit_mult'] ? -1 : 1;
				return 0;
			});
			$rank = 0;
			$pos = 0;
			$prev = null;
			$field = sizeof($idxs);
			foreach ($idxs as $i) {
				$pos++;
				$key = $rows[$i]['total'] . '|' . ($rows[$i]['right'] + $rows[$i]['autos']) . '|' . $rows[$i]['bit_mult'];
				if ($key !== $prev) {
					$rank = $pos;
					$prev = $key;
				}
				$rows[$i]['rank'] = $rank;
				$rows[$i]['field'] = $field;
			}
			$weeks[$week_id]['field'] = $field;
			$weeks[$week_id]['max_total'] = $rows[$idxs[0]]['total'];
			$weeks[$week_id]['min_total'] = $rows[$idxs[$field - 1]]['total'];
		}

		// ---- Full seasons: 10 weeks worth 55 points each
		foreach ($seasons as &$season) {
			$mode = null;
			$mode_n = 0;
			foreach ($season['possible_counts'] as $possible => $n) {
				if ($n > $mode_n) {
					$mode = $possible;
					$mode_n = $n;
				}
			}
			$season['full'] = $season['num_weeks'] == 10 && $mode == self::PERFECT;
			$season['paid_out'] = round($season['paid_out'], 2);
			unset($season['possible_counts']);

			// Entry fee to date for the season in progress
			if ($season['is_active']) {
				$alloc = $season['playoff_weeks'] > 0 ? self::FINALS_ALLOCATION : 0;
				$weekly = $season['num_weeks'] > 0 ? ($season['fee'] - $alloc) / $season['num_weeks'] : 0;
				$season['fee_to_date'] = round(min($season['regular_complete'], $season['num_weeks']) * $weekly
					+ ($season['last_week_complete'] ? $alloc : 0), 2);
			}
			else {
				$season['fee_to_date'] = $season['fee'];
			}
		}
		unset($season);

		// ---- Champions: the biggest payout of a paid playoff week
		foreach ($weeks as $week_id => $week) {
			if (!$week['is_playoffs'] || $week['top_payout'] <= 0) {
				continue;
			}
			foreach ($winnings[$week_id] as $user_id => $amount) {
				if ($amount == $week['top_payout']) {
					$seasons[$week['season_id']]['champion_user_id'] = (int) $user_id;
					break;
				}
			}
		}

		// ---- Aggregates
		$split_base = ['right' => 0, 'wrong' => 0, 'points' => 0, 'possible' => 0];
		$player_base = [
			'user_id' => null,
			'seasons' => 0,
			'weeks' => 0,
			'right' => 0,
			'wrong' => 0,
			'points' => 0,
			'possible' => 0,
			'autos' => 0,
			'auto_points' => 0,
			'ou' => $split_base,
			'spread' => $split_base,
			'nfl' => $split_base,
			'ncaa' => $split_base,
			'weeks_won' => 0,
			'weeks_last' => 0,
			'titles' => 0,
			'podiums' => 0,
			'perfect' => 0,
			'honor' => 0,
			'zero' => 0,
			'dishonor' => 0,
			'best_week' => null,
			'worst_week' => null,
			'winnings' => 0,
			'fees' => 0,
			'net' => 0,
			'pick_pct' => 0,
			'points_pct' => 0,
			'points_per_week' => 0,
		];
		$ps_base = [
			'user_id' => null,
			'season_id' => null,
			'weeks' => 0,
			'right' => 0,
			'wrong' => 0,
			'points' => 0,
			'possible' => 0,
			'weeks_won' => 0,
			'best_week' => null,
			'worst_week' => null,
			'finish' => 0,
			'players' => 0,
			'winnings' => 0,
			'champion' => false,
			'pick_pct' => 0,
			'points_pct' => 0,
		];

		$players = [];
		$player_seasons = [];
		$fame = [];
		$honor = [];
		$shame = [];
		$dishonor = [];
		$distribution = [];
		$totals = [
			'seasons' => sizeof($seasons),
			'weeks' => 0,
			'players' => 0,
			'player_weeks' => 0,
			'right' => 0,
			'wrong' => 0,
			'points' => 0,
			'possible' => 0,
			'paid_out' => round($paid_out, 2),
			'fees' => 0,
			'perfect' => 0,
			'honor' => 0,
			'zero' => 0,
			'dishonor' => 0,
			'avg_score' => 0,
			'mode_score' => 0,
			'top_payout' => 0,
			'top_payout_week_id' => null,
			'top_payout_user_id' => null,
		];
		foreach ($weeks as $week) {
			if ($week['complete']) {
				$totals['weeks']++;
			}
		}
		foreach ($winnings as $week_id => $by_user) {
			foreach ($by_user as $user_id => $amount) {
				if ($amount > $totals['top_payout']) {
					$totals['top_payout'] = $amount;
					$totals['top_payout_week_id'] = (int) $week_id;
					$totals['top_payout_user_id'] = (int) $user_id;
				}
			}
		}

		foreach ($rows as $row) {
			$uid = $row['user_id'];
			$sid = $row['season_id'];
			$week = $weeks[$row['week_id']];
			if (!isset($players[$uid])) {
				$players[$uid] = $player_base;
				$players[$uid]['user_id'] = $uid;
			}
			$p = &$players[$uid];
			$p['weeks']++;
			foreach (['right', 'wrong', 'points', 'possible', 'autos', 'auto_points'] as $k) {
				$p[$k] += $row[$k];
			}
			foreach (['ou', 'spread', 'nfl', 'ncaa'] as $suffix) {
				foreach ($split_base as $k => $zero) {
					$p[$suffix][$k] += $row[$suffix][$k];
				}
			}
			$p['winnings'] += $row['winnings'];
			// Best and worst week: real picks only, so a Semifinals week with
			// guaranteed points is neither a record high nor a record low.
			if ($row['autos'] == 0) {
				if ($p['best_week'] === null || $row['points'] > $p['best_week']['points']) {
					$p['best_week'] = ['points' => $row['points'], 'week_id' => $row['week_id']];
				}
				if ($p['worst_week'] === null || $row['points'] < $p['worst_week']['points']) {
					$p['worst_week'] = ['points' => $row['points'], 'week_id' => $row['week_id']];
				}
			}
			if (!$row['is_playoffs']) {
				if ($row['total'] == $week['max_total']) $p['weeks_won']++;
				if ($row['total'] == $week['min_total']) $p['weeks_last']++;
			}
			if ($week['is_playoffs'] && $week['top_payout'] > 0 && $row['winnings'] == $week['top_payout']) {
				$p['titles']++;
			}

			$totals['player_weeks']++;
			foreach (['right', 'wrong', 'points', 'possible'] as $k) {
				$totals[$k] += $row[$k];
			}

			// Walls: real picks only
			if ($row['autos'] == 0) {
				$distribution[$row['points']] = ($distribution[$row['points']] ?? 0) + 1;
				if ($row['points'] >= self::PERFECT) {
					$fame[] = $row;
					$p['perfect']++;
					$totals['perfect']++;
				}
				elseif ($row['points'] >= self::HONOR_MIN) {
					$honor[] = $row;
					$p['honor']++;
					$totals['honor']++;
				}
				elseif ($row['points'] == 0) {
					$shame[] = $row;
					$p['zero']++;
					$totals['zero']++;
				}
				elseif ($row['points'] <= self::DISHONOR_MAX) {
					$dishonor[] = $row;
					$p['dishonor']++;
					$totals['dishonor']++;
				}
			}
			unset($p);

			// Player-season (regular season only)
			if (!$row['is_playoffs']) {
				$key = $uid . '-' . $sid;
				if (!isset($player_seasons[$key])) {
					$player_seasons[$key] = $ps_base;
					$player_seasons[$key]['user_id'] = $uid;
					$player_seasons[$key]['season_id'] = $sid;
					$player_seasons[$key]['players'] = $seasons[$sid]['players'];
					$player_seasons[$key]['champion'] = $seasons[$sid]['champion_user_id'] === $uid;
				}
				$ps = &$player_seasons[$key];
				$ps['weeks']++;
				foreach (['right', 'wrong', 'points', 'possible'] as $k) {
					$ps[$k] += $row[$k];
				}
				if ($row['total'] == $week['max_total']) $ps['weeks_won']++;
				if ($ps['best_week'] === null || $row['points'] > $ps['best_week']['points']) {
					$ps['best_week'] = ['points' => $row['points'], 'week_id' => $row['week_id']];
				}
				if ($ps['worst_week'] === null || $row['points'] < $ps['worst_week']['points']) {
					$ps['worst_week'] = ['points' => $row['points'], 'week_id' => $row['week_id']];
				}
				unset($ps);
			}
		}

		// Season winnings (all weeks, playoffs included) onto the player-season rows
		foreach ($winnings as $week_id => $by_user) {
			if (!isset($weeks[$week_id])) {
				continue;
			}
			$sid = $weeks[$week_id]['season_id'];
			foreach ($by_user as $uid => $amount) {
				$key = $uid . '-' . $sid;
				if (isset($player_seasons[$key])) {
					$player_seasons[$key]['winnings'] += $amount;
				}
			}
		}

		// Season finish: the standings page's order among the season's roster
		$by_season = [];
		foreach ($player_seasons as $key => $ps) {
			$by_season[$ps['season_id']][] = $key;
		}
		foreach ($by_season as $sid => $keys) {
			usort($keys, function ($a, $b) use ($player_seasons) {
				$pa = $player_seasons[$a];
				$pb = $player_seasons[$b];
				if ($pa['points'] != $pb['points']) return $pb['points'] - $pa['points'];
				return $pb['right'] - $pa['right'];
			});
			$rank = 0;
			foreach ($keys as $key) {
				$uid = $player_seasons[$key]['user_id'];
				if (!isset($roster[$sid][$uid])) {
					continue;
				}
				$rank++;
				$player_seasons[$key]['finish'] = $rank;
				if ($rank <= 3 && isset($players[$uid]) && $seasons[$sid]['full']) {
					$players[$uid]['podiums']++;
				}
			}
		}
		foreach ($player_seasons as &$ps) {
			$ps['pick_pct'] = self::pct($ps['right'], $ps['right'] + $ps['wrong']);
			$ps['points_pct'] = self::pct($ps['points'], $ps['possible']);
		}
		unset($ps);

		// Seasons and fees per player, from the roster links
		foreach ($links as $link) {
			list($uid, $sid) = $link;
			if (!isset($players[$uid])) {
				$players[$uid] = $player_base;
				$players[$uid]['user_id'] = $uid;
			}
			$players[$uid]['seasons']++;
			$players[$uid]['fees'] += $seasons[$sid]['fee_to_date'];
			$totals['fees'] += $seasons[$sid]['fee_to_date'];
		}
		foreach ($players as &$p) {
			$p['net'] = round($p['winnings'] - $p['fees'], 2);
			$p['winnings'] = round($p['winnings'], 2);
			$p['fees'] = round($p['fees'], 2);
			$p['pick_pct'] = self::pct($p['right'], $p['right'] + $p['wrong']);
			$p['points_pct'] = self::pct($p['points'], $p['possible']);
			$p['points_per_week'] = $p['weeks'] ? round($p['points'] / $p['weeks'], 1) : 0;
			foreach (['ou', 'spread', 'nfl', 'ncaa'] as $suffix) {
				$p[$suffix]['pick_pct'] = self::pct($p[$suffix]['right'], $p[$suffix]['right'] + $p[$suffix]['wrong']);
				$p[$suffix]['points_pct'] = self::pct($p[$suffix]['points'], $p[$suffix]['possible']);
			}
			if ($p['weeks']) {
				$totals['players']++;
			}
		}
		unset($p);
		$totals['fees'] = round($totals['fees'], 2);

		// Distribution extras
		ksort($distribution);
		$n = 0;
		$sum = 0;
		$mode = 0;
		$mode_n = 0;
		foreach ($distribution as $points => $count) {
			$n += $count;
			$sum += $points * $count;
			if ($count > $mode_n) {
				$mode = $points;
				$mode_n = $count;
			}
		}
		$totals['avg_score'] = $n ? round($sum / $n, 1) : 0;
		$totals['mode_score'] = $mode;
		$totals['pick_pct'] = self::pct($totals['right'], $totals['right'] + $totals['wrong']);
		$totals['points_pct'] = self::pct($totals['points'], $totals['possible']);

		// Order the walls: newest first inside a score band, best (or worst) score first
		$chrono = function ($a, $b) {
			if ($a['season_id'] != $b['season_id']) return $b['season_id'] - $a['season_id'];
			if ($a['week_num'] != $b['week_num']) return $b['week_num'] - $a['week_num'];
			return $b['rank'] - $a['rank'];
		};
		usort($fame, $chrono);
		usort($shame, $chrono);
		usort($honor, function ($a, $b) use ($chrono) {
			if ($a['points'] != $b['points']) return $b['points'] - $a['points'];
			return $chrono($a, $b);
		});
		usort($dishonor, function ($a, $b) use ($chrono) {
			if ($a['points'] != $b['points']) return $a['points'] - $b['points'];
			return $chrono($a, $b);
		});

		return [
			'generated_at' => now(),
			'seasons' => $seasons,
			'weeks' => $weeks,
			'totals' => $totals,
			'distribution' => $distribution,
			'fame' => $fame,
			'honor' => $honor,
			'shame' => $shame,
			'dishonor' => $dishonor,
			'players' => $players,
			'player_seasons' => array_values($player_seasons),
		];
	}

	/**
	 * @param int|float $num
	 * @param int|float $den
	 * @return float percentage with one decimal, 0 when the denominator is 0
	 */
	public static function pct($num, $den)
	{
		if (!$den) {
			return 0;
		}
		return round($num / $den * 100, 1);
	}
}
