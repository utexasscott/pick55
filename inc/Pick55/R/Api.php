<?php

namespace Pick55\R;

use Pick55\Auth;

/**
 * Helpers for the JSON endpoints under r/api/ (docs/redesign.md section 6).
 * Every response is JSON, errors included: {"error": "..."} with a 4xx or
 * 500 status. The first helper called turns PHP warnings and uncaught
 * exceptions into a JSON 500, so an endpoint never answers with HTML.
 *
 *   require_once __DIR__ . '/../../inc/_inc.php';
 *   use Pick55\R\Api;
 *   $me = Api::guard('GET');            // same-origin + method + signed in
 *   Api::json(['ok' => true]);
 */
class Api
{
	/** @var bool */
	private static $booted = false;
	/** @var array|null */
	private static $input = null;

	private function __construct() {}

	/**
	 * Idempotent: JSON error handling for the rest of the request.
	 */
	public static function boot()
	{
		if (self::$booted) {
			return;
		}
		self::$booted = true;
		ini_set('display_errors', '0');
		set_error_handler(function ($severity, $message, $file, $line) {
			if (!(error_reporting() & $severity)) {
				return false;
			}
			throw new \ErrorException($message, 0, $severity, $file, $line);
		}, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
		set_exception_handler(function ($e) {
			error_log('r/api: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
			self::json(['error' => 'Unexpected error.'], 500);
		});
	}

	/**
	 * Refuses requests that did not come from this site: accepts
	 * `Sec-Fetch-Site: same-origin` or an `X-Requested-With` header (which
	 * a cross-origin page cannot send without a CORS preflight we never grant).
	 */
	public static function requireSameOrigin()
	{
		self::boot();
		$site = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
		if ($site === 'same-origin') {
			return;
		}
		if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
			return;
		}
		self::error('Cross-origin request refused.', 403);
	}

	/**
	 * @return \Pick55\Models\User  the signed-in user; otherwise a 401 {"error", "login"}
	 */
	public static function requireUser()
	{
		self::boot();
		$user = Auth::user();
		if (!$user) {
			self::json([
				'error' => 'You have been signed out.',
				'login' => config('base_url') . 'r/auth/login.php',
			], 401);
		}
		return $user;
	}

	/**
	 * @param string $method  'GET' or 'POST'
	 */
	public static function requireMethod($method)
	{
		self::boot();
		$actual = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
		if ($actual !== strtoupper($method)) {
			header('Allow: ' . strtoupper($method));
			self::error('Method not allowed.', 405);
		}
	}

	/**
	 * The usual preamble: same origin, method (when given), signed in.
	 *
	 * @param string|null $method
	 * @return \Pick55\Models\User
	 */
	public static function guard($method = null)
	{
		self::requireSameOrigin();
		if ($method) {
			self::requireMethod($method);
		}
		return self::requireUser();
	}

	/**
	 * The request body: a JSON object when the body is JSON, else $_POST.
	 *
	 * @param string|null $key  one field, or null for the whole body
	 * @param mixed $default
	 * @return mixed
	 */
	public static function input($key = null, $default = null)
	{
		if (self::$input === null) {
			self::$input = [];
			$type = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
			if (strpos($type, 'application/json') !== false) {
				$raw = file_get_contents('php://input');
				if ($raw !== false && strlen($raw)) {
					$data = json_decode($raw, true);
					if (!is_array($data)) {
						self::error('Invalid JSON body.', 400);
					}
					self::$input = $data;
				}
			}
			else {
				self::$input = $_POST;
			}
		}
		if ($key === null) {
			return self::$input;
		}
		return array_key_exists($key, self::$input) ? self::$input[$key] : $default;
	}

	/**
	 * Sends JSON and ends the request.
	 *
	 * @param mixed $data
	 * @param int $status
	 */
	public static function json($data, $status = 200)
	{
		if (!headers_sent()) {
			http_response_code((int) $status);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			header('X-Content-Type-Options: nosniff');
		}
		print json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		exit();
	}

	/**
	 * @param string $message
	 * @param int $status
	 */
	public static function error($message, $status = 400)
	{
		self::json(['error' => (string) $message], $status);
	}
}
