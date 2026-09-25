<?php

namespace Pick55\Models;

/**
 * A football_teams row's ESPN team id, learned by Pick55\Espn the first time
 * the team is matched by name (or seeded by hand with matched_by 'manual').
 * See docs/live-scores.md.
 */
class EspnTeam extends BaseModel
{
	const MATCHED_BY_NAME = 'name';
	const MATCHED_BY_SLUG = 'slug';
	const MATCHED_BY_MANUAL = 'manual';

	protected $table = 'football_espn_teams';
	protected $primaryKey = 'football_team_id';
	public $incrementing = false;
	protected $keyType = 'int';
	public $timestamps = false;
	protected $guarded = [];

	public function team()
	{
		return $this->belongsTo(Team::class, 'football_team_id');
	}

	/**
	 * @param array $team_ids
	 * @return array football_team_id => espn_team_id (string)
	 */
	public static function idsForTeams(array $team_ids)
	{
		$out = [];
		if (!sizeof($team_ids)) {
			return $out;
		}
		foreach (self::whereIn('football_team_id', $team_ids)->get() as $row) {
			$out[(int) $row->football_team_id] = (string) $row->espn_team_id;
		}
		return $out;
	}
}
