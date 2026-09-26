<?php

namespace Pick55\R;

use Pick55\Auth;
use Pick55\DB;
use Pick55\WeekResults;
use Pick55\Models\GameScore;
use Pick55\Models\Pool;
use Pick55\Models\PoolsUsersLink;
use Pick55\Models\Season;
use Pick55\Models\UserFriendLink;
use Pick55\Models\Week;

/**
 * The moment (docs/redesign.md section 4): what the signed-in player's site
 * should lead with right now. Computed once per request (Context::get()).
 *
 * Cost: the active season, its weeks with their formats, and one grouped
 * query over their games (count, undecided count, first and last kickoff),
 * from which every week's canPick()/canSeeResults() is derived with exactly
 * the model's rules, without the per-week queries the models would run.
 * Then one query for the viewer's picks in the pick week, and, only when a
 * live week exists, WeekResults::get() for it (cached, and with the same
 * arguments the results page uses, so both share one cache entry).
 *
 * Public fields (read them, do not write them):
 *   mode               guest | offseason | pick | live | recap | waiting
 *   user               User|null
 *   season             the active Season|null
 *   is_player          the viewer is linked to the active season
 *   pick_week          Week|null  the week the viewer can pick now (latest, as pick.php chooses)
 *   live_week          Week|null  latest week with results visible and a game undecided
 *   recap_week         Week|null  latest week with every game decided
 *   next_week          Week|null  first week whose picks open in the future
 *   next_opens_at      'Y-m-d H:i:s'|null  when next_week's picks open
 *   my_picks_progress  array|null  see progress()
 *   my_live            array|null  see live()
 */
class Context
{
	const RECAP_DAYS = 10;

	/** @var Context|null */
	private static $instance = null;

	public $mode = 'guest';
	public $user = null;
	public $season = null;
	public $is_player = false;
	public $now;
	public $pick_week = null;
	public $live_week = null;
	public $recap_week = null;
	public $next_week = null;
	public $next_opens_at = null;
	public $my_picks_progress = null;
	public $my_live = null;

	/** @var array week_id => info, see info() */
	private $weeks = [];
	/** @var array|null user_id => User, every player linked to the active season */
	private $players = null;
	/** @var array|null */
	private $friend_ids = null;
	/** @var array week_id => WeekResults::get() overall results */
	private $results = [];

	/**
	 * @return Context
	 */
	public static function get()
	{
		if (self::$instance === null) {
			self::$instance = new self();
			self::$instance->compute();
		}
		return self::$instance;
	}

	/**
	 * The moment as of another time, replacing the memoized one: for CLI
	 * checks of every mode against fixed data. Pages never call it.
	 *
	 * @param int $timestamp
	 * @return Context
	 */
	public static function at($timestamp)
	{
		self::$instance = new self();
		self::$instance->now = (int) $timestamp;
		self::$instance->compute();
		return self::$instance;
	}

	/**
	 * Drops the memoized moment, for a request that changed it (a save).
	 */
	public static function forget()
	{
		self::$instance = null;
	}

	private function __construct()
	{
		$this->now = time();
	}

	private function compute()
	{
		$this->user = Auth::user();
		if (!$this->user) {
			$this->mode = 'guest';
			return;
		}
		$this->season = Season::getActive();
		if (!$this->season) {
			$this->mode = 'offseason';
			return;
		}
		$this->is_player = $this->season->hasPlayer($this->user->id);
		$this->loadWeeks();

		// One pass over the weeks, in week_num order.
		foreach ($this->weeks as $info) {
			$week = $info['week'];
			if ($info['can_pick'] && $this->is_player) {
				// Latest pickable week wins, as Season::getPickWeek() (pick.php) chooses.
				$this->pick_week = $week;
			}
			if ($info['can_see_results'] && $info['undecided'] > 0) {
				$this->live_week = $week;
			}
			if ($info['can_see_results'] && $info['games'] > 0 && $info['undecided'] == 0) {
				$this->recap_week = $week;
			}
			if ($this->next_week === null && $info['opens_at'] && strtotime($info['opens_at']) > $this->now) {
				$this->next_week = $week;
				$this->next_opens_at = $info['opens_at'];
			}
		}

		if (!$this->is_player) {
			$this->mode = 'offseason';
			return;
		}

		if ($this->pick_week) {
			$this->my_picks_progress = $this->progress($this->pick_week);
		}
		if ($this->live_week) {
			$this->my_live = $this->live($this->live_week);
		}

		$recap_recent = false;
		if ($this->recap_week) {
			$last = $this->info($this->recap_week)['last_game_at'];
			$recap_recent = $last && strtotime($last) >= $this->now - self::RECAP_DAYS * 86400;
		}

		if ($this->pick_week && !$this->my_picks_progress['complete']) {
			$this->mode = 'pick';
		}
		elseif ($this->live_week) {
			$this->mode = 'live';
		}
		elseif ($this->pick_week) {
			$this->mode = 'pick';
		}
		elseif ($recap_recent) {
			$this->mode = 'recap';
		}
		elseif ($this->next_week) {
			$this->mode = 'waiting';
		}
		else {
			$this->mode = 'offseason';
		}
	}

	/**
	 * The active season's weeks with their formats and game aggregates.
	 */
	private function loadWeeks()
	{
		$weeks = $this->season->weeks()->with('format')->get();
		if (!sizeof($weeks)) {
			return;
		}
		$ids = [];
		foreach ($weeks as $week) {
			$week->setRelation('season', $this->season);
			$ids[] = (int) $week->id;
		}
		$agg = [];
		$q = DB::table('football_games')
			->whereIn('football_week_id', $ids)
			->groupBy('football_week_id')
			->selectRaw("
				football_week_id AS week_id,
				COUNT(*) AS n,
				SUM(correct_option = '0') AS undecided,
				MIN(CONCAT(`date`, ' ', IFNULL(`time`, '00:00:00'))) AS first_at,
				MAX(CONCAT(`date`, ' ', IFNULL(`time`, '00:00:00'))) AS last_at
			");
		foreach ($q->get() as $row) {
			$agg[(int) $row->week_id] = $row;
		}
		foreach ($weeks as $week) {
			$row = isset($agg[$week->id]) ? $agg[$week->id] : null;
			$this->weeks[(int) $week->id] = $this->buildInfo($week, $row);
		}
	}

	/**
	 * @param Week $week
	 * @param object|null $row  the games aggregate
	 * @return array
	 */
	private function buildInfo(Week $week, $row)
	{
		$games = $row ? (int) $row->n : 0;
		$first = ($row && $row->first_at && strtotime($row->first_at)) ? date('Y-m-d H:i:s', strtotime($row->first_at)) : null;
		$last = ($row && $row->last_at && strtotime($row->last_at)) ? date('Y-m-d H:i:s', strtotime($row->last_at)) : null;
		$opens = $week->getPicksAvailableAt();
		// Week::canPick() and Week::canSeeResults(), from the aggregate.
		$can_pick = $opens && $first
			&& strtotime($opens) <= $this->now
			&& strtotime($first) >= $this->now;
		$can_see = $games > 0 && $first && strtotime($first) <= $this->now;
		return [
			'week' => $week,
			'id' => (int) $week->id,
			'num' => (int) $week->week_num,
			'name' => $week->getName(),
			'is_playoffs' => $week->isPlayoffs(),
			'games' => $games,
			'undecided' => $row ? (int) $row->undecided : 0,
			'first_game_at' => $first,
			'last_game_at' => $last,
			'opens_at' => $opens,
			'can_pick' => (bool) $can_pick,
			'can_see_results' => (bool) $can_see,
		];
	}

	/**
	 * A week's memoized facts: id, num, name, is_playoffs, games, undecided,
	 * first_game_at, last_game_at, opens_at, can_pick, can_see_results.
	 * Weeks outside the active season are computed on demand.
	 *
	 * @param Week $week
	 * @return array
	 */
	public function info(Week $week)
	{
		$id = (int) $week->id;
		if (!isset($this->weeks[$id])) {
			$row = DB::table('football_games')
				->where('football_week_id', '=', $id)
				->selectRaw("
					COUNT(*) AS n,
					SUM(correct_option = '0') AS undecided,
					MIN(CONCAT(`date`, ' ', IFNULL(`time`, '00:00:00'))) AS first_at,
					MAX(CONCAT(`date`, ' ', IFNULL(`time`, '00:00:00'))) AS last_at
				")
				->first();
			$this->weeks[$id] = $this->buildInfo($week, $row && $row->n ? $row : null);
		}
		return $this->weeks[$id];
	}

	/**
	 * Every active-season week's info(), in week_num order.
	 *
	 * @return array week_id => info
	 */
	public function weeks()
	{
		return $this->weeks;
	}

	/**
	 * The viewer's progress on a week's picks.
	 *
	 * @param Week $week
	 * @return array [week_id, games, sides (games with a side or a guaranteed
	 *   pick), values (distinct point values 1-10 placed), values_needed
	 *   (min(10, games)), values_placed (bool), complete (bool), due_at]
	 */
	public function progress(Week $week)
	{
		$info = $this->info($week);
		$sides = 0;
		$values = [];
		$q = DB::table('football_bets AS b')
			->join('football_games AS g', 'g.id', '=', 'b.football_game_id')
			->where('g.football_week_id', '=', $week->id)
			->where('b.user_id', '=', $this->user->id)
			->select(['b.option', 'b.multiplier']);
		foreach ($q->get() as $row) {
			if (in_array((string) $row->option, ['1', '2', '3'], true)) {
				$sides++;
			}
			$mult = (int) $row->multiplier;
			if ($mult >= 1 && $mult <= 10) {
				$values[$mult] = true;
			}
		}
		$games = $info['games'];
		$needed = min(10, $games);
		$sides = min($sides, $games);
		$values_placed = sizeof($values) >= $needed;
		return [
			'week_id' => (int) $week->id,
			'games' => $games,
			'sides' => $sides,
			'values' => sizeof($values),
			'values_needed' => $needed,
			'values_placed' => $values_placed,
			'complete' => $games > 0 && $sides >= $games && $values_placed,
			'due_at' => $info['first_game_at'],
		];
	}

	/**
	 * The viewer's live scoreboard for a week, from WeekResults (cached).
	 *
	 * @param Week $week
	 * @return array|null [week_id, points, rank, players, right, wrong,
	 *   unknown, games, games_left, decided (games with a result), in_play,
	 *   expected, payout, has_payouts,
	 *   leader_points, behind, win_pct]
	 */
	public function live(Week $week)
	{
		if (!$this->user) {
			return null;
		}
		$results = $this->results($week);
		$key = 'u' . $this->user->id;
		if (!isset($results['stats_by_user_id'][$key])) {
			return null;
		}
		$me = $results['stats_by_user_id'][$key];
		$leader = reset($results['stats_by_user_id']);
		$in_play = 0;
		try {
			foreach (GameScore::forWeek($week->id) as $score) {
				if ($score->isLive()) {
					$in_play++;
				}
			}
		}
		catch (\Throwable $e) {
			// football_game_scores missing: no live counts.
		}
		$decided = sizeof($results['games']) - (int) $results['num_unknowns'];
		$behind = $leader ? max(0, (int) $leader['points'] - (int) $me['points']) : 0;
		return [
			'week_id' => (int) $week->id,
			'points' => (int) $me['points'],
			'rank' => (int) $me['rank'],
			// Until a game has a result everyone is tied for 1st: show a dash.
			'rank_label' => $decided > 0 ? Fmt::ordinal($me['rank']) : "\u{2013}",
			'behind_label' => $decided == 0 ? 'no finals yet' : ($behind > 0 ? $behind . ' back' : 'leading'),
			'players' => sizeof($results['stats_by_user_id']),
			'right' => (int) $me['right'],
			'wrong' => (int) $me['wrong'],
			'unknown' => (int) $me['unknown'],
			'games' => sizeof($results['games']),
			'games_left' => (int) $results['num_unknowns'],
			'decided' => sizeof($results['games']) - (int) $results['num_unknowns'],
			'in_play' => $in_play,
			'expected' => (float) $me['expected_payout'],
			'payout' => (float) $me['payout'],
			'has_payouts' => (bool) $results['has_payouts'],
			'leader_points' => $leader ? (int) $leader['points'] : 0,
			'behind' => $leader ? max(0, (int) $leader['points'] - (int) $me['points']) : 0,
			'win_pct' => isset($me['prediction_ranks_pct']['r1']) ? (float) $me['prediction_ranks_pct']['r1'] : 0.0,
		];
	}

	/**
	 * The overall WeekResults for a week of the active season, called with
	 * the same arguments as the results page (every linked player, their
	 * pools), so the cache entry is shared. Memoized per request.
	 *
	 * @param Week $week
	 * @return array  see WeekResults::compute()
	 */
	public function results(Week $week)
	{
		$id = (int) $week->id;
		if (!isset($this->results[$id])) {
			$user_ids = array_keys($this->players());
			$pool_by_user_id = [];
			if (sizeof($user_ids)) {
				$links = PoolsUsersLink::where('week_id', '=', $id)
					->whereIn('er_user_id', $user_ids)
					->get();
				$pool_ids = [];
				foreach ($links as $link) {
					$pool_ids[(int) $link->pool_id] = true;
				}
				$pool_nums = [];
				if (sizeof($pool_ids)) {
					foreach (Pool::whereIn('id', array_keys($pool_ids))->get() as $pool) {
						$pool_nums[(int) $pool->id] = (int) $pool->pool_num;
					}
				}
				foreach ($links as $link) {
					if (isset($pool_nums[(int) $link->pool_id])) {
						$pool_by_user_id[(int) $link->er_user_id] = $pool_nums[(int) $link->pool_id];
					}
				}
			}
			$this->results[$id] = WeekResults::get($week, $user_ids, [], null, ['pool_by_user_id' => $pool_by_user_id]);
		}
		return $this->results[$id];
	}

	/**
	 * Every player linked to the active season (Season::getPlayers()).
	 *
	 * @return array user_id => User
	 */
	public function players()
	{
		if ($this->players === null) {
			$this->players = [];
			if ($this->season) {
				foreach ($this->season->getPlayers() as $user) {
					$this->players[(int) $user->id] = $user;
				}
			}
		}
		return $this->players;
	}

	/**
	 * The viewer's friends (users they have added), for `.row-friend`.
	 *
	 * @return array user_id => true
	 */
	public function friendIds()
	{
		if ($this->friend_ids === null) {
			$this->friend_ids = [];
			if ($this->user) {
				$ids = UserFriendLink::where('er_user_id', '=', $this->user->id)
					->pluck('friend_er_user_id')
					->all();
				foreach ($ids as $id) {
					$this->friend_ids[(int) $id] = true;
				}
			}
		}
		return $this->friend_ids;
	}

	/**
	 * Nav badges: 'picks' => "2d" | "4h 12m" (time left) | "done"; 'results' => "LIVE".
	 *
	 * @return array
	 */
	public function badges()
	{
		$badges = [];
		if ($this->pick_week && $this->my_picks_progress) {
			$p = $this->my_picks_progress;
			if ($p['complete']) {
				$badges['picks'] = 'done';
			}
			elseif ($p['due_at']) {
				$left = strtotime($p['due_at']) - $this->now;
				// Short form for the tab: "2d", "4h", "12m".
				if ($left >= 86400) {
					$badges['picks'] = intdiv($left, 86400) . 'd';
				}
				elseif ($left >= 3600) {
					$badges['picks'] = intdiv($left, 3600) . 'h';
				}
				else {
					$badges['picks'] = max(1, intdiv($left, 60)) . 'm';
				}
			}
		}
		if ($this->live_week && $this->is_player) {
			$badges['results'] = 'LIVE';
		}
		return $badges;
	}

	/**
	 * @param Week|null $week
	 * @return array|null  JSON-safe summary of a week
	 */
	public function weekArray(Week $week = null)
	{
		if (!$week) {
			return null;
		}
		$info = $this->info($week);
		return [
			'id' => $info['id'],
			'num' => $info['num'],
			'name' => $info['name'],
			'games' => $info['games'],
			'undecided' => $info['undecided'],
			'opens_at' => Fmt::iso($info['opens_at']),
			'first_game_at' => Fmt::iso($info['first_game_at']),
			'last_game_at' => Fmt::iso($info['last_game_at']),
			'first_game_label' => $info['first_game_at'] ? Fmt::kickoff($info['first_game_at'], null, $this->now) : '',
		];
	}

	/**
	 * The moment as JSON-safe data (api/today.php).
	 *
	 * @return array
	 */
	public function toArray()
	{
		$progress = $this->my_picks_progress;
		if ($progress) {
			$progress['due_at'] = Fmt::iso($progress['due_at']);
		}
		return [
			'mode' => $this->mode,
			'now' => Fmt::iso($this->now),
			'season' => $this->season ? ['id' => (int) $this->season->id, 'name' => (string) $this->season->name] : null,
			'is_player' => $this->is_player,
			'pick_week' => $this->weekArray($this->pick_week),
			'live_week' => $this->weekArray($this->live_week),
			'recap_week' => $this->weekArray($this->recap_week),
			'next_week' => $this->weekArray($this->next_week),
			'next_opens_at' => Fmt::iso($this->next_opens_at),
			'my_picks_progress' => $progress,
			'my_live' => $this->my_live,
			'badges' => (object) $this->badges(),
		];
	}
}
