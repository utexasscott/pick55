<?php

class OddsPageGrabber
{
	private function __construct() {}

	/**
	 * @param string $type
	 * @return string
	 * @throws Exception
	 */
	public static function get($type)
	{
		$type = strtolower($type);
		switch ($type) {
			case 'nfl':
				return self::getNfl();
			case 'ncaa':
				return self::getNcaa();
		}
		throw new \Exception("Invalid type '" . $type . "'.");
	}

	/**
	 * @return string
	 */
	public static function getNcaa()
	{
		return trim(shell_exec("node get-odds-page.js ncaa"));
	}

	/**
	 * @return string
	 */
	public static function getNfl()
	{
		return trim(shell_exec("node get-odds-page.js nfl"));
	}
}
