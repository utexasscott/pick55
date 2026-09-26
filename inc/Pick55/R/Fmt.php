<?php

namespace Pick55\R;

use Pick55\Models\User;
use Pick55\Snippets\Money;

/**
 * Formatting for the redesign. Every function returns plain text (escape it
 * with h() when it goes into HTML). Times are the app's zone, America/Chicago.
 *
 * Datetime arguments accept a 'Y-m-d H:i:s' string, anything strtotime()
 * reads, or a Unix timestamp int.
 */
class Fmt
{
	private function __construct() {}

	/**
	 * Whole dollars through Pick55\Snippets\Money: "$1,234", "-$120", "$0".
	 *
	 * @param float $amount
	 * @param bool $blank_zero  '' for zero
	 * @param bool $signed  "+$40" for positive amounts
	 * @return string
	 */
	public static function money($amount, $blank_zero = false, $signed = false)
	{
		return Money::b($amount, $blank_zero, $signed);
	}

	/**
	 * @param int $num
	 * @return string "1st", "22nd", "13th"
	 */
	public static function ordinal($num)
	{
		return ordinal((int) $num);
	}

	/**
	 * @param int|float $num
	 * @param int $decimals
	 * @return string "1,234"
	 */
	public static function num($num, $decimals = 0)
	{
		return number_format((float) $num, $decimals);
	}

	/**
	 * @param int|float $num
	 * @return string "+3", "−2" (minus sign), "0"
	 */
	public static function signed($num)
	{
		$num = (int) round($num);
		if ($num > 0) {
			return '+' . $num;
		}
		if ($num < 0) {
			return "\u{2212}" . abs($num);
		}
		return '0';
	}

	/**
	 * @param mixed $datetime
	 * @return int|null
	 */
	public static function ts($datetime)
	{
		if ($datetime === null || $datetime === '' || $datetime === false) {
			return null;
		}
		if (is_int($datetime) || (is_string($datetime) && ctype_digit($datetime))) {
			return (int) $datetime;
		}
		$ts = strtotime((string) $datetime);
		return $ts === false ? null : $ts;
	}

	/**
	 * ISO 8601 with offset, for data attributes that app.js reads
	 * (data-countdown, data-reltime).
	 *
	 * @param mixed $datetime
	 * @return string '' when unknown
	 */
	public static function iso($datetime)
	{
		$ts = self::ts($datetime);
		return $ts === null ? '' : date('c', $ts);
	}

	/**
	 * @param mixed $datetime
	 * @return string "7:15 PM"
	 */
	public static function time($datetime)
	{
		$ts = self::ts($datetime);
		return $ts === null ? '' : date('g:i A', $ts);
	}

	/**
	 * A kickoff or deadline, relative to today: "Today 7:15 PM",
	 * "Tomorrow 3:25 PM", "Yesterday 11:00 AM", "Sat 11:00 AM" within six
	 * days either way, otherwise "Sat, Oct 4 · 11:00 AM".
	 *
	 * @param mixed $date  a date ('Y-m-d') with $time, or a full datetime alone
	 * @param string|null $time  'H:i:s'
	 * @param int|null $now  timestamp, for tests
	 * @return string
	 */
	public static function kickoff($date, $time = null, $now = null)
	{
		$ts = $time === null ? self::ts($date) : self::ts(trim($date . ' ' . $time));
		if ($ts === null) {
			return '';
		}
		$now = $now === null ? time() : (int) $now;
		$days = (int) round((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d', $now))) / 86400);
		$clock = date('g:i A', $ts);
		if ($days === 0) {
			return 'Today ' . $clock;
		}
		if ($days === 1) {
			return 'Tomorrow ' . $clock;
		}
		if ($days === -1) {
			return 'Yesterday ' . $clock;
		}
		if (abs($days) <= 6) {
			return date('D', $ts) . ' ' . $clock;
		}
		return date('D, M j', $ts) . " \u{00B7} " . $clock;
	}

	/**
	 * Time left until $datetime: "2d 4h", "4h 12m", "12m"; "0m" once past.
	 *
	 * @param mixed $datetime
	 * @param int|null $now
	 * @return string
	 */
	public static function countdown($datetime, $now = null)
	{
		$ts = self::ts($datetime);
		if ($ts === null) {
			return '';
		}
		$now = $now === null ? time() : (int) $now;
		$left = $ts - $now;
		if ($left <= 0) {
			return '0m';
		}
		$days = intdiv($left, 86400);
		$hours = intdiv($left % 86400, 3600);
		$minutes = intdiv($left % 3600, 60);
		if ($days > 0) {
			return $days . 'd ' . $hours . 'h';
		}
		if ($hours > 0) {
			return $hours . 'h ' . $minutes . 'm';
		}
		return max(1, $minutes) . 'm';
	}

	/**
	 * @param mixed $datetime
	 * @param bool $with_time  append " at 11:00 AM"
	 * @return string "Saturday, September 27" (the year is added when it is not this year)
	 */
	public static function dateLong($datetime, $with_time = false)
	{
		$ts = self::ts($datetime);
		if ($ts === null) {
			return '';
		}
		$str = date('l, F j', $ts);
		if (date('Y', $ts) !== date('Y')) {
			$str .= ', ' . date('Y', $ts);
		}
		if ($with_time) {
			$str .= ' at ' . date('g:i A', $ts);
		}
		return $str;
	}

	/**
	 * @param User|null $user
	 * @return string  User::getDisplayName(), never the email
	 */
	public static function name(User $user = null)
	{
		return $user ? $user->getDisplayName() : 'Unknown player';
	}

	/**
	 * @param User|null $user
	 * @return string "SN"
	 */
	public static function initials(User $user = null)
	{
		if (!$user) {
			return '?';
		}
		$first = trim((string) $user->first_name);
		$last = trim((string) $user->last_name);
		$mb = function_exists('mb_substr');
		$str = '';
		if ($first !== '') {
			$str .= $mb ? mb_substr($first, 0, 1) : substr($first, 0, 1);
		}
		if ($last !== '') {
			$str .= $mb ? mb_substr($last, 0, 1) : substr($last, 0, 1);
		}
		if ($str === '') {
			return '#';
		}
		return $mb ? mb_strtoupper($str) : strtoupper($str);
	}

	/**
	 * @param float $num
	 * @param float $den  100 when $num is already a percentage
	 * @param int $decimals
	 * @return string "54.2%", or an em dash when $den is 0
	 */
	public static function pct($num, $den = 100, $decimals = 1)
	{
		if (!(float) $den) {
			return "\u{2014}";
		}
		return number_format((float) $num / (float) $den * 100, $decimals) . '%';
	}

	/**
	 * @param int $right
	 * @param int $wrong
	 * @return string "34–18" (en dash)
	 */
	public static function record($right, $wrong)
	{
		return (int) $right . "\u{2013}" . (int) $wrong;
	}
}
