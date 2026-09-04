<?php

namespace Pick55\Snippets;

class DateDisplay extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'date' => null,
			'format' => "D, M jS",
		], $params);
		if (!$params['date']) return '';
		$ts = strtotime($params['date']);
		if (!$ts) return '';
		if (date("Y") != date("Y", $ts)) {
			$params['format'] .= " Y";
		}
		return date($params['format'], $ts);
	}

	/**
	 * @param string $date
	 * @return string
	 */
	public static function b($date)
	{
		return self::build(['date' => $date]);
	}
}
