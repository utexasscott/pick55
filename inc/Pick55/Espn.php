<?php

namespace Pick55;

use DateTime;
use DateTimeZone;
use Exception;
use GuzzleHttp\Client;
use Pick55\Models\EspnTeam;
use Pick55\Models\Game;
use Pick55\Models\GameScore;
use Pick55\Models\Team;

/**
 * ESPN scoreboard client for live scores: fetches a league's scoreboard for a
 * day, parses its events into plain arrays, and matches a football_games row
 * to one of them by stored event id, learned team ids, or team names.
 *
 * One instance per run; fetched scoreboards are cached on the instance so a
 * day is fetched once however many games fall on it.
 *
 * See docs/live-scores.md for the measured JSON shape and the matching rules.
 * Runs under PHP 7.4 (local) and PHP 8.0 (droplet CLI): keep the syntax valid on both.
 */
class Espn
{
	// site.api.espn.com answers 403 (Akamai "Access Denied") from this workstation and from
	// Anthropic's fetcher alike (measured 2026-09-25); site.web.api.espn.com serves the same
	// path. Hosts are tried in order.
	const HOSTS = [
		'https://site.web.api.espn.com',
		'https://site.api.espn.com',
	];
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
	const TIMEOUT_SECONDS = 20;

	// A football_games kickoff and an ESPN event kickoff must be this close to be the same game.
	const MATCH_WINDOW_SECONDS = 4 * 3600;

	// Match levels, best first (see matchTeam()).
	const LEVEL_FULL_NAME = 1;
	const LEVEL_LOCATION = 2;
	const LEVEL_SLUG = 3;
	const LEVEL_NICKNAME = 4;

	/** @var array league => YYYYMMDD => list of parsed events */
	private $boards = [];

	/** @var array list of ['league', 'date', 'url', 'events'] in fetch order */
	private $fetched = [];

	/**
	 * @return array league => scoreboard path
	 */
	public static function getPaths()
	{
		return [
			Game::LEAGUE_NFL => '/apis/site/v2/sports/football/nfl/scoreboard',
			Game::LEAGUE_NCAA => '/apis/site/v2/sports/football/college-football/scoreboard',
		];
	}

	/**
	 * @param string $league
	 * @param string $yyyymmdd
	 * @return string query string (without '?')
	 */
	public static function getQuery($league, $yyyymmdd)
	{
		$params = ['dates' => $yyyymmdd];
		if ($league == Game::LEAGUE_NCAA) {
			// groups=80 is FBS; the default page is a short "top games" list.
			$params['groups'] = '80';
			$params['limit'] = '300';
		}
		return http_build_query($params);
	}

	/**
	 * Teams whose football_teams name differs from what ESPN prints as the
	 * team "location", keyed by vegas_insider_url slug (the stable key).
	 * Only needed where normalize(team + nickname), normalize(team) and the
	 * hyphen-less slug all miss.
	 *
	 * @return array slug => ESPN location
	 */
	public static function getAliases()
	{
		return [
			'appalachian-state' => 'App State',
			'louisiana-monroe' => 'UL Monroe',
			'umass' => 'Massachusetts',
			'uconn' => 'UConn',
			'miami-fl' => 'Miami',
			'san-jose-state' => 'San José State',
			'hawaii' => "Hawai'i",
			'nc-state' => 'NC State',
			'pittsburgh' => 'Pittsburgh',
			'southern-miss' => 'Southern Miss',
			'sam-houston' => 'Sam Houston',
			'texas-am' => 'Texas A&M',
			'ole-miss' => 'Ole Miss',
			'liu' => 'Long Island University',
		];
	}

	// -----
	// Fetch
	// -----

	/**
	 * Events of one league's scoreboard for one day, fetched once per instance.
	 *
	 * @param string $league Game::LEAGUE_*
	 * @param string $yyyymmdd
	 * @return array list of parsed events (see parseEvents)
	 * @throws Exception when every host fails
	 */
	public function events($league, $yyyymmdd)
	{
		$league = self::league($league);
		if (isset($this->boards[$league][$yyyymmdd])) {
			return $this->boards[$league][$yyyymmdd];
		}
		$result = self::fetchScoreboard($league, $yyyymmdd);
		$this->boards[$league][$yyyymmdd] = $result['events'];
		$this->fetched[] = [
			'league' => $league,
			'date' => $yyyymmdd,
			'url' => $result['url'],
			'events' => sizeof($result['events']),
		];
		return $result['events'];
	}

	/**
	 * @return array list of ['league', 'date', 'url', 'events' => count]
	 */
	public function getFetched()
	{
		return $this->fetched;
	}

	/**
	 * Every event fetched so far for a league, keyed by ESPN event id.
	 *
	 * @param string $league
	 * @return array id => event
	 */
	public function eventsById($league)
	{
		$out = [];
		if (empty($this->boards[$league])) {
			return $out;
		}
		foreach ($this->boards[$league] as $events) {
			foreach ($events as $event) {
				$out[$event['id']] = $event;
			}
		}
		return $out;
	}

	/**
	 * GETs one scoreboard and parses it. Static so a caller can bypass the cache.
	 *
	 * @param string $league
	 * @param string $yyyymmdd
	 * @return array ['url' => string, 'events' => array]
	 * @throws Exception
	 */
	public static function fetchScoreboard($league, $yyyymmdd)
	{
		$league = self::league($league);
		if (!preg_match('/^\d{8}$/', $yyyymmdd)) {
			throw new Exception("Bad scoreboard date '" . $yyyymmdd . "' (want YYYYMMDD).");
		}
		$client = new Client([
			'timeout' => self::TIMEOUT_SECONDS,
			'connect_timeout' => 10,
			'http_errors' => false,
			'headers' => [
				'User-Agent' => self::USER_AGENT,
				'Accept' => 'application/json',
				'Accept-Language' => 'en-US,en;q=0.9',
			],
		]);
		$errors = [];
		foreach (self::HOSTS as $host) {
			$url = $host . self::getPaths()[$league] . '?' . self::getQuery($league, $yyyymmdd);
			try {
				$response = $client->get($url);
			}
			catch (Exception $e) {
				$errors[] = $url . ': ' . $e->getMessage();
				continue;
			}
			if ($response->getStatusCode() != 200) {
				$errors[] = $url . ': HTTP ' . $response->getStatusCode();
				continue;
			}
			$json = json_decode((string) $response->getBody(), true);
			if (!is_array($json) || !array_key_exists('events', $json)) {
				$errors[] = $url . ': no "events" in the response';
				continue;
			}
			return [
				'url' => $url,
				'events' => self::parseEvents($json),
			];
		}
		throw new Exception("Scoreboard fetch failed. " . implode(' | ', $errors));
	}

	/**
	 * @param string $league
	 * @return string
	 * @throws Exception
	 */
	public static function league($league)
	{
		$league = trim(strtoupper((string) $league));
		if (!isset(self::getPaths()[$league])) {
			throw new Exception("Invalid league '" . $league . "'. Valid: " . implode(', ', array_keys(self::getPaths())));
		}
		return $league;
	}

	// -----
	// Parse
	// -----

	/**
	 * Reduces a scoreboard's events[] to what the matcher and the score rows need:
	 *   id, name, date_utc, kickoff_ts, kickoff (Central 'Y-m-d H:i:s'),
	 *   state (pre|in|post), completed (bool), period (int), clock (string),
	 *   detail (status.type.shortDetail), status_name (status.type.name),
	 *   away / home => ['id', 'location', 'name', 'display_name', 'abbreviation', 'score' (int|null), 'winner' (bool|null)]
	 *
	 * @param array $json decoded scoreboard
	 * @return array list of events, kickoff order
	 */
	public static function parseEvents(array $json)
	{
		$events = [];
		$central = new DateTimeZone('America/Chicago');
		foreach ((array) $json['events'] as $e) {
			if (empty($e['id']) || empty($e['competitions'][0]['competitors'])) {
				continue;
			}
			$competition = $e['competitions'][0];
			$status = isset($e['status']) ? $e['status'] : (isset($competition['status']) ? $competition['status'] : []);
			$type = isset($status['type']) ? $status['type'] : [];
			$state = isset($type['state']) ? (string) $type['state'] : 'pre';
			if (!in_array($state, ['pre', 'in', 'post'])) {
				$state = 'pre';
			}
			$date_utc = isset($e['date']) ? (string) $e['date'] : (isset($competition['date']) ? (string) $competition['date'] : '');
			$kickoff_ts = $date_utc ? strtotime($date_utc) : false;
			if (!$kickoff_ts) {
				continue;
			}
			$kickoff = new DateTime('@' . $kickoff_ts);
			$kickoff->setTimezone($central);

			$sides = ['away' => null, 'home' => null];
			foreach ($competition['competitors'] as $c) {
				$side = isset($c['homeAway']) ? $c['homeAway'] : '';
				if (!array_key_exists($side, $sides)) {
					continue;
				}
				$team = isset($c['team']) ? $c['team'] : [];
				$score = null;
				if ($state != 'pre' && isset($c['score']) && is_numeric($c['score'])) {
					$score = (int) $c['score'];
				}
				$sides[$side] = [
					'id' => isset($team['id']) ? (string) $team['id'] : '',
					'location' => isset($team['location']) ? (string) $team['location'] : '',
					'name' => isset($team['name']) ? (string) $team['name'] : '',
					'display_name' => isset($team['displayName']) ? (string) $team['displayName'] : '',
					'abbreviation' => isset($team['abbreviation']) ? (string) $team['abbreviation'] : '',
					'score' => $score,
					'winner' => array_key_exists('winner', $c) ? (bool) $c['winner'] : null,
				];
			}
			if (!$sides['away'] || !$sides['home']) {
				continue;
			}
			$events[] = [
				'id' => (string) $e['id'],
				'name' => isset($e['name']) ? (string) $e['name'] : ($sides['away']['display_name'] . ' at ' . $sides['home']['display_name']),
				'date_utc' => $date_utc,
				'kickoff_ts' => $kickoff_ts,
				'kickoff' => $kickoff->format('Y-m-d H:i:s'),
				'state' => $state,
				'completed' => !empty($type['completed']),
				'period' => isset($status['period']) ? (int) $status['period'] : 0,
				'clock' => isset($status['displayClock']) ? (string) $status['displayClock'] : '',
				'detail' => isset($type['shortDetail']) ? (string) $type['shortDetail'] : (isset($type['detail']) ? (string) $type['detail'] : ''),
				'status_name' => isset($type['name']) ? (string) $type['name'] : '',
				'away' => $sides['away'],
				'home' => $sides['home'],
			];
		}
		usort($events, function ($a, $b) {
			if ($a['kickoff_ts'] != $b['kickoff_ts']) {
				return $a['kickoff_ts'] < $b['kickoff_ts'] ? -1 : 1;
			}
			return strcmp($a['id'], $b['id']);
		});
		return $events;
	}

	// -----
	// Dates
	// -----

	/**
	 * @param Game $game
	 * @return int|false the game's Central kickoff as a timestamp
	 */
	public static function kickoffTs(Game $game)
	{
		if (!$game->date) {
			return false;
		}
		return strtotime($game->date . ' ' . ($game->time ? $game->time : '00:00:00'));
	}

	/**
	 * The scoreboard days that can hold a game. ESPN's `dates=` bucket is the
	 * US Eastern day (measured 2026-09-25: the 20260920 NFL board held the
	 * 7:20 PM Central Sunday-night game, 00:20 UTC Monday), so the Central date
	 * is right until 11 PM Central; after that the next day is fetched too.
	 *
	 * @param Game $game
	 * @return array of YYYYMMDD
	 */
	public static function datesFor(Game $game)
	{
		$ts = self::kickoffTs($game);
		if (!$ts) {
			return [];
		}
		$dates = [date('Ymd', $ts)];
		if ((int) date('G', $ts) >= 23) {
			$dates[] = date('Ymd', $ts + 86400);
		}
		return $dates;
	}

	// -----
	// Match
	// -----

	/**
	 * Finds the ESPN event for a football_games row among the boards fetched
	 * so far for its league (call events() for datesFor($game) first).
	 *
	 * Order: (a) $event_id, a previously stored espn_event_id, looked up
	 * directly; (b) both teams' learned ESPN ids; (c) names, at the best level
	 * where exactly one event within MATCH_WINDOW_SECONDS of the kickoff has
	 * both teams matching.
	 *
	 * @param Game $game with awayTeam / homeTeam loaded
	 * @param array $espn_ids football_team_id => espn_team_id (learned)
	 * @param string|null $event_id stored espn_event_id, if any
	 * @return array [
	 *   'event' => array|null,
	 *   'away' => competitor array|null  (the one that is OUR away team, whatever ESPN calls it),
	 *   'home' => competitor array|null,
	 *   'swapped' => bool  ESPN lists our away team as its home team,
	 *   'by' => 'event'|'ids'|'name'|'slug'|'nickname'|'',
	 *   'learned' => [football_team_id => ['espn_team_id', 'espn_display_name', 'matched_by']],
	 *   'reason' => string  why there is no match (for the log),
	 * ]
	 */
	public function match(Game $game, array $espn_ids = [], $event_id = null)
	{
		$none = [
			'event' => null, 'away' => null, 'home' => null, 'swapped' => false,
			'by' => '', 'learned' => [], 'reason' => '',
		];
		$league = (string) $game->type;
		$away = $game->awayTeam;
		$home = $game->homeTeam;
		if (!$away || !$home) {
			$none['reason'] = 'game has no away/home team rows';
			return $none;
		}
		$away_id = isset($espn_ids[(int) $away->id]) ? (string) $espn_ids[(int) $away->id] : '';
		$home_id = isset($espn_ids[(int) $home->id]) ? (string) $espn_ids[(int) $home->id] : '';

		// (a) stored event id
		if ($event_id) {
			$by_id = $this->eventsById($league);
			if (isset($by_id[(string) $event_id])) {
				$event = $by_id[(string) $event_id];
				$orient = self::orient($event, $away, $home, $away_id, $home_id);
				return array_merge($none, [
					'event' => $event,
					'away' => $orient['away'],
					'home' => $orient['home'],
					'swapped' => $orient['swapped'],
					'by' => 'event',
				]);
			}
		}

		$ts = self::kickoffTs($game);
		$window = [];
		foreach ($this->eventsById($league) as $event) {
			if ($ts && abs($event['kickoff_ts'] - $ts) <= self::MATCH_WINDOW_SECONDS) {
				$window[] = $event;
			}
		}
		if (!sizeof($window)) {
			$none['reason'] = 'no ESPN ' . $league . ' event within ' . (self::MATCH_WINDOW_SECONDS / 3600) . 'h of ' . $game->date . ' ' . $game->time
				. ' on the fetched boards (' . sizeof($this->eventsById($league)) . ' events)';
			return $none;
		}

		// (b) learned ids
		if ($away_id !== '' && $home_id !== '') {
			$hits = [];
			foreach ($window as $event) {
				if ($event['away']['id'] === $away_id && $event['home']['id'] === $home_id) {
					$hits[] = ['event' => $event, 'swapped' => false];
				}
				elseif ($event['away']['id'] === $home_id && $event['home']['id'] === $away_id) {
					$hits[] = ['event' => $event, 'swapped' => true];
				}
			}
			if (sizeof($hits) == 1) {
				$hit = $hits[0];
				return array_merge($none, [
					'event' => $hit['event'],
					'away' => $hit['swapped'] ? $hit['event']['home'] : $hit['event']['away'],
					'home' => $hit['swapped'] ? $hit['event']['away'] : $hit['event']['home'],
					'swapped' => $hit['swapped'],
					'by' => 'ids',
				]);
			}
			if (sizeof($hits) > 1) {
				$none['reason'] = sizeof($hits) . ' ESPN events in the window have both learned team ids';
				return $none;
			}
		}

		// (c) names
		$best_level = null;
		$hits = [];
		foreach ($window as $event) {
			foreach ([false, true] as $swapped) {
				$espn_away = $swapped ? $event['home'] : $event['away'];
				$espn_home = $swapped ? $event['away'] : $event['home'];
				$la = self::matchTeam($away, $espn_away);
				$lh = self::matchTeam($home, $espn_home);
				if ($la === null || $lh === null) {
					continue;
				}
				$level = max($la, $lh);
				if ($best_level === null || $level < $best_level) {
					$best_level = $level;
					$hits = [];
				}
				if ($level == $best_level) {
					$hits[] = ['event' => $event, 'swapped' => $swapped, 'la' => $la, 'lh' => $lh];
				}
			}
		}
		if (sizeof($hits) == 1) {
			$hit = $hits[0];
			$espn_away = $hit['swapped'] ? $hit['event']['home'] : $hit['event']['away'];
			$espn_home = $hit['swapped'] ? $hit['event']['away'] : $hit['event']['home'];
			$learned = [];
			if ($away_id === '' && $espn_away['id'] !== '') {
				$learned[(int) $away->id] = [
					'espn_team_id' => $espn_away['id'],
					'espn_display_name' => $espn_away['display_name'],
					'matched_by' => $hit['la'] == self::LEVEL_SLUG ? EspnTeam::MATCHED_BY_SLUG : EspnTeam::MATCHED_BY_NAME,
				];
			}
			if ($home_id === '' && $espn_home['id'] !== '') {
				$learned[(int) $home->id] = [
					'espn_team_id' => $espn_home['id'],
					'espn_display_name' => $espn_home['display_name'],
					'matched_by' => $hit['lh'] == self::LEVEL_SLUG ? EspnTeam::MATCHED_BY_SLUG : EspnTeam::MATCHED_BY_NAME,
				];
			}
			$by = 'name';
			if ($best_level == self::LEVEL_SLUG) {
				$by = 'slug';
			}
			elseif ($best_level == self::LEVEL_NICKNAME) {
				$by = 'nickname';
			}
			return array_merge($none, [
				'event' => $hit['event'],
				'away' => $espn_away,
				'home' => $espn_home,
				'swapped' => $hit['swapped'],
				'by' => $by,
				'learned' => $learned,
			]);
		}
		if (sizeof($hits) > 1) {
			$names = [];
			foreach ($hits as $hit) {
				$names[] = $hit['event']['name'];
			}
			$none['reason'] = sizeof($hits) . ' ESPN events match at level ' . $best_level . ': ' . implode('; ', $names);
			return $none;
		}
		// Nothing: name the closest events so the owner can see why.
		usort($window, function ($a, $b) use ($ts) {
			return abs($a['kickoff_ts'] - $ts) <=> abs($b['kickoff_ts'] - $ts);
		});
		$names = [];
		foreach (array_slice($window, 0, 4) as $event) {
			$names[] = $event['name'] . ' (' . date('H:i', $event['kickoff_ts']) . ')';
		}
		$none['reason'] = 'no ESPN event names both "' . $away->getName() . '" and "' . $home->getName() . '"; closest: ' . implode('; ', $names);
		return $none;
	}

	/**
	 * Which of the event's competitors is our away team, for an event matched
	 * by stored id. Uses learned ids when known, else names, else ESPN's own
	 * homeAway.
	 *
	 * @return array ['away' => competitor, 'home' => competitor, 'swapped' => bool]
	 */
	private static function orient(array $event, Team $away, Team $home, $away_id, $home_id)
	{
		$swapped = false;
		if ($away_id !== '' && $event['home']['id'] === $away_id) {
			$swapped = true;
		}
		elseif ($home_id !== '' && $event['away']['id'] === $home_id) {
			$swapped = true;
		}
		elseif ($away_id === '' && $home_id === '') {
			if (self::matchTeam($away, $event['home']) !== null && self::matchTeam($away, $event['away']) === null) {
				$swapped = true;
			}
		}
		return [
			'away' => $swapped ? $event['home'] : $event['away'],
			'home' => $swapped ? $event['away'] : $event['home'],
			'swapped' => $swapped,
		];
	}

	/**
	 * How well a football_teams row matches an ESPN competitor's team: the
	 * best LEVEL_* that holds, or null.
	 *
	 *   LEVEL_FULL_NAME  normalize(location + name) == normalize(team + nickname),
	 *                    or the alias for the team's slug == location
	 *   LEVEL_LOCATION   normalize(location) == normalize(team)
	 *   LEVEL_SLUG       slug without hyphens == normalize(location)
	 *   LEVEL_NICKNAME   normalize(name) == normalize(nickname)
	 *
	 * @param Team $team
	 * @param array $espn competitor array from parseEvents
	 * @return int|null
	 */
	public static function matchTeam(Team $team, array $espn)
	{
		$location = GameScore::normalize($espn['location']);
		$name = GameScore::normalize($espn['name']);
		$full = GameScore::normalize($espn['location'] . $espn['name']);
		$display = GameScore::normalize($espn['display_name']);
		$our_team = GameScore::normalize($team->team);
		$our_nick = GameScore::normalize($team->nickname);
		$our_full = GameScore::normalize($team->team . $team->nickname);
		$slug = strtolower(trim((string) $team->vegas_insider_url));
		if ($our_full !== '' && ($our_full === $full || $our_full === $display)) {
			return self::LEVEL_FULL_NAME;
		}
		$aliases = self::getAliases();
		if ($slug !== '' && isset($aliases[$slug]) && $location !== '' && GameScore::normalize($aliases[$slug]) === $location) {
			return self::LEVEL_FULL_NAME;
		}
		if ($our_team !== '' && $our_team === $location) {
			return self::LEVEL_LOCATION;
		}
		if ($slug !== '' && $location !== '' && str_replace('-', '', $slug) === $location) {
			return self::LEVEL_SLUG;
		}
		if ($our_nick !== '' && $our_nick === $name) {
			return self::LEVEL_NICKNAME;
		}
		return null;
	}

	// ---
	// Log
	// ---

	/**
	 * One timestamped line on stdout (cron appends it to the log file).
	 *
	 * @param string $msg
	 */
	public static function log($msg)
	{
		print date('Y-m-d H:i:s') . ' ' . $msg . "\n";
	}
}
