<?php

/**
 * Parses stored VegasInsider odds pages (scrape/raw/vegas-insider/<league>/<stamp>.html)
 * into sibling <stamp>.json files in the shape admin/weeks/week/bulk-games.php consumes.
 *
 *   php scrape/parse-raw.php              parse every .html that has no .json yet
 *   php scrape/parse-raw.php --all        re-parse every .html
 *   php scrape/parse-raw.php <file.html>  parse one file (always re-parses)
 *
 * See docs/odds-scraper.md for the page structure and the JSON fields.
 */

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\VegasInsider;

$args = array_slice($argv, 1);
$reparse = in_array('--all', $args);
$files = [];
foreach ($args as $arg) {
	if ($arg == '--all') {
		continue;
	}
	$files[] = $arg;
	$reparse = true;
}
if (!sizeof($files)) {
	foreach (array_keys(VegasInsider::getUrls()) as $league) {
		foreach (VegasInsider::listRawFiles($league) as $file) {
			$files[] = $file;
		}
	}
}

$exit = 0;
foreach ($files as $file) {
	$json = VegasInsider::jsonPathFor($file);
	if (!$reparse && file_exists($json)) {
		continue;
	}
	print "Parsing " . $file . "..\n";
	try {
		$games = VegasInsider::parseFile($file);
		print "\tOK " . sizeof($games) . " games -> " . $json . "\n";
	}
	catch (Exception $e) {
		fwrite(STDERR, "\tFAILED: " . $e->getMessage() . "\n");
		$exit = 1;
	}
}
exit($exit);
