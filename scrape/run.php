<?php

/**
 * Cron entry point for the VegasInsider scraper.
 *
 * Exits silently (status 0) unless a football_weeks row has picks_due_date in the next 7 days.
 * When one does, fetches and parses both league pages and writes one log line per league on
 * stdout (cron appends stdout to /home/beanstalk/logs/scrape/). A failed league is logged and
 * makes the exit status 1; the other league still runs.
 *
 *   php scrape/run.php            normal cron run
 *   php scrape/run.php --check    print the week decision only, fetch nothing
 *   php scrape/run.php --force    fetch and parse regardless of the week check
 *
 * Runs under PHP 8.0 on the droplet and 7.4 locally; keep the syntax valid on both.
 * See docs/odds-scraper.md.
 */

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\VegasInsider;
use Pick55\Models\Week;

const LOOKAHEAD_DAYS = 7;

$args = array_slice($argv, 1);
$check = in_array('--check', $args);
$force = in_array('--force', $args);

$now = time();
$week = Week::where('picks_due_date', '>', date('Y-m-d H:i:s', $now))
	->where('picks_due_date', '<', date('Y-m-d H:i:s', $now + LOOKAHEAD_DAYS * 86400))
	->orderBy('picks_due_date', 'ASC')
	->first();

$week_label = 'no week';
if ($week) {
	$week_label = 'week #' . $week->id . ' (season ' . $week->football_season_id . ' week ' . $week->week_num
		. ', picks due ' . $week->picks_due_date . ')';
}

if ($check) {
	if ($week) {
		print "Would run: " . $week_label . " has picks due within " . LOOKAHEAD_DAYS . " days.\n";
	}
	else {
		print "Would skip: no week has picks due within " . LOOKAHEAD_DAYS . " days.\n";
	}
	exit(0);
}

if (!$week && !$force) {
	exit(0);
}

$exit = 0;
foreach (array_keys(VegasInsider::getUrls()) as $league) {
	try {
		$result = VegasInsider::scrape($league);
		VegasInsider::log($week_label . ': ' . $league . ' ' . sizeof($result['games']) . ' games -> ' . $result['json']);
	}
	catch (Exception $e) {
		VegasInsider::log($week_label . ': ' . $league . ' FAILED: ' . $e->getMessage());
		$exit = 1;
	}
}
exit($exit);
