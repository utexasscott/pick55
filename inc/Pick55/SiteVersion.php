<?php

namespace Pick55;

/**
 * Which of the two player sites a visitor has chosen, the classic one or the
 * redesign under r/ ("Version 2.0"), and the redirects that keep them on it.
 * See docs/site-version.md.
 *
 * The choice lives in the site_version cookie, per browser. It is written
 * only when the visitor switches: every switch link carries ?site=classic or
 * ?site=r. Without the cookie nothing is redirected.
 */
class SiteVersion
{
	const CLASSIC = 'classic';
	const REDESIGN = 'r';

	const COOKIE = 'site_version';
	const PARAM = 'site';

	// How long the choice is kept without a visit (in seconds)
	const LIFETIME = 31536000;

	/** @var string|null|false  false until read */
	private static $preference = false;

	private function __construct() {}

	/**
	 * @param mixed $version
	 * @return bool
	 */
	public static function isValid($version)
	{
		return $version === self::CLASSIC || $version === self::REDESIGN;
	}

	/**
	 * @param string|null $version
	 * @return string
	 */
	public static function label($version)
	{
		if ($version === self::REDESIGN) {
			return 'Version 2.0';
		}
		if ($version === self::CLASSIC) {
			return 'Classic';
		}
		return 'No choice';
	}

	/**
	 * @return string  the site's base path: '/pick55/' locally, '/' on production
	 */
	public static function basePath()
	{
		$path = trim((string) parse_url((string) config('base_url'), PHP_URL_PATH), '/');
		return $path === '' ? '/' : '/' . $path . '/';
	}

	/**
	 * The script serving this request, relative to the site: 'index.php',
	 * 'r/season/standings.php'. Null outside a web request.
	 *
	 * @return string|null
	 */
	public static function requestRel()
	{
		if (PHP_SAPI === 'cli' || !isset($_SERVER['SCRIPT_NAME'])) {
			return null;
		}
		$script = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);
		$base = self::basePath();
		if (strpos($script, $base) !== 0) {
			return null;
		}
		return (string) substr($script, strlen($base));
	}

	/**
	 * @param string|null $rel  a site-relative path; the current request's by default
	 * @return string  the version that serves it
	 */
	public static function current($rel = null)
	{
		if ($rel === null) {
			$rel = (string) self::requestRel();
		}
		return strpos($rel, 'r/') === 0 ? self::REDESIGN : self::CLASSIC;
	}

	/**
	 * @return string|null  the visitor's choice, null when they never chose
	 */
	public static function preference()
	{
		if (self::$preference === false) {
			$value = isset($_COOKIE[self::COOKIE]) ? $_COOKIE[self::COOKIE] : null;
			self::$preference = self::isValid($value) ? $value : null;
		}
		return self::$preference;
	}

	/**
	 * Saves the choice in the cookie, good for LIFETIME from now.
	 *
	 * @param string $version
	 */
	public static function remember($version)
	{
		if (!self::isValid($version)) {
			return;
		}
		self::$preference = $version;
		if (!headers_sent()) {
			setcookie(self::COOKIE, $version, [
				'expires' => time() + self::LIFETIME,
				'path' => '/',
				'httponly' => true,
			]);
		}
	}

	/**
	 * The same page on the other site: a classic path's r/ twin, or an r/
	 * path's classic twin. Null when there is none (admin pages, JSON
	 * endpoints, r/api/).
	 *
	 * @param string $rel  site-relative script path
	 * @param string $to  the version wanted
	 * @return string|null
	 */
	public static function counterpart($rel, $to)
	{
		$rel = (string) $rel;
		if (!preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_/-]*\.php$~', $rel)) {
			return null;
		}
		$root = dirname(__DIR__, 2) . '/';
		if ($to === self::REDESIGN) {
			if (strpos($rel, 'r/') === 0 || strpos($rel, 'admin/') === 0) {
				return null;
			}
			return is_file($root . 'r/' . $rel) ? 'r/' . $rel : null;
		}
		if ($to === self::CLASSIC) {
			if (strpos($rel, 'r/') !== 0) {
				return null;
			}
			$sub = (string) substr($rel, 2);
			if (strpos($sub, 'api/') === 0) {
				return null;
			}
			return is_file($root . $sub) ? $sub : null;
		}
		return null;
	}

	/**
	 * @param string $version
	 * @return string  the site-relative path of a version's home page
	 */
	public static function home($version)
	{
		return $version === self::REDESIGN ? 'r/' : '';
	}

	/**
	 * The current page on the given site, as a link that also saves the
	 * choice: the "Version 2.0" and "Classic site" links.
	 *
	 * @param string $to
	 * @return string
	 */
	public static function switchLink($to)
	{
		$query = self::query();
		$query[self::PARAM] = $to;
		return config('base_url') . self::target($to) . '?' . http_build_query($query);
	}

	/**
	 * Runs on every request, from inc/_inc.php: saves a choice made through
	 * a switch link, then sends a GET for a page of the other site to the
	 * page's twin on the chosen site.
	 */
	public static function handle()
	{
		$rel = self::requestRel();
		if ($rel === null) {
			return;
		}
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
		$is_read = $method === 'GET' || $method === 'HEAD';

		// A switch link
		$asked = isset($_GET[self::PARAM]) ? $_GET[self::PARAM] : null;
		if (self::isValid($asked)) {
			self::remember($asked);
			if ($is_read) {
				self::redirect(self::target($asked));
			}
			return;
		}

		$preference = self::preference();
		if ($preference === null) {
			return;
		}

		// Every visit extends the cookie
		self::remember($preference);

		if (!$is_read || $preference === self::current($rel)) {
			return;
		}
		$twin = self::counterpart($rel, $preference);
		if ($twin !== null) {
			self::redirect(self::tidy($twin));
		}
	}

	/**
	 * Where the current page lives on the given site: the page itself, its
	 * twin, or that site's home page when it has no twin.
	 *
	 * @param string $to
	 * @return string  site-relative
	 */
	private static function target($to)
	{
		$rel = (string) self::requestRel();
		if (self::current($rel) === $to) {
			return self::tidy($rel);
		}
		$twin = self::counterpart($rel, $to);
		return $twin !== null ? self::tidy($twin) : self::home($to);
	}

	/**
	 * A directory's index.php is addressed as the directory when the request
	 * was ('/pick55/' stays 'r/', not 'r/index.php').
	 *
	 * @param string $rel
	 * @return string
	 */
	private static function tidy($rel)
	{
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = (string) parse_url($uri, PHP_URL_PATH);
		if (substr($path, -1) === '/' && substr($rel, -9) === 'index.php') {
			return (string) substr($rel, 0, -9);
		}
		return $rel;
	}

	/**
	 * @return array  the request's query without the switch and fragment markers
	 */
	private static function query()
	{
		$query = $_GET;
		unset($query[self::PARAM], $query['_partial']);
		return $query;
	}

	/**
	 * @param string $rel  site-relative; '' is the classic home page, which
	 *                     redir() would read as "the current URL"
	 */
	private static function redirect($rel)
	{
		$query = self::query();
		header('Location: ' . config('base_url') . $rel . (sizeof($query) ? '?' . http_build_query($query) : ''));
		exit();
	}
}
