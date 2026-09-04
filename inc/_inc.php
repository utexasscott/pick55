<?php

// ------
// Config
// ------

require_once __DIR__ . '/_config.php';

/**
 * Finds the given config key and returns it. If the key cannot be found,
 * returns the $default value.
 *
 * The $key can access a multidimensional array by using a dot '.' as a delimiter.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function config($key, $default = null) {
	global $__config;
	$parts = explode('.', $key);
	if (!sizeof($parts)) {
		return $default;
	}
	$cur = $__config;
	foreach ($parts as $part) {
		if (!isset($cur[$part])) {
			return $default;
		}
		$cur = $cur[$part];
	}
	return $cur;
}

// --------
// Settings
// --------

// Show all errors
error_reporting(E_ALL);

// Set the timezone
date_default_timezone_set('America/Chicago');

// Use UTF-8 for everything
setlocale(LC_CTYPE, 'en_US.utf8');

// Only store session ID in cookies client-side
ini_set('session.use_only_cookies', '1');

// Specify the hash function to generate session IDs ('0' => MD5, '1' => SHA-1)
ini_set('session.hash_function', '1');

// Set the lifetime for the session before it is garbage collected (in seconds)
ini_set('session.gc_maxlifetime', 60 * 60 * 24);

// Transparently compress pages
ini_set('zlib.output_compression', true);

// Session key
define('SKEY', 'pick55');

// -------
// Session
// -------

// Create a user fingerprint
$__fingerprint = md5('PICK55');

// Check for a valid saved session ID in the client's cookies
if (
	isset($_COOKIE['session_id'])
	&& isset($_COOKIE['fingerprint'])
	&& $_COOKIE['fingerprint'] == $__fingerprint
) {
	session_id($_COOKIE['session_id']);
	session_start();
}
else {
	session_start();

	// Check that the fingerprint matches or else regen the session
	if (
		isset($_SESSION['fingerprint'])
		&& $_SESSION['fingerprint'] != $__fingerprint
	) {
		session_regenerate_id();
		$_SESSION = [];
	}

	$timestamp = time() + 60 * 60 * 24 * 7;
	setcookie('session_id', session_id(), $timestamp, '/', '', false, true);
	setcookie('fingerprint', $__fingerprint, $timestamp, '/', '', false, true);
}

// Save the fingerprint in the session
$_SESSION['fingerprint'] = $__fingerprint;

if (!isset($_SESSION[SKEY])) {
	$_SESSION[SKEY] = [];
}

// --------
// Database
// --------

/**
 * @return MySQLi
 */
function get_mysqli() {
	static $mysqli = null;
	if ($mysqli === null) {
		$mysqli = new mysqli(
			config('db.host'),
			config('db.username'),
			config('db.password', ''),
			config('db.database'),
			config('db.port', 3306)
		);
		$mysqli->set_charset('utf8mb4');
	}
	return $mysqli;
}

// ------
// Vendor
// ------

require_once __DIR__ . '/../vendor/autoload.php';

// --------
// Eloquent
// --------

use Illuminate\Database\Capsule\Manager as Capsule;

$__capsule = new Capsule;
$__capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => config('db.host'),
    'database'  => config('db.database'),
    'username'  => config('db.username'),
    'password'  => config('db.password'),
    'port'      => config('db.port', 3306),
    'charset'   => 'utf8',
    'collation' => 'utf8_unicode_ci',
    'prefix'    => '',
]);

// Set the event dispatcher used by Eloquent models... (optional)
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
$__capsule->setEventDispatcher(new Dispatcher(new Container));

// Make this Capsule instance available globally via static methods... (optional)
$__capsule->setAsGlobal();

// Setup the Eloquent ORM... (optional; unless you've used setEventDispatcher())
$__capsule->bootEloquent();

// --------
// Autoload
// --------

spl_autoload_register(function ($class_name) {
	$path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', '/', $class_name) . '.php';
	if (file_exists($path)) {
		require_once $path;
		return true;
	}
	return false;
});

// -------
// Library
// -------

require_once __DIR__ . '/funcs.php';

// ---
// App
// ---

use Pick55\App;
$app = App::get();

// Global Post Handler
require_once __DIR__ . '/global_post_handler.php';
