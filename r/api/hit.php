<?php

require_once __DIR__ . '/../../inc/_inc.php';

use Pick55\R\Api;
use Pick55\Traffic;

// Records a page view app.js showed from a prefetched fragment, whose own
// request site tracking skipped (docs/site-traffic.md).
//   POST {url: 'season/standings.php?id=18'}   (relative to r/)
//   -> {ok: true|false}
Api::guard('POST');

$url = (string) Api::input('url', '');
$parts = explode('?', $url, 2);
$path = $parts[0] === '' ? 'index.php' : $parts[0];
if (substr($path, -1) === '/') {
	$path .= 'index.php';
}
$query = isset($parts[1]) ? $parts[1] : '';

if (
	!preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_/-]*\.php$~', $path)
	|| strpos($path, 'api/') === 0
	|| !is_file(__DIR__ . '/../' . $path)
) {
	Api::error('Unknown page.', 404);
}

Api::json(['ok' => Traffic::view('r/' . $path, $query)]);
