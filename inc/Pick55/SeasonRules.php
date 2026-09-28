<?php

namespace Pick55;

use Pick55\Models\Season;

/**
 * The facts both rules pages (rules.php, r/rules.php) print for a season,
 * from the season row plus what its weeks, formats, games and guaranteed
 * points already record. See docs/season-rules.md.
 */
class SeasonRules
{
	/**
	 * @param Season $season
	 * @return array [
	 *   fee, weekly_pot, finals_pot (floats),
	 *   num_regular_weeks, num_playoff_weeks, num_weeks, games_per_week,
	 *   num_zero_games (ints),
	 *   starts_at (datetime or null), has_started (bool),
	 *   is_active, is_cfp, has_pools (bools),
	 *   advance (int or null: players advancing from the knock-out round),
	 *   pay_to_name, pay_to_venmo (string or null),
	 *   gg_slots (point value of each game slot, zeros first),
	 *   gg_rows ([place label, guaranteed slot count], last row "Nth and lower" => 0),
	 *   gg_places (int: the last place with guaranteed games, 0 = none),
	 *   gg_season (Season the grid was borrowed from, or null when it is the season's own),
	 * ]
	 */
	public static function get(Season $season)
	{
		$games = $season->getGamesPerWeek();
		$zeros = max(0, $games - 10);
		$starts_at = $season->getStartsAt();

		$gg_rows = [];
		$grid = $season->getGuaranteeGrid();
		if ($grid) {
			foreach ($grid['rows'] as $row) {
				$label = $row['min'] == $row['max']
					? ordinal($row['min'])
					: ordinal($row['min']) . "\u{2013}" . ordinal($row['max']);
				// multipliers_less_than N guarantees every 0-point game and 1 .. N-1
				$gg_rows[] = [$label, $zeros + max(0, min(10, $row['lt'] - 1))];
			}
			$gg_rows[] = [ordinal($grid['places'] + 1) . ' and lower', 0];
		}

		return [
			'fee' => (float) $season->fee,
			'weekly_pot' => $season->getWeeklyPot(),
			'finals_pot' => $season->getFinalsPot(),
			'num_regular_weeks' => (int) $season->num_weeks,
			'num_playoff_weeks' => (int) $season->playoff_weeks,
			'num_weeks' => $season->getNumWeeksTotal(),
			'games_per_week' => $games,
			'num_zero_games' => $zeros,
			'starts_at' => $starts_at,
			'has_started' => $starts_at && strtotime($starts_at) <= time(),
			'is_active' => (bool) $season->is_active,
			'is_cfp' => (bool) $season->is_cfp,
			'has_pools' => $season->hasPools(),
			'advance' => $season->getNumAdvancing(),
			'pay_to_name' => strlen((string) $season->pay_to_name) ? $season->pay_to_name : null,
			'pay_to_venmo' => strlen((string) $season->pay_to_venmo) ? $season->pay_to_venmo : null,
			'gg_slots' => array_merge(array_fill(0, $zeros, 0), range(1, 10)),
			'gg_rows' => $gg_rows,
			'gg_places' => $grid ? $grid['places'] : 0,
			'gg_season' => $grid && (int) $grid['season']->id !== (int) $season->id ? $grid['season'] : null,
		];
	}

	/**
	 * The season to show: ?id= if it exists, else the active season, else
	 * the latest.
	 *
	 * @return Season or null
	 */
	public static function resolveSeason()
	{
		$season = get('id') ? Season::find((int) get('id')) : null;
		if (!$season) {
			$season = Season::getActive();
		}
		if (!$season) {
			$season = Season::getLatest();
		}
		return $season;
	}

	/**
	 * @return array of Season, newest first, for the season select
	 */
	public static function seasonList()
	{
		return Season::orderBy('id', 'DESC')->get()->all();
	}
}
