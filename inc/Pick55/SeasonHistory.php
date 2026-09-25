<?php

namespace Pick55;

use Pick55\Models\Bet;
use Pick55\Models\Game;
use Pick55\Models\Season;
use Pick55\Models\UsersSeasonsLink;
use Pick55\Models\Week;
use Pick55\Models\WeekFormat;
use Pick55\Models\WeekWinner;

/**
 * One player's record across every season they are in: the "Seasons" table
 * at the bottom of season/index.php.
 *
 * Four queries regardless of how many seasons the player has. The numbers
 * are defined to match what the other pages show:
 *   - players: who season/standings.php lists (paid players while a season
 *     is active, every linked player once it is not);
 *   - finish: the player's place on season/standings.php, i.e. regular-season
 *     weeks only, ranked by points then number correct, guaranteed picks
 *     counted as correct;
 *   - best week: the best weekly Rank shown in the My Season table
 *     (Week::getUserRank), regular-season weeks only;
 *   - winnings: the sum of the player's football_week_winners rows.
 */
class SeasonHistory
{
	/**
	 * @param int $user_id
	 * @return array season_id => [
	 *   'season' => Season,
	 *   'players' => int,
	 *   'finish' => int (0 = no decided picks yet / not a ranked player),
	 *   'best_week_rank' => int (0 = none),
	 *   'best_week_num' => int,
	 *   'winnings' => float,
	 * ], newest season first
	 */
	public static function forUser($user_id)
	{
		$user_id = (int) $user_id;
		$history = [];
		$season_ids = UsersSeasonsLink::where('er_user_id', '=', $user_id)
			->pluck('football_season_id')
			->all();
		if (!sizeof($season_ids)) {
			return $history;
		}
		$seasons = Season::whereIn('id', $season_ids)
			->orderBy('id', 'DESC')
			->get();
		foreach ($seasons as $season) {
			$history[$season->id] = [
				'season' => $season,
				'players' => 0,
				'finish' => 0,
				'best_week_rank' => 0,
				'best_week_num' => 0,
				'winnings' => 0,
			];
		}
		$season_ids = array_keys($history);

		// Ranked players per season, by the standings page's rule.
		$players_by_season_id = [];
		foreach ($season_ids as $season_id) {
			$players_by_season_id[$season_id] = [];
		}
		$q = DB::table(UsersSeasonsLink::getTableName())
			->select(['football_season_id', 'er_user_id', 'paid_at'])
			->whereIn('football_season_id', $season_ids);
		foreach ($q->cursor() as $row) {
			if ($history[$row->football_season_id]['season']->is_active && !$row->paid_at) {
				continue;
			}
			$players_by_season_id[$row->football_season_id][$row->er_user_id] = true;
		}
		foreach ($players_by_season_id as $season_id => $ids) {
			$history[$season_id]['players'] = sizeof($ids);
		}

		// Winnings.
		$q = DB::table(WeekWinner::getTableName() . ' AS winner')
			->leftJoin(Week::getTableName() . ' AS week', 'winner.week_id', '=', 'week.id')
			->select([
				'week.football_season_id',
				DB::raw('SUM(winner.amount) AS amount'),
			])
			->where('winner.er_user_id', '=', $user_id)
			->whereIn('week.football_season_id', $season_ids)
			->groupBy('week.football_season_id');
		foreach ($q->cursor() as $row) {
			$history[$row->football_season_id]['winnings'] = (float) $row->amount;
		}

		// Season finish: the standings page's leaderboard order, per season.
		$q = DB::table(Bet::getTableName() . ' AS bet')
			->leftJoin(Game::getTableName() . ' AS game', 'bet.football_game_id', '=', 'game.id')
			->leftJoin(Week::getTableName() . ' AS week', 'game.football_week_id', '=', 'week.id')
			->leftJoin(WeekFormat::getTableName() . ' AS fmt', 'week.football_week_format_id', '=', 'fmt.id')
			->select([
				'week.football_season_id',
				'bet.user_id',
				DB::raw('SUM(bet.multiplier) AS points'),
				DB::raw('COUNT(*) AS num_correct'),
			])
			->whereIn('week.football_season_id', $season_ids)
			->where('game.correct_option', '!=', '0')
			->whereRaw("(bet.option = '3' OR bet.option = game.correct_option)")
			// A week without a format counts as regular season.
			->whereRaw('(fmt.is_playoffs = 0 OR fmt.id IS NULL)')
			->groupBy('week.football_season_id')
			->groupBy('bet.user_id')
			->orderBy('week.football_season_id')
			->orderBy('points', 'DESC')
			->orderBy('num_correct', 'DESC');
		$rank = 0;
		$last_season_id = null;
		foreach ($q->cursor() as $row) {
			if ($row->football_season_id !== $last_season_id) {
				$rank = 0;
				$last_season_id = $row->football_season_id;
			}
			if (!isset($players_by_season_id[$row->football_season_id][$row->user_id])) {
				continue;
			}
			$rank++;
			if ($row->user_id == $user_id) {
				$history[$row->football_season_id]['finish'] = $rank;
			}
		}

		// Best weekly finish: Week::getUserRank's order, per regular-season week.
		$q = DB::table(Bet::getTableName() . ' AS bet')
			->leftJoin(Game::getTableName() . ' AS game', 'bet.football_game_id', '=', 'game.id')
			->leftJoin(Week::getTableName() . ' AS week', 'game.football_week_id', '=', 'week.id')
			->leftJoin(WeekFormat::getTableName() . ' AS fmt', 'week.football_week_format_id', '=', 'fmt.id')
			->select([
				'week.football_season_id',
				DB::raw('week.id AS week_id'),
				'week.week_num',
				'bet.user_id',
				DB::raw('SUM(bet.multiplier) AS points'),
				DB::raw('COUNT(*) AS num_correct'),
				DB::raw('SUM(POWER(2, bet.multiplier)) AS bit_mult'),
			])
			->whereIn('week.football_season_id', $season_ids)
			->where('game.correct_option', '!=', '0')
			->whereRaw('bet.option = game.correct_option')
			->whereRaw('(fmt.is_playoffs = 0 OR fmt.id IS NULL)')
			->groupBy('week.football_season_id')
			->groupBy('week.id')
			->groupBy('week.week_num')
			->groupBy('bet.user_id')
			->orderBy('week.id')
			->orderBy('points', 'DESC')
			->orderBy('num_correct', 'DESC')
			->orderBy('bit_mult', 'DESC');
		$rank = 0;
		$last_week_id = null;
		foreach ($q->cursor() as $row) {
			if ($row->week_id !== $last_week_id) {
				$rank = 0;
				$last_week_id = $row->week_id;
			}
			$rank++;
			if ($row->user_id != $user_id) {
				continue;
			}
			$h = &$history[$row->football_season_id];
			if (!$h['best_week_rank'] || $rank < $h['best_week_rank']) {
				$h['best_week_rank'] = $rank;
				$h['best_week_num'] = (int) $row->week_num;
			}
			unset($h);
		}

		return $history;
	}
}
