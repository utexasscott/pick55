<?php

namespace Pick55\Snippets;

class Rank extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'rank' => null,
		], $params);
		ob_start();
		?>
		<span class="badge text-dark" style="padding: 0; font-weight: normal!important;"><?=ordinal($params['rank'])?></span>
		<?php
		return ob_get_clean();
	}
}
