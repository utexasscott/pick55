<?php

namespace Pick55\Snippets;

/**
 * A dollar amount: "$1,234", "-$120", "$0". Whole dollars; cents are never
 * shown anywhere in the app.
 */
class Money extends Snippet
{
	/**
	 * @param array $params  amount (float), blank_zero (bool), signed (bool: prefix + on positive)
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'amount' => 0,
			'blank_zero' => false,
			'signed' => false,
		], $params);
		$amount = round((float) $params['amount']);
		if ($amount == 0 && $params['blank_zero']) {
			return '';
		}
		$str = '$' . number_format(abs($amount));
		if ($amount < 0) {
			return '-' . $str;
		}
		if ($amount > 0 && $params['signed']) {
			return '+' . $str;
		}
		return $str;
	}

	/**
	 * @param float $amount
	 * @param bool $blank_zero
	 * @param bool $signed
	 * @return string
	 */
	public static function b($amount, $blank_zero = false, $signed = false)
	{
		return self::build(['amount' => $amount, 'blank_zero' => $blank_zero, 'signed' => $signed]);
	}
}
