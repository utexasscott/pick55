<?php

namespace Pick55\Snippets;

class GameOptionClass extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'game' => null,
			'option' => null,
		], $params);
		if ($params['game']) {
			if ($params['game']->correct_option == '0') {
				return '';
			}
			if ($params['game']->correct_option == $params['option']) {
				return 'fw-bold';
			}
		}
		return 'text-decoration-line-through';
	}
}
