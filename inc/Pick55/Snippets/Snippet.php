<?php

namespace Pick55\Snippets;

abstract class Snippet
{
	private function __construct() {}

	/**
	 * Build and return a snippet with the given parameters.
	 *
	 * @param array $params
	 * @return string
	 */
	abstract public static function build(array $params = []);

	/**
	 * Make a shortcut version of build like this:
	 *
	 * public static function b($foo) { return self::build(['foo' => $foo]); }
	 *
	 */
}
