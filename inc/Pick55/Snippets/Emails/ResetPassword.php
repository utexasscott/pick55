<?php

namespace Pick55\Snippets\Emails;

use Pick55\Snippets\Snippet;

class ResetPassword extends Snippet
{
	/**
	 * @param array $params
	 * @return string
	 */
	public static function build(array $params = [])
	{
		$params = array_merge([
			'link' => '',
		], $params);
		ob_start();
		?>
<p>A password reset has been requested. To reset your password, follow the link below.</p>
<p><a href="<?=$params['link']?>"><?=$params['link']?></a></p>
<p>If you did not initiate this request, please ignore this message.</p>
<p>- The Pick55 Team</p>
		<?php
		return ob_get_clean();
	}

	/**
	 * @param string $link
	 * @return string
	 */
	public static function b($link)
	{
		return self::build(['link' => $link]);
	}
}

