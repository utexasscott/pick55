<?php

use TroShared\Models\Football\GameAll;
use TroShared\Models\Football\Team;

class OddsPageParser
{
	/**
	 * @var string
	 */
	public $type;

	/**
	 * @var string
	 */
	public $path;

	/**
	 * @var DOMDocument
	 */
	public $dom;

	/**
	 * @var DOMXPath
	 */
	public $xpath;

	/**
	 * @param string $type
	 * @param string $path
	 * @param array $options
	 */
	public function __construct($type, $path)
	{
		$this->type = strtolower($type);
		$this->path = $path;
		$this->dom = new \DOMDocument('1.0', 'UTF-8');
		$this->dom->preserveWhiteSpace = false;
		$this->dom->formatOutput = true;
		$raw = file_get_contents($this->path);
		$raw = str_replace('&nbsp;', ' ', $raw);
		$raw = str_replace('½', '.5', $raw);
		@$this->dom->loadHTML($raw);
		$this->xpath = new DOMXPath($this->dom);
	}

	/**
	 * @return array
	 */
	public static function getCols()
	{
		return [
			'open',
			'vi_consensus',
			'westgate_superbook',
			'mgm_mirage',
			'play_mgm',
			'william_hill',
			'cg_tech',
			'circa_sports',
			'stations',
		];
	}

	/**
	 * @return array
	 */
	public function parse()
	{
		$data = [];
		$cols = self::getCols();
		try {
			$rows = $this->getNodes("//table[@class='frodds-data-tbl']/tbody/tr");
			if (!sizeof($rows)) throw new Exception("No rows in odds table.");
			foreach ($rows as $row) {
				$cells = $this->getNodes("td", $row);
				if (!sizeof($cells)) {
					continue;
				}
				$cell = array_shift($cells);
				if ($cell->childNodes->length != 9) {
					continue;
				}
				$url1 = $this->getNodeValue("b[1]/a/@href", $cell);
				$url2 = $this->getNodeValue("b[2]/a/@href", $cell);
				$slug1 = substr($url1, strrpos($url1, '/') + 1);
				$slug2 = substr($url2, strrpos($url2, '/') + 1);
				$rowdata = [
					'at' => date("Y-m-d g:ia", strtotime($this->getNodeValue("span[1]", $cell))),
					'team1' => [
						'num' => $this->getNodeValue("text()[2]", $cell),
						'name' => $this->getNodeValue("b[1]", $cell),
						'slug' => $slug1,
					],
					'team2' => [
						'num' => $this->getNodeValue("text()[3]", $cell),
						'name' => $this->getNodeValue("b[2]", $cell),
						'slug' => $slug2,
					],
					'lines' => [],
				];
				foreach ($cells as $i => $cell) {
					if ($this->nodeHasClass($cell, 'betnow-picks')) {
						continue;
					}
					$node = $this->getNode("a[1]", $cell);
					$key = 'line#' . $i;
					if (array_key_exists($i, $cols)) {
						$key = $cols[$i];
					}
					$rowdata['lines'][$key] = [
						$this->getNodeValue("text()[2]", $node),
						$this->getNodeValue("text()[3]", $node),
					];
				}
				$data[] = $rowdata;
			}
		}
		catch (Exception $e) {
			die($e->getMessage());
		}
		return $data;
	}

	/**
	 * @param array $data
	 */
	public function process(array $data = [])
	{
		foreach ($data as $i => $rowdata) {
			$away_team = Team::where('type', 'LIKE', $this->type)
				->where('vegas_insider_url', 'LIKE', $rowdata['team1']['slug'])
				->first();
			$home_team = Team::where('type', 'LIKE', $this->type)
				->where('vegas_insider_url', 'LIKE', $rowdata['team2']['slug'])
				->first();
			if (!$home_team) {
				print "WARNING: could not find home team '{$rowdata['team2']['slug']}' in row {$i}\n";
				continue;
			}
			if (!$away_team) {
				print "WARNING: could not find away team '{$rowdata['team1']['slug']}' in row {$i}\n";
				continue;
			}
			$game = GameAll::firstOrCreate([
				'type' => $this->type,
				'home_team_id' => $home_team->id,
				'away_team_id' => $away_team->id,
				'datetime' => date("Y-m-d H:i:s", strtotime($rowdata['at'])),
			]);
			if (isset($rowdata['lines']['vi_consensus'])) {
				$line0 = $rowdata['lines']['vi_consensus'][0];
				$line1 = $rowdata['lines']['vi_consensus'][1];
				$points_regex = '/^(\d+(?:\.5)?)(u|o)/';
				$spread_regex = '/^(PK|-\d+(?:\.5)?)/';
				$is_away_favored = false;
				$m_points = null;
				$m_spread = null;
				if (preg_match($points_regex, $line0, $m_points)) {
					preg_match($spread_regex, $line1, $m_spread);
				}
				elseif (preg_match($points_regex, $line1, $m_points)) {
					$is_away_favored = true;
					preg_match($spread_regex, $line0, $m_spread);
				}
				if ($m_points && $m_spread) {
					$points = $m_points[1];
					$spread = $m_spread[1];
					if (round($points) == round($points, 1)) {
						$points += 0.5;
					}
					if (round($spread) == round($spread, 1)) {
						$spread += 0.5;
					}
					$game->total_points = $points;
					if ($is_away_favored) {
						$game->spread = $spread;
					}
					else {
						$game->spread = -1 * $spread;
					}
					$game->save();
				}
			}
		}
	}

	/**
	 * @param DOMNode $node
	 * @param string $class
	 * @return bool
	 */
	public function nodeHasClass(DOMNode $node, $class)
	{
		$class_attr = $node->getAttribute('class');
		if (preg_match('/\b' . $class . '\b/i', $class_attr)) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $exp
	 * @param DOMNode $context
	 * @return array of DOMNode
	 */
	public function getNodes($exp, $context = null)
	{
		$node_list = $this->xpath->query($exp, $context);
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
	 * @param string $exp
	 * @param DOMNode $context
	 * @return DOMNode
	 */
	public function getNode($exp, $context = null)
	{
		$nodes = $this->getNodes($exp, $context);
		if (!sizeof($nodes)) {
			return null;
		}
		return array_shift($nodes);
	}

	/**
	 * @param string $exp
	 * @param DOMNode $context
	 * @return string
	 */
	public function getNodeValue($exp, $context = null)
	{
		$node = $this->getNode($exp, $context);
		if (!$node) {
			return '';
		}
		return trim($node->nodeValue);
	}

	/**
	 * @param DOMNode $node
	 */
	public function dumpNode(DOMNode $node)
	{
		foreach ($node->childNodes as $i => $child) {
			print $i . ". <" . $child->nodeName . "> " . trim($child->nodeValue) . "\n";
		}
	}
}
