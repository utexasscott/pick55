<?php

namespace Pick55\Snippets;

class TimeDisplay extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'time' => null,
			'format' => "g:i A",
		], $params);
		if (!$params['time']) return '';
		$ts = strtotime($params['time']);
		if (!$ts) return '';
		return date($params['format'], $ts);
	}

	/**
	 * @param string $time
	 * @return string
	 */
	public static function b($time)
	{
		return self::build(['time' => $time]);
	}
}
