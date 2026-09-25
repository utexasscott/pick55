<?php

namespace Pick55\Models;

/**
 * One row of a format's payout table.
 *
 *   place_type   'overall' (whole field), 'pool' (each pool, or one pool when
 *                pool_num is set), 'team' (each member of the team, legacy)
 *   pool_num     NULL = every pool; N = only pool N (finals: 1 = Finalists,
 *                2 = Consolation)
 *   min_place    first place that qualifies (1-based)
 *   max_place    last place that qualifies; NULL = open-ended
 *   min_points   a player with at least this many points also qualifies
 *   payout       amount each qualifying player receives; NULL when the row
 *                is a split pot
 *   total_payout the split pot, shared equally by everyone who qualifies
 */
class WeekFormatPayout extends BaseModel
{
	const PLACE_OVERALL = 'overall';
	const PLACE_POOL = 'pool';
	const PLACE_TEAM = 'team';

	protected $table = 'football_week_format_payouts';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = [];

	public function format()
	{
		return $this->belongsTo(WeekFormat::class, 'football_week_format_id');
	}

	/**
	 * @return bool
	 */
	public function isSplitPot()
	{
		return $this->payout === null;
	}

	/**
	 * "1st", "2nd-6th", "2nd-4th or 41+ pts", "41+ pts"
	 *
	 * @return string
	 */
	public function getPlaceLabel()
	{
		$parts = [];
		$min = (int) $this->min_place;
		if ($min) {
			if ($this->max_place === null) {
				$parts[] = ordinal($min) . '+';
			}
			elseif ((int) $this->max_place == $min) {
				$parts[] = ordinal($min);
			}
			else {
				$parts[] = ordinal($min) . '-' . ordinal((int) $this->max_place);
			}
		}
		if ($this->min_points !== null) {
			$parts[] = (int) $this->min_points . '+ pts';
		}
		return implode(' or ', $parts);
	}

	/**
	 * "$130", "$47.50", "split $190"
	 *
	 * @return string
	 */
	public function getAmountLabel()
	{
		if (!$this->isSplitPot()) {
			return '$' . self::money($this->payout);
		}
		if ($this->total_payout) {
			return 'split $' . self::money($this->total_payout);
		}
		return 'split';
	}

	/**
	 * @param mixed $amount
	 * @return string
	 */
	public static function money($amount)
	{
		$amount = (float) $amount;
		if (abs($amount - round($amount)) < 0.005) {
			return number_format($amount, 0);
		}
		return number_format($amount, 2);
	}
}
