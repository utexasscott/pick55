<?php

$__config = [
	'base_url' => '//127.0.0.1/pick55/', // with trailing forward slash
	'sendgrid_api_key' => '',
	'dev_email_redir' => null,
	// Optional. Writable directory for the results cache; defaults to <repo>/cache,
	// then the system temp dir. See docs/results-cache.md.
	// 'cache_dir' => __DIR__ . '/../cache',
	// Optional. CLI PHP the "Scrape now" button runs when the web server's PHP has no dom
	// extension (the droplet's Apache 7.4). Defaults to /usr/bin/php. See docs/odds-scraper.md.
	// 'scrape' => ['php_cli' => '/usr/bin/php'],
	'db' => [
		'host'     => '127.0.0.1',
		'username' => 'root',
		'password' => '',
		'database' => 'pick',
		'port'     => '3306', // default 3306
	],
];
