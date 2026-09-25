<?php

namespace Pick55\Models;

/**
 * The live (or final) ESPN score of one football_games row, written by
 * scrape/live-scores.php. See docs/live-scores.md.
 *
 * Result rule (the same one the cron applies when a game completes, applied
 * here to whatever score is stored):
 *   spread      football_games.value is against the AWAY team (negative = away
 *               favored). The away side wins when away_score + value > home_score,
 *               the home side when it is less, and nobody on equality.
 *   over-under  OVER wins when away_score + home_score > value, UNDER when less.
 * Which of option_1 / option_2 is the away (or OVER) side comes from the option
 * text, cross-checked against football_games.options_flipped; when the two
 * disagree the game is treated as undecidable (null).
 */
class GameScore extends BaseModel
{
	const STATE_PRE = 'pre';
	const STATE_IN = 'in';
	const STATE_POST = 'post';

	protected $table = 'football_game_scores';
	protected $primaryKey = 'football_game_id';
	public $incrementing = false;
	protected $keyType = 'int';
	public $timestamps = false;
	protected $guarded = [];

	public function game()
	{
		return $this->belongsTo(Game::class, 'football_game_id');
	}

	/**
	 * Every score row of a week, one query.
	 *
	 * @param int $week_id
	 * @return array football_game_id => GameScore
	 */
	public static function forWeek($week_id)
	{
		$out = [];
		$q = self::whereIn('football_game_id', function ($q) use ($week_id) {
			$q->select('id')
				->from('football_games')
				->where('football_week_id', '=', (int) $week_id);
		});
		foreach ($q->get() as $row) {
			$out[(int) $row->football_game_id] = $row;
		}
		return $out;
	}

	/**
	 * @return bool
	 */
	public function isLive()
	{
		return $this->state == self::STATE_IN;
	}

	/**
	 * @return bool
	 */
	public function isFinal()
	{
		return $this->state == self::STATE_POST && (bool) $this->completed;
	}

	/**
	 * @return bool  both scores known and the game has started
	 */
	public function hasScore()
	{
		return $this->state != self::STATE_PRE
			&& $this->away_score !== null
			&& $this->home_score !== null;
	}

	/**
	 * Which option currently wins on the stored score: '1', '2', or null when
	 * the game has not started, a score is missing, the line is exactly met,
	 * or the option text and options_flipped disagree.
	 *
	 * @return string|null
	 */
	public function getLeadingOption()
	{
		if (!$this->hasScore()) {
			return null;
		}
		$game = $this->game;
		if (!$game) {
			return null;
		}
		return self::optionFor($game, (int) $this->away_score, (int) $this->home_score);
	}

	/**
	 * Short status text for the page: '' before kickoff, "Q3 4:12" / "Half" /
	 * "End Q1" / "OT 2:00" / "2OT 0:45" while live, "Final" / "Final/OT" when
	 * done, and ESPN's own short detail for anything else (postponed, delayed).
	 *
	 * @return string
	 */
	public function getStatusLabel()
	{
		$detail = trim((string) $this->detail);
		if ($this->state == self::STATE_PRE) {
			return '';
		}
		if ($this->state == self::STATE_POST) {
			if ($this->completed) {
				return stripos($detail, 'Final') === 0 ? $detail : 'Final';
			}
			return $detail;
		}
		// in progress
		if (stripos($detail, 'Half') !== false) {
			return 'Half';
		}
		if (stripos($detail, 'Delay') !== false || stripos($detail, 'Suspend') !== false) {
			return $detail;
		}
		$period = (int) $this->period;
		$name = self::periodName($period);
		if (stripos($detail, 'End') === 0) {
			return 'End ' . $name;
		}
		$clock = trim((string) $this->clock);
		return trim($name . ' ' . $clock);
	}

	/**
	 * @param int $period
	 * @return string Q1..Q4, OT, 2OT, ...
	 */
	public static function periodName($period)
	{
		$period = (int) $period;
		if ($period <= 0) {
			return '';
		}
		if ($period <= 4) {
			return 'Q' . $period;
		}
		if ($period == 5) {
			return 'OT';
		}
		return ($period - 4) . 'OT';
	}

	/**
	 * The result rule (see the class comment) applied to a score.
	 *
	 * @param Game $game
	 * @param int $away_score
	 * @param int $home_score
	 * @return string|null '1', '2', or null (tie on the line, or undecidable sides)
	 */
	public static function optionFor(Game $game, $away_score, $home_score)
	{
		$sides = self::sidesOf($game);
		if (!$sides) {
			return null;
		}
		$value = (float) $game->value;
		if ($game->bet_type == Game::BET_TYPE_OVER_UNDER) {
			$total = (int) $away_score + (int) $home_score;
			if ($total > $value) {
				return $sides['over'];
			}
			if ($total < $value) {
				return $sides['under'];
			}
			return null;
		}
		$margin = (int) $away_score + $value - (int) $home_score;
		if ($margin > 0) {
			return $sides['away'];
		}
		if ($margin < 0) {
			return $sides['home'];
		}
		return null;
	}

	/**
	 * Which option number is which side, from the option text, cross-checked
	 * against options_flipped. Null when the two disagree (logged by the cron,
	 * never written).
	 *
	 * Spread rows: ['away' => '1'|'2', 'home' => '1'|'2', 'flipped' => bool]
	 * Over-under rows: ['over' => ..., 'under' => ..., 'flipped' => bool]
	 *
	 * @param Game $game
	 * @return array|null
	 */
	public static function sidesOf(Game $game)
	{
		$flipped_column = (bool) $game->options_flipped;
		$flipped_text = self::flippedByText($game);
		if ($flipped_text !== null && $flipped_text !== $flipped_column) {
			return null;
		}
		$flipped = $flipped_column;
		$first = $flipped ? '2' : '1';
		$second = $flipped ? '1' : '2';
		if ($game->bet_type == Game::BET_TYPE_OVER_UNDER) {
			return ['over' => $first, 'under' => $second, 'flipped' => $flipped];
		}
		return ['away' => $first, 'home' => $second, 'flipped' => $flipped];
	}

	/**
	 * What the option text says about the order of the sides: true when
	 * option_1 is the home (or UNDER) side, false when it is the away (or
	 * OVER) side, null when the text names neither.
	 *
	 * @param Game $game
	 * @return bool|null
	 */
	public static function flippedByText(Game $game)
	{
		if ($game->bet_type == Game::BET_TYPE_OVER_UNDER) {
			$one = strtoupper((string) $game->option_1);
			$two = strtoupper((string) $game->option_2);
			$one_over = strpos($one, 'OVER') !== false && strpos($one, 'UNDER') === false;
			$one_under = strpos($one, 'UNDER') !== false;
			$two_over = strpos($two, 'OVER') !== false && strpos($two, 'UNDER') === false;
			$two_under = strpos($two, 'UNDER') !== false;
			if ($one_over && $two_under) {
				return false;
			}
			if ($one_under && $two_over) {
				return true;
			}
			return null;
		}
		$away = $game->awayTeam;
		$home = $game->homeTeam;
		if (!$away || !$home) {
			return null;
		}
		$one = self::optionLabel($game->option_1);
		$two = self::optionLabel($game->option_2);
		$one_away = self::labelNamesTeam($one, $away);
		$one_home = self::labelNamesTeam($one, $home);
		$two_away = self::labelNamesTeam($two, $away);
		$two_home = self::labelNamesTeam($two, $home);
		if ($one_away && !$one_home && $two_home && !$two_away) {
			return false;
		}
		if ($one_home && !$one_away && $two_away && !$two_home) {
			return true;
		}
		return null;
	}

	/**
	 * "Ole Miss (+3.5)" -> "Ole Miss"
	 *
	 * @param string $option
	 * @return string
	 */
	public static function optionLabel($option)
	{
		return trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $option));
	}

	/**
	 * @param string $label
	 * @param Team $team
	 * @return bool  the label is the team's name, nickname, or both
	 */
	private static function labelNamesTeam($label, Team $team)
	{
		$label = self::normalize($label);
		if ($label === '') {
			return false;
		}
		$candidates = [
			self::normalize($team->team),
			self::normalize($team->nickname),
			self::normalize($team->team . ' ' . $team->nickname),
		];
		return in_array($label, array_filter($candidates), true);
	}

	/**
	 * Lowercase, accents folded, everything but a-z0-9 removed.
	 *
	 * @param string $s
	 * @return string
	 */
	public static function normalize($s)
	{
		$s = (string) $s;
		$s = str_replace(
			['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', '&'],
			['a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n', 'and'],
			$s
		);
		return preg_replace('/[^a-z0-9]/', '', strtolower($s));
	}
}
