<?php

namespace Pick55\Snippets;

class Score extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'correct' => null,
			'incorrect' => null,
			'points' => null,
		], $params);
		if ($params['points'] === null) {
			return $params['correct'] . '-' . $params['incorrect']
				. ' (' . sprintf("%d", $params['correct'] / max(1, $params['incorrect'] + $params['correct']) * 100) . '%)';
		}
		return $params['correct'] . '-' . $params['incorrect']
			. ' (' . sprintf("%d", $params['correct'] / max(1, $params['incorrect'] + $correct) * 100) . '%) '
			. $params['points'] . 'pts.';
	}
}
