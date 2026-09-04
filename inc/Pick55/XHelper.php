<?php

namespace Pick55;

use \DOMNode;
use \DOMXPath;

class XHelper
{
	/**
	 * Returns true if the given node has the given CSS class assigned to it, false otherwise.
	 *
	 * @param DOMNode $node
	 * @param string $class
	 * @return bool
	 */
	public static function nodeHasClass(DOMNode $node, $class)
	{
		$class_attr = $node->getAttribute('class');
		if (preg_match('/\b' . $class . '\b/i', $class_attr)) {
			return true;
		}
		return false;
	}

	/**
	 * Given an expression, returns an array of DOMNodes that match.
	 * Optionally provide context.
	 *
	 * @param DOMXPath $xpath
	 * @param string $exp
	 * @param DOMNode $context
	 * @return array of DOMNode
	 */
	public static function getNodes(DOMXPath $xpath, $exp, DOMNode $context = null)
	{
		$node_list = $xpath->query($exp, $context);
		if (!$node_list->length) {
			return [];
		}
		$arr = [];
		foreach ($node_list as $node) {
			$arr[] = $node;
		}
		return $arr;
	}

	/**
	 * Given an expression, returns the first DOMNode that matches.
	 * Optionally provide context.
	 * Returns null if a node cannot be found.
	 *
	 * @param DOMXPath $xpath
	 * @param string $exp
	 * @param DOMNode $context
	 * @return DOMNode or null
	 */
	public static function getNode(DOMXPath $xpath, $exp, DOMNode $context = null)
	{
		$nodes = self::getNodes($xpath, $exp, $context);
		if (!sizeof($nodes)) {
			return null;
		}
		return array_shift($nodes);
	}

	/**
	 * Given an expression, returns the value of the first DOMNode that matches.
	 * Optionally provide context.
	 * Returns null if a node cannot be found.
	 *
	 * @param DOMXPath $xpath
	 * @param string $exp
	 * @param DOMNode $context
	 * @return string or null
	 */
	public static function getNodeValue(DOMXPath $xpath, $exp, DOMNode $context = null)
	{
		$node = self::getNode($xpath, $exp, $context);
		if (!$node) {
			return null;
		}
		return trim($node->nodeValue);
	}

	/**
	 * @param DOMNode $node
	 */
	public static function dumpNode(DOMNode $node)
	{
		print 'Parent Node: <' . $node->nodeName . '> ' . trim($node->nodeValue) . "\n";
		foreach ($node->childNodes as $i => $child) {
			print "\tChild: " . $i . ". <" . $child->nodeName . "> " . trim($child->nodeValue) . "\n";
		}
	}
}
