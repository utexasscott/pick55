<?php

namespace Pick55;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Exception;
use GuzzleHttp\Client;
use Pick55\Models\Game;

/**
 * VegasInsider odds scraper: fetches the NFL / college football "las-vegas" odds pages,
 * stores the raw HTML under scrape/raw/vegas-insider/<league>/, and parses a stored page
 * into the JSON shape admin/weeks/week/bulk-games.php consumes.
 *
 * See docs/odds-scraper.md for the measured page structure this parser targets.
 *
 * Runs under PHP 7.4 (Apache, local) and PHP 8.0 (droplet CLI): keep the syntax valid on both.
 */
class VegasInsider
{
	const SOURCE = 'vegas-insider';
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
	const CONSENSUS_HEADER = 'Consensus';
	const STAMP_FORMAT = 'Y-m-d-H-i-s';

	/**
	 * @return array league => URL
	 */
	public static function getUrls()
	{
		return [
			Game::LEAGUE_NFL => 'https://www.vegasinsider.com/nfl/odds/las-vegas/',
			Game::LEAGUE_NCAA => 'https://www.vegasinsider.com/college-football/odds/las-vegas/',
		];
	}

	/**
	 * Normalizes a league name and throws if it is not one we scrape.
	 *
	 * @param string $league
	 * @return string
	 * @throws Exception
	 */
	public static function league($league)
	{
		$league = trim(strtoupper((string) $league));
		if (!isset(self::getUrls()[$league])) {
			throw new Exception("Invalid league '" . $league . "'. Valid: " . implode(', ', array_keys(self::getUrls())));
		}
		return $league;
	}

	/**
	 * @return string absolute path of scrape/raw/vegas-insider
	 */
	public static function getRawDir()
	{
		return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scrape' . DIRECTORY_SEPARATOR . 'raw' . DIRECTORY_SEPARATOR . self::SOURCE;
	}

	/**
	 * @param string $league
	 * @return string absolute path of the league's raw folder (created if missing)
	 */
	public static function getLeagueDir($league)
	{
		$dir = self::getRawDir() . DIRECTORY_SEPARATOR . strtolower(self::league($league));
		if (!is_dir($dir)) {
			if (!@mkdir($dir, 0775, true)) {
				throw new Exception("Could not create " . $dir . " (running as " . self::whoami() . ").");
			}
			// mkdir's mode is masked by umask; the cron user and the web server both write here.
			@chmod($dir, 0775);
		}
		if (!is_writable($dir)) {
			throw new Exception($dir . " is not writable by " . self::whoami() . "; see docs/odds-scraper.md, 'Permissions on the droplet'.");
		}
		return $dir;
	}

	/**
	 * @return string the current process user, for error messages
	 */
	private static function whoami()
	{
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$pw = posix_getpwuid(posix_geteuid());
			if ($pw && isset($pw['name'])) {
				return $pw['name'];
			}
		}
		$user = getenv('USERNAME');
		return $user ? $user : 'unknown user';
	}

	// -----
	// Fetch
	// -----

	/**
	 * GETs the league's odds page and returns the HTML.
	 *
	 * @param string $league
	 * @return string
	 * @throws Exception
	 */
	public static function fetch($league)
	{
		$league = self::league($league);
		$url = self::getUrls()[$league];
		$client = new Client([
			'timeout' => 60,
			'headers' => [
				'User-Agent' => self::USER_AGENT,
				'Accept' => 'text/html,application/xhtml+xml',
				'Accept-Language' => 'en-US,en;q=0.9',
			],
		]);
		$response = $client->get($url);
		if ($response->getStatusCode() != 200) {
			throw new Exception("HTTP " . $response->getStatusCode() . " from " . $url);
		}
		$html = (string) $response->getBody();
		if (strlen($html) < 10000) {
			throw new Exception("Suspiciously short response (" . strlen($html) . " bytes) from " . $url);
		}
		return $html;
	}

	/**
	 * Saves fetched HTML as scrape/raw/vegas-insider/<league>/<stamp>.html.
	 *
	 * @param string $league
	 * @param string $html
	 * @return string path of the saved file
	 */
	public static function saveRaw($league, $html)
	{
		$path = self::getLeagueDir($league) . DIRECTORY_SEPARATOR . date(self::STAMP_FORMAT) . '.html';
		if (@file_put_contents($path, $html) === false) {
			throw new Exception("Could not write " . $path . " as " . self::whoami() . ".");
		}
		return $path;
	}

	/**
	 * Fetches, saves and parses one league. Returns the parsed games and the file paths.
	 *
	 * @param string $league
	 * @return array ['html' => path, 'json' => path, 'games' => array]
	 * @throws Exception
	 */
	public static function scrape($league)
	{
		$html = self::fetch($league);
		$html_path = self::saveRaw($league, $html);
		$games = self::parseFile($html_path);
		return [
			'html' => $html_path,
			'json' => self::jsonPathFor($html_path),
			'games' => $games,
		];
	}

	// -----
	// Files
	// -----

	/**
	 * @param string $html_path
	 * @return string sibling .json path
	 */
	public static function jsonPathFor($html_path)
	{
		$parts = pathinfo($html_path);
		return $parts['dirname'] . DIRECTORY_SEPARATOR . $parts['filename'] . '.json';
	}

	/**
	 * Timestamp encoded in a raw file name (<Y-m-d-H-i-s>.html / .json), or null.
	 *
	 * @param string $file name or path
	 * @return int|null
	 */
	public static function stampOf($file)
	{
		if (preg_match('/(\d{4}-\d{2}-\d{2})-(\d{2})-(\d{2})-(\d{2})\.(html|json)$/', basename($file), $m)) {
			$ts = strtotime($m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4]);
			return $ts ? $ts : null;
		}
		return null;
	}

	/**
	 * Raw HTML files stored for a league, oldest first.
	 *
	 * @param string $league
	 * @return array of absolute paths
	 */
	public static function listRawFiles($league)
	{
		$dir = self::getRawDir() . DIRECTORY_SEPARATOR . strtolower(self::league($league));
		if (!is_dir($dir)) {
			return [];
		}
		$files = [];
		foreach (scandir($dir) as $file) {
			if (self::stampOf($file) && substr($file, -5) == '.html') {
				$files[] = $dir . DIRECTORY_SEPARATOR . $file;
			}
		}
		sort($files);
		return $files;
	}

	/**
	 * The newest parsed scrape for a league, or null if none.
	 *
	 * @param string $league
	 * @return array|null ['league' => 'NFL', 'ts' => int, 'json' => path, 'games' => array]
	 */
	public static function newest($league)
	{
		$league = self::league($league);
		$dir = self::getRawDir() . DIRECTORY_SEPARATOR . strtolower($league);
		if (!is_dir($dir)) {
			return null;
		}
		$newest = null;
		foreach (scandir($dir) as $file) {
			if (substr($file, -5) != '.json') {
				continue;
			}
			$ts = self::stampOf($file);
			if ($ts && (!$newest || $ts > $newest['ts'])) {
				$newest = [
					'league' => $league,
					'ts' => $ts,
					'json' => $dir . DIRECTORY_SEPARATOR . $file,
				];
			}
		}
		if (!$newest) {
			return null;
		}
		$games = json_decode(file_get_contents($newest['json']), true);
		$newest['games'] = is_array($games) ? $games : [];
		return $newest;
	}

	/**
	 * Parses a stored HTML file and writes the sibling .json. Returns the games.
	 *
	 * @param string $html_path
	 * @return array
	 * @throws Exception
	 */
	public static function parseFile($html_path)
	{
		if (!file_exists($html_path)) {
			throw new Exception("File does not exist: " . $html_path);
		}
		$games = self::parse(file_get_contents($html_path));
		$json_path = self::jsonPathFor($html_path);
		if (@file_put_contents($json_path, json_encode($games, JSON_PRETTY_PRINT)) === false) {
			throw new Exception("Could not write " . $json_path . " as " . self::whoami() . ".");
		}
		return $games;
	}

	// -----
	// Parse
	// -----

	/**
	 * Parses an odds page into one entry per game:
	 *   date, time         kickoff in America/Chicago (the app's timezone)
	 *   kickoff_utc        the page's UTC value, kept for reference
	 *   away_team, home_team   VegasInsider URL slugs (match football_teams.vegas_insider_url)
	 *   away_name, home_name   team names as printed on the page
	 *   away_abbr, home_abbr   the page's data-abbr
	 *   spread             Consensus line applied to the away team (negative = away favored), or null
	 *   over-under         Consensus total, or null
	 * Every line ends in .5 (HALF_POINT_LINES): whole numbers get +0.5 so no pick can push.
	 *
	 * @param string $html
	 * @return array
	 * @throws Exception
	 */
	public static function parse($html)
	{
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$dom->loadHTML($html);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new DOMXPath($dom);

		$table = XHelper::getNode($xpath, "//table[contains(concat(' ', normalize-space(@class), ' '), ' odds-table ')]");
		if (!$table) {
			throw new Exception("No table.odds-table found in the page.");
		}

		// Sportsbook column order comes from the header; never hardcode it.
		$consensus_col = null;
		$header_cells = XHelper::getNodes($xpath, ".//thead//tr[1]/th | .//thead//tr[1]/td", $table);
		foreach ($header_cells as $i => $cell) {
			$label = trim(preg_replace('/\s+/', ' ', $cell->textContent));
			if (strcasecmp($label, self::CONSENSUS_HEADER) == 0) {
				$consensus_col = $i;
				break;
			}
		}
		if ($consensus_col === null) {
			throw new Exception("Could not find the '" . self::CONSENSUS_HEADER . "' column in the table header.");
		}
		// Header cells before the first sportsbook (th.game-legend, the "Time" column) are not
		// odds cells; count them so the index lines up with td.game-odds positions in team rows.
		$leading = 0;
		foreach ($header_cells as $i => $cell) {
			if ($i >= $consensus_col) {
				break;
			}
			if (!XHelper::nodeHasClass($cell, 'book-pinup')) {
				$leading++;
			}
		}
		$consensus_odds_index = $consensus_col - $leading;

		$games = [];
		foreach (['spread' => Game::BET_TYPE_SPREAD, 'total' => Game::BET_TYPE_OVER_UNDER] as $tbody_key => $bet_type) {
			$tbody = XHelper::getNode($xpath, ".//tbody[contains(@class, 'odds-table-" . $tbody_key . "')]", $table);
			if (!$tbody) {
				throw new Exception("No tbody.odds-table-" . $tbody_key . " found in the page.");
			}
			foreach (self::groupGameRows($xpath, $tbody) as $rows) {
				$game = self::parseGameRows($xpath, $rows, $consensus_odds_index, $bet_type);
				if (!$game) {
					continue;
				}
				$key = $game['away_team'] . '@' . $game['home_team'] . '@' . $game['kickoff_utc'];
				if (!isset($games[$key])) {
					$games[$key] = [
						'date' => $game['date'],
						'time' => $game['time'],
						'kickoff_utc' => $game['kickoff_utc'],
						'away_team' => $game['away_team'],
						'home_team' => $game['home_team'],
						'away_name' => $game['away_name'],
						'home_name' => $game['home_name'],
						'away_abbr' => $game['away_abbr'],
						'home_abbr' => $game['home_abbr'],
						Game::BET_TYPE_SPREAD => null,
						Game::BET_TYPE_OVER_UNDER => null,
					];
				}
				$games[$key][$bet_type] = $game['value'];
			}
		}

		$games = array_values($games);
		usort($games, function ($a, $b) {
			return strcmp($a['kickoff_utc'] . $a['away_team'], $b['kickoff_utc'] . $b['away_team']);
		});
		return $games;
	}

	/**
	 * Splits a tbody's rows into runs of [header row, away row, home row] per game.
	 *
	 * @param DOMXPath $xpath
	 * @param DOMNode $tbody
	 * @return array of arrays of DOMNode
	 */
	private static function groupGameRows(DOMXPath $xpath, DOMNode $tbody)
	{
		$groups = [];
		$current = null;
		foreach (XHelper::getNodes($xpath, "./tr", $tbody) as $tr) {
			if (XHelper::getNode($xpath, ".//td[contains(@class, 'game-time')]", $tr)) {
				if ($current) {
					$groups[] = $current;
				}
				$current = [$tr];
			}
			elseif ($current !== null) {
				$current[] = $tr;
			}
		}
		if ($current) {
			$groups[] = $current;
		}
		return $groups;
	}

	/**
	 * @param DOMXPath $xpath
	 * @param array $rows [header tr, away tr, home tr, ...]
	 * @param int $consensus_odds_index index among td.game-odds cells
	 * @param string $bet_type Game::BET_TYPE_*
	 * @return array|null
	 */
	private static function parseGameRows(DOMXPath $xpath, array $rows, $consensus_odds_index, $bet_type)
	{
		$header = array_shift($rows);
		$team_rows = [];
		foreach ($rows as $tr) {
			if (XHelper::getNode($xpath, ".//td[contains(@class, 'game-team')]", $tr)) {
				$team_rows[] = $tr;
			}
		}
		if (sizeof($team_rows) < 2) {
			return null;
		}
		$away_tr = $team_rows[0];
		$home_tr = $team_rows[1];

		$utc = XHelper::getNode($xpath, ".//*[@data-role='localtime']/@data-value", $header);
		$utc = $utc ? trim($utc->nodeValue) : '';
		if (!$utc) {
			return null;
		}
		try {
			$kickoff = new DateTime($utc, new DateTimeZone('UTC'));
		}
		catch (Exception $e) {
			return null;
		}
		$kickoff->setTimezone(new DateTimeZone('America/Chicago'));

		$away = self::parseTeamCell($xpath, $away_tr);
		$home = self::parseTeamCell($xpath, $home_tr);
		if (!$away['slug'] || !$home['slug']) {
			return null;
		}

		$value = null;
		if ($bet_type == Game::BET_TYPE_SPREAD) {
			// Spreads are stored against the away team (negative = away favored); the away row's
			// Consensus cell already reads that way. Fall back to negating the home row.
			$away_raw = self::consensusValue($xpath, $away_tr, $consensus_odds_index);
			$home_raw = self::consensusValue($xpath, $home_tr, $consensus_odds_index);
			$value = self::cleanSpread($away_raw);
			if ($value === null) {
				$home_value = self::cleanSpread($home_raw);
				if ($home_value !== null) {
					$value = -1 * $home_value;
				}
			}
		}
		else {
			$raw = self::consensusValue($xpath, $away_tr, $consensus_odds_index);
			$value = self::cleanTotal($raw);
			if ($value === null) {
				$value = self::cleanTotal(self::consensusValue($xpath, $home_tr, $consensus_odds_index));
			}
		}
		if ($value === null) {
			return null;
		}

		return [
			'date' => $kickoff->format('Y-m-d'),
			'time' => $kickoff->format('H:i:s'),
			'kickoff_utc' => $utc,
			'away_team' => $away['slug'],
			'home_team' => $home['slug'],
			'away_name' => $away['name'],
			'home_name' => $home['name'],
			'away_abbr' => $away['abbr'],
			'home_abbr' => $home['abbr'],
			'value' => $value,
		];
	}

	/**
	 * @param DOMXPath $xpath
	 * @param DOMNode $tr
	 * @return array ['slug' => string, 'name' => string, 'abbr' => string]
	 */
	private static function parseTeamCell(DOMXPath $xpath, DOMNode $tr)
	{
		$out = ['slug' => '', 'name' => '', 'abbr' => ''];
		$a = XHelper::getNode($xpath, ".//td[contains(@class, 'game-team')]//a[@href]", $tr);
		if (!$a) {
			return $out;
		}
		$href = $a->getAttribute('href');
		if (preg_match('@/teams/([^/?#]+)/?@', $href, $m)) {
			$out['slug'] = strtolower($m[1]);
		}
		$out['abbr'] = trim($a->getAttribute('data-abbr'));
		// The logo's alt text carries the full name ("Army Black Knights"); the link text is short ("Army").
		$img = XHelper::getNode($xpath, ".//td[contains(@class, 'game-team')]//img[@alt]", $tr);
		$out['name'] = $img ? trim(preg_replace('/\s+/', ' ', $img->getAttribute('alt'))) : '';
		if ($out['name'] === '') {
			$out['name'] = trim(preg_replace('/\s+/', ' ', $a->textContent));
		}
		return $out;
	}

	/**
	 * Text of the Consensus cell's span.data-value in a team row, or ''.
	 *
	 * @param DOMXPath $xpath
	 * @param DOMNode $tr
	 * @param int $index
	 * @return string
	 */
	private static function consensusValue(DOMXPath $xpath, DOMNode $tr, $index)
	{
		$cells = XHelper::getNodes($xpath, "./td[contains(@class, 'game-odds')]", $tr);
		if (!isset($cells[$index])) {
			return '';
		}
		$span = XHelper::getNode($xpath, ".//*[contains(@class, 'data-value')]", $cells[$index]);
		$text = $span ? $span->textContent : $cells[$index]->textContent;
		return trim(preg_replace('/\s+/', ' ', $text));
	}

	/**
	 * '+3.5' / '-7' / '--4.5' / 'PK' / 'EVEN' -> float ending in .5, or null when there is no line.
	 *
	 * @param string $raw
	 * @return float|null
	 */
	public static function cleanSpread($raw)
	{
		$raw = trim((string) $raw);
		if ($raw === '' || $raw === '-' || $raw === 'N/A') {
			return null;
		}
		if (preg_match('/^(PK|PICK|EV|EVEN)$/i', $raw)) {
			return 0.5;
		}
		// Reduce oddities like '--4.5' or '+ 3' to a single sign and a number.
		$raw = str_replace(' ', '', $raw);
		if (!preg_match('/^([+-]*)(\d+(?:\.\d+)?)/', $raw, $m)) {
			return null;
		}
		$sign = (strpos($m[1], '-') !== false) ? -1 : 1;
		$abs = self::halfPoint((float) $m[2]);
		if ($abs == 0) {
			return 0.5;
		}
		return $sign * $abs;
	}

	/**
	 * 'o47.5' / 'u47' / '47' -> float ending in .5, or null when there is no line.
	 *
	 * @param string $raw
	 * @return float|null
	 */
	public static function cleanTotal($raw)
	{
		$raw = trim((string) $raw);
		if (!preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
			return null;
		}
		$value = self::halfPoint((float) $m[1]);
		return $value > 0 ? $value : null;
	}

	/**
	 * HALF_POINT_LINES: whole-number lines get +0.5 so a pick can never push.
	 *
	 * @param float $abs non-negative
	 * @return float
	 */
	public static function halfPoint($abs)
	{
		$abs = abs((float) $abs);
		$floor = floor($abs);
		if ($abs - $floor == 0.5) {
			return $abs;
		}
		return $floor + 0.5;
	}

	// ---
	// Log
	// ---

	/**
	 * One timestamped line on stdout (cron redirects it to the log file).
	 *
	 * @param string $msg
	 */
	public static function log($msg)
	{
		print date('Y-m-d H:i:s') . ' ' . $msg . "\n";
	}
}
