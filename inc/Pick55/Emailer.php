<?php

namespace Pick55;

class Emailer
{
	const FROM_EMAIL = 'support@pick55.com';
	const FROM_NAME = 'Pick55 Team';
	private function __construct() {}

	/**
	 * @param string $to_email
	 * @param string $to_name
	 * @param string $subject
	 * @param string $html
	 * @return bool
	 */
	public static function send($to_email, $to_name, $subject, $html)
	{
		$sendgrid_email = new \SendGrid\Mail\Mail();
		$sendgrid_email->setFrom(self::FROM_EMAIL, self::FROM_NAME);
		$sendgrid_email->setSubject($subject);
		if (config('dev_email_redir')) {
			$prepend = '<p><i>This message originally intented for <b>' . $to_name . ' &lt;' . $to_email . '&gt;</b></i></p>';
			$to_email = config('dev_email_redir');
			$to_name = 'pick55.dev_email_redir';
			$html = $prepend . $html;
		}
		$sendgrid_email->addTo($to_email, $to_name);
		$sendgrid_email->addContent('text/html', $html);
		$sendgrid = new \SendGrid(config('sendgrid_api_key'));
		try {
			$response = $sendgrid->send($sendgrid_email);
		}
		catch (Exception $e) {
			return false;
		}
		return true;
	}
}
