<?php

/**
 * Fetches one league's VegasInsider odds page and stores it as
 * scrape/raw/vegas-insider/<league>/<Y-m-d-H-i-s>.html. Does not parse, so it does not move
 * past an ending week the way VegasInsider::scrape() (run.php) does; pass the week key for that.
 *
 *   php scrape/get-raw.php nfl
 *   php scrape/get-raw.php ncaa
 *   php scrape/get-raw.php nfl 2026-reg-4
 *
 * See docs/odds-scraper.md.
 */

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\VegasInsider;

try {
	if (!isset($argv[1])) {
		throw new Exception("League is required.");
	}
	$league = VegasInsider::league($argv[1]);
	$week_key = isset($argv[2]) ? $argv[2] : null;
}
catch (Exception $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	fwrite(STDERR, "Usage: php " . basename(__FILE__) . " <league> [week key]\n");
	fwrite(STDERR, "\t<league>: " . implode(', ', array_keys(VegasInsider::getUrls())) . "\n");
	fwrite(STDERR, "\t[week key]: the site's ?week= value, e.g. 2026-reg-4; default: the page's current week\n");
	exit(1);
}

try {
	print "League : " . $league . "\n";
	print "URL    : " . VegasInsider::getUrls()[$league] . ($week_key !== null ? '?week=' . $week_key : '') . "\n";
	print "Getting page HTML..\n";
	$html = VegasInsider::fetch($league, $week_key);
	print "\tOK (" . number_format(strlen($html)) . " bytes)\n";
	print "Saving page..\n";
	$path = VegasInsider::saveRaw($league, $html);
	print "\tOK " . $path . "\n";
}
catch (Exception $e) {
	fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
	exit(1);
}
