<?php

require_once __DIR__ . '/../inc/_inc.php';

use Pick55\XHelper;
use Pick55\Models\Game;

$raw_dir = __DIR__ . DIRECTORY_SEPARATOR . trim('raw', '/\\');

foreach (scandir($raw_dir) as $source) {
	if (in_array($source, ['.', '..'])) {
		continue;
	}
	$parser = null;
	switch ($source) {
		case 'vegas-insider':
			$parser = new VegasInsiderParser;
			break;
	}
	if (!$parser) {
		print "No parser available for source '" . $source . "', skipping.\n";
		continue;
	}
	$source_path = $raw_dir . DIRECTORY_SEPARATOR . $source;
	foreach (scandir($source_path) as $league) {
		if (in_array($league, ['.', '..'])) {
			continue;
		}
		$league_path = $source_path . DIRECTORY_SEPARATOR . $league;
		foreach (scandir($league_path) as $file) {
			if (in_array($file, ['.', '..'])) {
				continue;
			}
			$file_path = $league_path . DIRECTORY_SEPARATOR . $file;
			$parts = pathinfo($file_path);
			if ($parts['extension'] == 'html') {
				$parser->parseFilepath($file_path);
			}
		}
	}
}

class VegasInsiderParser
{
	/**
	 * @param array $options
	 */
	public function __construct(array $options = [])
	{
		$this->options = array_merge([
			'reparse' => true,
		], $options);
	}

	/**
	 * @param string $file_path
	 * @throws Exception
	 */
	public function parseFilepath($file_path)
	{
		print "Parsing '" . $file_path . "'..\n";
		if (!file_exists($file_path)) {
			throw new Exception("File does not exist.");
		}
		$parts = pathinfo($file_path);
		$json_file_path = $parts['dirname'] . DIRECTORY_SEPARATOR . $parts['filename'] . '.json';
		if (file_exists($json_file_path) && !$this->options['reparse']) {
			print "\tJSON already exists, skipping\n";
		}
		$raw = file_get_contents($file_path);
		$dom = new DOMDocument();
		@$dom->loadHTML($raw);
		$xpath = new DOMXPath($dom);

		$cols = [];

		// Figure out column names from the images in the first table
		$exp_header_cells = "//table[@id='frodds-imgmap-container']//tr[1]/td";
		foreach (XHelper::getNodes($xpath, $exp_header_cells) as $col_num => $node) {
			$exp_header_img_src = $exp_header_cells . "[" . ($col_num + 1) . "]//img/@src";
			$src = XHelper::getNodeValue($xpath, $exp_header_img_src);
			$name = '';
			foreach ([
				'open_white_background' => 'first',
				'betmgm_color' => 'mgm',
				'caesars' => 'caesars',
				'circa' => 'circa',
				'fanduel' => 'fanduel',
				'draftking' => 'draftkings',
				'pointsbet' => 'pointsbet',
				'westgate' => 'westgate',
				'vi_consensus' => 'vi_consensus',
			] as $match => $book) {
				if (stripos($src, $match) !== false) {
					$name = $book;
					break;
				}
			}
			$cols[$col_num] = $name;
		}

		// First column has the game information
		$cols[0] = 'game';

		$vi_consensus_col_index = array_search('vi_consensus', $cols);
		if ($vi_consensus_col_index === false) {
			throw new Exception("Could not determine vi_consensus column.");
		}

		$data = [];

		// Iterate through table data rows
		$exp_data_rows = "//table[@class='frodds-data-tbl']//tr";
		foreach (XHelper::getNodes($xpath, $exp_data_rows) as $row_num => $node) {
			$game_data = [
				'date' => '',
				'time' => '',
				'away_team' => '',
				'home_team' => '',
				Game::BET_TYPE_SPREAD => '',
				Game::BET_TYPE_OVER_UNDER => '',
			];

			$exp_data_row = $exp_data_rows . "[" . ($row_num + 1) . "]";

			$exp_game_cell = $exp_data_row . "/td[1]";

			$exp_datetime = $exp_game_cell . "//*[@class='cellTextHot']";
			$datetime_short = XHelper::getNodeValue($xpath, $exp_datetime);
			// 09/18  10:15 PM
			if (preg_match('@^(\d+/\d+)\s+(\d+:\d+\s+(AM|PM))$@', $datetime_short, $m)) {
				$game_data['date'] = date("Y-m-d", strtotime($m[1]));
				$game_data['time'] = date("H:i:s", strtotime($m[2]));
			}

			$exp_away_team_href = "(" . $exp_game_cell . "//*[contains(@href, '/team/')])[1]/@href";
			$away_team_href = XHelper::getNodeValue($xpath, $exp_away_team_href);
			if (preg_match('@team/(.+?)$@', $away_team_href, $m)) {
				$game_data['away_team'] = $m[1];
			}

			$exp_home_team_href = "(" . $exp_game_cell . "//*[contains(@href, '/team/')])[2]/@href";
			$home_team_href = XHelper::getNodeValue($xpath, $exp_home_team_href);
			if (preg_match('@team/(.+?)$@', $home_team_href, $m)) {
				$game_data['home_team'] = $m[1];
			}

			// Raw odds array should contain two elements, one for each line in the odds.
			// The order of the odds lines matters since the spread is always printed negative.
			// If the spread is shown of the first line, then the negative value is applied to the
			// away team. If it's shown on the second line, then the negative value is applied to
			// the home team.
			$raw_odds = [];
			$exp_odds = $exp_data_row . "/td[" . ($vi_consensus_col_index + 1) . "]//text()";
			foreach (XHelper::getNodes($xpath, $exp_odds) as $node) {
				$str = trim($node->nodeValue);
				$str = preg_replace('/[^A-Za-z0-9-.]/', '', $str);
				$str = trim(preg_replace('/\s+/', ' ', $str));
				if (!strlen($str)) {
					continue;
				}
				$raw_odds[] = $str;
			}

			// Parse the raw odds lines into usable game data
			$clean_odds = [];
			foreach ($raw_odds as $i => $raw_odd) {
				if (preg_match('/PK/', $raw_odd)) {
					$game_data[Game::BET_TYPE_SPREAD] = 0.5;
				}
				elseif (preg_match('/^(\d+)(o|u)/', $raw_odd, $m)) {
					$game_data[Game::BET_TYPE_OVER_UNDER] = $m[1] + 0.5;
				}
				elseif (preg_match('/^(-\d+)/', $raw_odd, $m)) {
					$val = $m[1];
					if ($i == 1) {
						// if $i is 1 then this is the second odds line, meaning the value
						// should be swapped to positive since we apply the spread value
						// to the away team
						$val *= -1;
					}
					$game_data[Game::BET_TYPE_SPREAD] = $val;
				}
			}

			$data[] = $game_data;
		}

		file_put_contents($json_file_path, json_encode($data, JSON_PRETTY_PRINT));
	}
}
