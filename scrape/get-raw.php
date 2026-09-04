<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\Models\Game;
use GuzzleHttp\Client;

$valid_leagues = Game::getLeagues();

try {
	// Get league from CLI param
	if (!isset($argv[1])) {
		throw new Exception("League is required.");
	}
	$league = trim(strtoupper($argv[1]));
	if (!in_array($league, $valid_leagues)) {
		throw new Exception("Invalid league.");
	}

	// Set target URL
	$url = '';
	if ($league == Game::LEAGUE_NFL) {
		$url = 'https://www.vegasinsider.com/nfl/odds/las-vegas/';
	}
	elseif ($league == Game::LEAGUE_NCAA) {
		$url = 'https://www.vegasinsider.com/college-football/odds/las-vegas/';
	}
	else {
		throw new Exception("No target URL for league '" . $league . "'.");
	}

	print "League : " . $league . "\n";
	print "URL    : " . $url . "\n";
}
catch (Exception $e) {
	print $e->getMessage() . "\n";
	print "Usage: $ php " . basename(__FILE__) . " <league>\n";
	print "\t<league>: " . implode(', ', $valid_leagues) . "\n";
	exit;
}

// Create folders for storage
if (!file_exists('raw')) {
	mkdir('raw');
}
if (!file_exists('raw' . DIRECTORY_SEPARATOR . 'vegas-insider')) {
	mkdir('raw' . DIRECTORY_SEPARATOR . 'vegas-insider');
}
if (!file_exists('raw' . DIRECTORY_SEPARATOR . 'vegas-insider' . DIRECTORY_SEPARATOR . strtolower($league))) {
	mkdir('raw' . DIRECTORY_SEPARATOR . 'vegas-insider' . DIRECTORY_SEPARATOR . strtolower($league));
}

print "Getting page HTML..\n";
$client = new Client();
$response = $client->get($url);
print "\tOK\n";
print "Saving page..\n";
file_put_contents('raw' . DIRECTORY_SEPARATOR . 'vegas-insider' . DIRECTORY_SEPARATOR . strtolower($league) . DIRECTORY_SEPARATOR . date("Y-m-d-H-i-s") . '.html', $response->getBody());
print "\tOK\n";
