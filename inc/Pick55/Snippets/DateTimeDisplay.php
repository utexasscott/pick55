<?php

namespace Pick55\Snippets;

class DateTimeDisplay extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'datetime' => null,
			'connector' => ' @ ',
		], $params);
		if (!$params['datetime']) return '';
		$ts = strtotime($params['datetime']);
		if (!$ts) return '';
		$date = DateDisplay::b($params['datetime']);
		$time = TimeDisplay::b($params['datetime']);
		if ($date && $time) return $date . $params['connector'] . $time;
		if ($date) return $date;
		if ($time) return $time;
		return '';
	}

	/**
	 * @param string $datetime
	 * @return string
	 */
	public static function b($datetime)
	{
		return self::build(['datetime' => $datetime]);
	}
}
