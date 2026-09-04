<?php

namespace Pick55;

class Alert
{
	private function __construct() {}

	/**
	 */
	public static function clear()
	{
		$_SESSION[SKEY]['alerts'] = [];
	}

	/**
	 * @return array
	 */
	public static function get()
	{
		self::init();
		return $_SESSION[SKEY]['alerts'];
	}

	/**
	 */
	public static function init()
	{
		if (!isset($_SESSION[SKEY]['alerts'])) {
			$_SESSION[SKEY]['alerts'] = [];
		}
	}

	/**
	 * @param string $msg
	 */
	public static function success($msg)
	{
		self::add($msg, 'success');
	}

	/**
	 * @param string $msg
	 */
	public static function warning($msg)
	{
		self::add($msg, 'warning');
	}

	/**
	 * @param string $msg
	 */
	public static function error($msg)
	{
		self::add($msg, 'error');
	}

	/**
	 * @param string $msg
	 */
	public static function info($msg)
	{
		self::add($msg, 'info');
	}

	/**
	 * @param string $msg
	 * @param string $type
	 */
	public static function add($msg, $type)
	{
		self::init();
		if (!isset($_SESSION[SKEY]['alerts'][$type])) {
			$_SESSION[SKEY]['alerts'][$type] = [];
		}
		if (strlen($msg)) {
			$_SESSION[SKEY]['alerts'][$type][] = $msg;
		}
	}

}
