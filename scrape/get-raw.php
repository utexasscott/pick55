<?php

/**
 * Fetches one league's VegasInsider odds page and stores it as
 * scrape/raw/vegas-insider/<league>/<Y-m-d-H-i-s>.html. Does not parse.
 *
 *   php scrape/get-raw.php nfl
 *   php scrape/get-raw.php ncaa
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
}
catch (Exception $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	fwrite(STDERR, "Usage: php " . basename(__FILE__) . " <league>\n");
	fwrite(STDERR, "\t<league>: " . implode(', ', array_keys(VegasInsider::getUrls())) . "\n");
	exit(1);
}

try {
	print "League : " . $league . "\n";
	print "URL    : " . VegasInsider::getUrls()[$league] . "\n";
	print "Getting page HTML..\n";
	$html = VegasInsider::fetch($league);
	print "\tOK (" . number_format(strlen($html)) . " bytes)\n";
	print "Saving page..\n";
	$path = VegasInsider::saveRaw($league, $html);
	print "\tOK " . $path . "\n";
}
catch (Exception $e) {
	fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
	exit(1);
}
