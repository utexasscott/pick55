<?php

namespace Pick55;

use Pick55\Models\SiteHit;

/**
 * Site tracking: one er_site_hits row per page request, written when the
 * request ends so the row carries the response status and the time taken.
 * See docs/site-traffic.md.
 *
 * Tracking never breaks a page: any failure (the table missing, the
 * database gone) is logged and the hit is dropped.
 */
class Traffic
{
	/**
	 * Requests that are not page views: JSON the pages poll or post to.
	 * Everything under r/api/ is skipped as well.
	 */
	const SKIP = [
		'season/week/save-picks.php',
		'season/week/live.php',
		'season/week/raw.php',
	];

	/** @var bool */
	private static $started = false;
	/** @var int|null  the user signed in when the request began */
	private static $user_id = null;

	private function __construct() {}

	/**
	 * Runs on every request, from inc/_inc.php.
	 */
	public static function start()
	{
		if (self::$started || PHP_SAPI === 'cli') {
			return;
		}
		self::$started = true;
		$rel = SiteVersion::requestRel();
		if ($rel === null || !self::isTracked($rel)) {
			return;
		}
		// A fragment fetched on hover may never be shown; app.js reports it
		// through r/api/hit.php when it is.
		if (isset($_SERVER['HTTP_X_P55_PREFETCH']) && $_SERVER['HTTP_X_P55_PREFETCH'] === '1') {
			return;
		}
		self::$user_id = Auth::getAuthedUserId();
		register_shutdown_function([self::class, 'finish']);
	}

	/**
	 * @param string $rel  site-relative script path
	 * @return bool
	 */
	public static function isTracked($rel)
	{
		if (strpos($rel, 'r/api/') === 0) {
			return false;
		}
		return !in_array($rel, self::SKIP, true);
	}

	/**
	 * The shutdown function: records the request that just ended.
	 */
	public static function finish()
	{
		$started = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null;
		$is_partial = (isset($_SERVER['HTTP_X_P55_PARTIAL']) && $_SERVER['HTTP_X_P55_PARTIAL'] === '1')
			|| (isset($_GET['_partial']) && $_GET['_partial'] === '1');
		// Signing out ends the request with nobody signed in: keep who it was
		$user_id = Auth::getAuthedUserId() ?: self::$user_id;
		self::record([
			'er_user_id' => $user_id ? (int) $user_id : null,
			'method' => isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET',
			'path' => (string) SiteVersion::requestRel(),
			'query' => isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '',
			'status' => (int) http_response_code(),
			'is_partial' => $is_partial ? 1 : 0,
			'duration_ms' => $started ? max(0, (int) round((microtime(true) - $started) * 1000)) : null,
		]);
	}

	/**
	 * A page view app.js reports itself: a prefetched fragment it has shown.
	 *
	 * @param string $rel  site-relative script path, e.g. 'r/season/standings.php'
	 * @param string $query
	 * @return bool  recorded
	 */
	public static function view($rel, $query = '')
	{
		$user_id = Auth::getAuthedUserId();
		return self::record([
			'er_user_id' => $user_id ? (int) $user_id : null,
			'method' => 'GET',
			'path' => (string) $rel,
			'query' => (string) $query,
			'status' => 200,
			'is_partial' => 1,
			'duration_ms' => null,
		]);
	}

	/**
	 * @param array $hit  er_user_id, method, path, query, status, is_partial, duration_ms
	 * @return bool
	 */
	private static function record(array $hit)
	{
		try {
			$hit['created_at'] = now();
			$hit['version'] = SiteVersion::current($hit['path']);
			$hit['preference'] = SiteVersion::preference();
			$hit['method'] = substr($hit['method'], 0, 8);
			$hit['path'] = self::clip($hit['path'], 191);
			$hit['query'] = (string) self::clip(self::cleanQuery($hit['query']), 255);
			$hit['ip'] = self::clip(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null, 45);
			$hit['user_agent'] = self::clip(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null, 255);
			$hit['referrer'] = self::clip(self::cleanQuery(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null), 255);
			SiteHit::create($hit);
			return true;
		}
		catch (\Throwable $e) {
			error_log('Traffic: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * A query string (or a URL) without what must not be kept or is noise:
	 * verify and reset tokens are masked, the switch and fragment markers
	 * are dropped.
	 *
	 * @param string|null $str
	 * @return string|null
	 */
	public static function cleanQuery($str)
	{
		if ($str === null || $str === '') {
			return $str;
		}
		$str = preg_replace('~(^|[?&])(token=)[^&#]*~i', '$1$2*', (string) $str);
		$str = preg_replace('~(^|[?&])(?:_partial|' . SiteVersion::PARAM . ')=[^&#]*~', '$1', $str);
		$str = preg_replace('~&{2,}~', '&', $str);
		$str = str_replace('?&', '?', $str);
		return rtrim(ltrim($str, '&'), '?&');
	}

	/**
	 * @param mixed $val
	 * @param int $length
	 * @return string|null  valid UTF-8, at most $length characters
	 */
	private static function clip($val, $length)
	{
		if ($val === null || $val === '') {
			return $val;
		}
		$val = mb_convert_encoding((string) $val, 'UTF-8', 'UTF-8');
		// Four-byte characters do not fit the table's utf8 columns
		$val = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $val);
		return mb_substr((string) $val, 0, $length, 'UTF-8');
	}

	/**
	 * @param string|null $user_agent
	 * @return string  'Phone', 'Tablet', 'Desktop', 'Bot' or ''
	 */
	public static function device($user_agent)
	{
		$ua = (string) $user_agent;
		if ($ua === '') {
			return '';
		}
		if (preg_match('~bot|crawl|spider|slurp|curl|wget|python|monitor|headless~i', $ua)) {
			return 'Bot';
		}
		if (preg_match('~ipad|tablet|android(?!.*mobile)~i', $ua)) {
			return 'Tablet';
		}
		if (preg_match('~mobi|iphone|ipod|android~i', $ua)) {
			return 'Phone';
		}
		return 'Desktop';
	}
}
