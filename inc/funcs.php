<?php

/**
 * @param mixed $val
 * @return array
 */
function arrayify($val) {
	if (!is_array($val)) {
		$val = array_filter([$val]);
	}
	return $val;
}

/**
 * @param string $val
 * @param string $default
 * @return string
 */
function ifempty($val, $default = '') {
	if (!$val || !strlen(trim($val))) {
		return $default;
	}
	return $val;
}

function sel($val1, $val2) {
	if ($val1 == $val2) return 'selected';
	return '';
}

function selb($val) {
	if ($val) return 'selected';
	return '';
}

/**
 * @param string $key
 * @param string $val
 * @return string
 */
function query_string_add($key, $val) {
	$qs = $_GET;
	$qs[$key] = $val;
	return '?' . http_build_query($qs);
}

/**
 * @param int $num
 * @return string
 */
function ordinal($num) {
	if ($num > 10 && $num < 14) {
		$suffix = 'th';
	}
	elseif ((substr($num, -1) == 1)) {
		$suffix = 'st';
	}
	elseif ((substr($num, -1) == 2)) {
		$suffix = 'nd';
	}
	elseif ((substr($num, -1) == 3)) {
		$suffix = 'rd';
	}
	else $suffix = 'th';
	return $num . $suffix;
}

/**
 * @return string
 */
function now() {
	return date("Y-m-d H:i:s");
}

/**
 * @return bool
 */
function is_post() {
	if (!isset($_SERVER)) {
		return false;
	}
	if (
		isset($_SERVER['REQUEST_METHOD'])
		&& $_SERVER['REQUEST_METHOD'] == 'POST'
	) {
		return true;
	}
	return false;
}

/**
 * @param string $key
 * @return string $default
 */
function input($key, $default = null) {
	$get = get($key);
	if ($get !== null) return $get;
	$post = post($key);
	if ($post !== null) return $post;
	return $default;
}

/**
 * @param string $key
 * @return string $default
 */
function get($key, $default = null) {
	if (isset($_GET[$key])) {
		return $_GET[$key];
	}
	return $default;
}

/**
 * @param string $key
 * @return string $default
 */
function post($key, $default = null) {
	if (isset($_POST[$key])) {
		return $_POST[$key];
	}
	return $default;
}

/**
 * @param string or null $path
 */
function redir($path = null) {
	if ($path) {
		$url = config('base_url') . $path;
	}
	else {
		$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
		$url = '//' . rtrim($host, '/') . '/' . ltrim($uri, '/');
	}
	header('Location: ' . $url);
	exit();
}

function ago($datetime, $precision = 2) {
	if (preg_match('/^\d+$/', $datetime)) {
		$at = $datetime;
	}
	else {
		$at = strtotime($datetime);
	}
	if (!$at) {
		return '';
	}
	$seconds = time() - $at;
	if ($seconds == 0) {
		return 'now';
	}
	$suffix = 'ago';
	if ($seconds < 0) {
		$suffix = 'from now';
		$seconds = -1 * $seconds;
	}
	$years = floor($seconds / 31536000);
	$seconds -= $years * 31536000;
	$months = floor($seconds / 2592000);
	$seconds -= $months * 2592000;
	$days = floor($seconds / 86400);
	$seconds -= $days * 86400;
	$hours = floor($seconds / 3600);
	$seconds -= $hours * 3600;
	$minutes = floor($seconds / 60);
	$seconds -= $minutes * 60;
	$parts = [];
	if ($years > 0) $parts[] = $years . 'yr' . ($years == 1 ? '' : 's');
	if ($months > 0) $parts[] = $months . 'mo' . ($months == 1 ? '' : 's');
	if ($days > 0) $parts[] = $days . 'd';
	if ($hours > 0) $parts[] = $hours . 'h';
	if ($minutes > 0) $parts[] = $minutes . 'm';
	if ($seconds > 0) $parts[] = $seconds . 's';
	$parts = array_slice($parts, 0, $precision);
	return implode(' ', $parts) . ' ' . $suffix;
}
