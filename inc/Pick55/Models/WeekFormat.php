<?php

namespace Pick55\Models;

use Illuminate\Database\Eloquent\Collection;

/**
 * The rules of a week: how many players it was designed for, whether they
 * are split into pools (or teams), whether it is a playoff week, and the
 * payout table (WeekFormatPayout rows).
 *
 * Payout semantics: a player receives the single largest payout they qualify
 * for. An overall payout outranks a pool payout, so the overall winner takes
 * the overall amount and their pool's 1st-place amount goes unpaid. See
 * docs/week-formats.md.
 */
class WeekFormat extends BaseModel
{
	protected $table = 'football_week_formats';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function payouts()
	{
		return $this->hasMany(WeekFormatPayout::class, 'football_week_format_id')
			->orderByRaw("FIELD(place_type, 'overall', 'pool', 'team')")
			->orderBy('pool_num', 'ASC')
			->orderBy('min_place', 'ASC')
			->orderBy('id', 'ASC');
	}

	public function weeks()
	{
		return $this->hasMany(Week::class, 'football_week_format_id');
	}

	/**
	 * @return bool
	 */
	public function hasPools()
	{
		return (int) $this->num_pools >= 2;
	}

	/**
	 * @return int|null
	 */
	public function getPlayersPerPool()
	{
		if (!$this->hasPools()) {
			return null;
		}
		return (int) ceil($this->num_players / $this->num_pools);
	}

	/**
	 * @return Collection of WeekFormatPayout
	 */
	public function getOverallPayouts()
	{
		return $this->payouts->filter(function ($p) {
			return $p->place_type == WeekFormatPayout::PLACE_OVERALL;
		})->values();
	}

	/**
	 * Pool (or team) payout rows that apply to the given pool number:
	 * rows with pool_num NULL apply to every pool.
	 *
	 * @param int|null $pool_num  null = rows that apply to every pool
	 * @return Collection of WeekFormatPayout
	 */
	public function getPoolPayouts($pool_num = null)
	{
		return $this->payouts->filter(function ($p) use ($pool_num) {
			if ($p->place_type == WeekFormatPayout::PLACE_OVERALL) {
				return false;
			}
			if ($p->pool_num === null) {
				return true;
			}
			return $pool_num !== null && (int) $p->pool_num == (int) $pool_num;
		})->values();
	}

	/**
	 * The payout rows that decide places for one view of the results:
	 * the pool rows when a pool is selected, otherwise the overall rows.
	 *
	 * @param int|null $pool_num
	 * @return Collection of WeekFormatPayout
	 */
	public function getPayoutsForView($pool_num = null)
	{
		if ($pool_num !== null && $this->hasPools()) {
			return $this->getPoolPayouts($pool_num);
		}
		return $this->getOverallPayouts();
	}

	/**
	 * How many places pay in the given view (replaces football_weeks.num_winners).
	 * Rows without a max_place (open-ended "everyone with N points" rows) do
	 * not count; a format with no bounded rows pays 1 place.
	 *
	 * @param int|null $pool_num
	 * @return int
	 */
	public function getNumWinners($pool_num = null)
	{
		$max = 0;
		foreach ($this->getPayoutsForView($pool_num) as $p) {
			if ($p->max_place !== null) {
				$max = max($max, (int) $p->max_place);
			}
		}
		return max(1, $max);
	}

	/**
	 * The lowest point threshold that pays in the given view, 0 if none
	 * (replaces football_weeks.min_score_threshold).
	 *
	 * @param int|null $pool_num
	 * @return int
	 */
	public function getMinScoreThreshold($pool_num = null)
	{
		$min = 0;
		foreach ($this->getPayoutsForView($pool_num) as $p) {
			if ($p->min_points !== null) {
				$min = $min ? min($min, (int) $p->min_points) : (int) $p->min_points;
			}
		}
		return $min;
	}

	/**
	 * Total money the format pays out, computed from the payout rows.
	 *
	 * Overall rows: payout x number of places, or the split pot (total_payout).
	 * Pool rows: the same, times the number of pools they apply to (one when
	 * pool_num targets a single pool). Team rows: times the players per team
	 * (teams are ranked against each other, the team in that place pays each
	 * member). Then the overall winners are assumed to sit in distinct pools
	 * and displace that pool's (or team's) 1st-place payout, since they
	 * receive only the larger, overall amount.
	 *
	 * @param array $rows  list of arrays/objects with place_type, pool_num, min_place, max_place, payout, total_payout
	 * @param int $num_pools
	 * @param bool $is_teams
	 * @param int $num_players
	 * @return float
	 */
	public static function computeTotalPayout(array $rows, $num_pools, $is_teams, $num_players)
	{
		$num_pools = (int) $num_pools;
		$players_per_pool = $num_pools >= 2 ? (int) ceil($num_players / $num_pools) : 0;
		$total = 0;
		$overall_places = 0;
		$pool_first = 0;
		foreach ($rows as $r) {
			$r = (object) $r;
			$places = self::numPlaces($r);
			if ($r->payout !== null && $r->payout !== '') {
				$amount = (float) $r->payout * $places;
			}
			else {
				$amount = (float) ($r->total_payout ?: 0);
			}
			if ($r->place_type == WeekFormatPayout::PLACE_OVERALL) {
				$total += $amount;
				if ($r->payout !== null && $r->payout !== '') {
					$overall_places += $places;
				}
				continue;
			}
			if ($r->place_type == WeekFormatPayout::PLACE_TEAM) {
				// Teams are ranked against each other; the team in that place
				// pays each of its members.
				$multiplier = max(1, $players_per_pool);
			}
			elseif ($r->pool_num === null || $r->pool_num === '') {
				$multiplier = max(1, $num_pools);
			}
			else {
				$multiplier = 1;
			}
			$total += $amount * $multiplier;
			if ((int) $r->min_place == 1 && ($r->pool_num === null || $r->pool_num === '') && $r->payout !== null && $r->payout !== '') {
				$pool_first = (float) $r->payout;
			}
		}
		if ($overall_places && $pool_first) {
			$total -= min($overall_places, max(1, $num_pools)) * $pool_first;
		}
		return round($total, 2);
	}

	/**
	 * @param object $r
	 * @return int
	 */
	private static function numPlaces($r)
	{
		$min = (int) $r->min_place ?: 1;
		if ($r->max_place === null || $r->max_place === '') {
			return 1;
		}
		return max(1, (int) $r->max_place - $min + 1);
	}

	/**
	 * Formats designed for the given number of players first, then the rest,
	 * for select boxes.
	 *
	 * @param int|null $num_players
	 * @return Collection of WeekFormat
	 */
	public static function getListForSelect($num_players = null)
	{
		$q = self::orderBy('is_playoffs', 'ASC')
			->orderBy('num_players', 'DESC')
			->orderBy('name', 'ASC');
		$all = $q->get();
		if (!$num_players) {
			return $all;
		}
		$matching = $all->filter(function ($f) use ($num_players) {
			return (int) $f->num_players == (int) $num_players;
		});
		$rest = $all->filter(function ($f) use ($num_players) {
			return (int) $f->num_players != (int) $num_players;
		});
		return $matching->merge($rest)->values();
	}
}
