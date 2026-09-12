<?php

namespace Pick55;

/**
 * Minimal file cache. Entries are serialized PHP values written atomically to
 * config('cache_dir') (default: <repo>/cache, falling back to the system temp
 * dir when that is not writable). Keys are sanitized to a safe filename.
 */
class Cache
{
	private static $dir = null;

	/**
	 * @return string absolute directory path, without trailing slash
	 */
	public static function dir()
	{
		if (self::$dir !== null) {
			return self::$dir;
		}
		$candidates = [
			config('cache_dir', __DIR__ . '/../../cache'),
			sys_get_temp_dir() . '/pick55-cache',
		];
		foreach ($candidates as $dir) {
			if (!is_dir($dir)) {
				@mkdir($dir, 0775, true);
			}
			if (is_dir($dir) && is_writable($dir)) {
				self::$dir = rtrim($dir, '/\\');
				return self::$dir;
			}
		}
		// Nothing writable: cache becomes a no-op.
		self::$dir = '';
		return self::$dir;
	}

	/**
	 * @param string $key
	 * @return string
	 */
	private static function path($key)
	{
		return self::dir() . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.cache';
	}

	/**
	 * @param string $key
	 * @return mixed or null when missing
	 */
	public static function get($key)
	{
		if (self::dir() === '') {
			return null;
		}
		$path = self::path($key);
		if (!is_file($path)) {
			return null;
		}
		$raw = @file_get_contents($path);
		if ($raw === false || $raw === '') {
			return null;
		}
		$val = @unserialize($raw);
		if ($val === false && $raw !== serialize(false)) {
			return null;
		}
		return $val;
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 */
	public static function set($key, $value)
	{
		if (self::dir() === '') {
			return;
		}
		$path = self::path($key);
		$tmp = $path . '.' . uniqid('', true) . '.tmp';
		if (@file_put_contents($tmp, serialize($value), LOCK_EX) === false) {
			return;
		}
		if (!@rename($tmp, $path)) {
			// Windows cannot rename over an open file; fall back to a direct write.
			@file_put_contents($path, serialize($value), LOCK_EX);
			@unlink($tmp);
		}
	}

	/**
	 * Deletes every entry whose key starts with $prefix, except those whose key
	 * starts with $keep_prefix (when given).
	 *
	 * @param string $prefix
	 * @param string or null $keep_prefix
	 */
	public static function forgetPrefix($prefix, $keep_prefix = null)
	{
		if (self::dir() === '') {
			return;
		}
		$safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $prefix);
		$keep = $keep_prefix === null ? null : self::path($keep_prefix);
		$keep = $keep === null ? null : substr($keep, 0, -strlen('.cache'));
		foreach (glob(self::dir() . '/' . $safe . '*.cache') ?: [] as $file) {
			if ($keep !== null && strpos($file, $keep) === 0) {
				continue;
			}
			@unlink($file);
		}
	}

	/**
	 * Returns the cached value for $key, computing and storing it when missing.
	 * A per-$lock_name file lock makes concurrent requests wait for one compute
	 * instead of all computing at once.
	 *
	 * @param string $key
	 * @param string $lock_name
	 * @param callable $compute
	 * @return mixed
	 */
	public static function remember($key, $lock_name, callable $compute)
	{
		$val = self::get($key);
		if ($val !== null) {
			return $val;
		}
		$fp = null;
		if (self::dir() !== '') {
			$fp = @fopen(self::dir() . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $lock_name) . '.lock', 'c');
		}
		if ($fp) {
			flock($fp, LOCK_EX);
			// Another request may have filled it while we waited.
			$val = self::get($key);
			if ($val !== null) {
				flock($fp, LOCK_UN);
				fclose($fp);
				return $val;
			}
		}
		try {
			$val = $compute();
			self::set($key, $val);
		}
		finally {
			if ($fp) {
				flock($fp, LOCK_UN);
				fclose($fp);
			}
		}
		return $val;
	}
}
