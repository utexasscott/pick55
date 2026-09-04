<?php

require_once '../common/config.php';
require_once 'OddsPageGrabber.php';
require_once 'OddsPageParser.php';

foreach (['nfl', 'ncaa'] as $type) {
	print "Collecting odds for " . strtoupper($type) . "..";
	$page = OddsPageGrabber::get($type);
	$parser = new OddsPageParser($type, $page);
	$parser->process($parser->parse());
	print "OK\n";
}
