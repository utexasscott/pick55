-- Live scores from ESPN for the week results page (docs/live-scores.md).
-- Applied locally 2026-09-25. Idempotent: both statements are CREATE TABLE IF NOT EXISTS.
--
-- Rollback:
--   DROP TABLE IF EXISTS `football_game_scores`;
--   DROP TABLE IF EXISTS `football_espn_teams`;

-- One row per football_games row that scrape/live-scores.php has looked at.
-- A real-world game backing two rows (a spread row and an over-under row)
-- gets two score rows carrying the same espn_event_id.
CREATE TABLE IF NOT EXISTS `football_game_scores` (
	`football_game_id` int(10) unsigned NOT NULL,
	`espn_event_id` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
	`away_score` smallint(5) unsigned DEFAULT NULL,
	`home_score` smallint(5) unsigned DEFAULT NULL,
	`state` enum('pre','in','post') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pre',
	`completed` tinyint(1) NOT NULL DEFAULT '0',
	`period` tinyint(3) unsigned DEFAULT NULL,
	`clock` varchar(10) COLLATE utf8_unicode_ci DEFAULT NULL,
	`detail` varchar(60) COLLATE utf8_unicode_ci DEFAULT NULL,
	`fetched_at` datetime NOT NULL,
	`changed_at` datetime DEFAULT NULL,
	`result_set_at` datetime DEFAULT NULL,
	PRIMARY KEY (`football_game_id`),
	KEY `state` (`state`,`completed`),
	KEY `espn_event_id` (`espn_event_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ESPN team ids learned from the first successful name match, so a team is
-- matched by name only once. football_teams itself is not altered.
CREATE TABLE IF NOT EXISTS `football_espn_teams` (
	`football_team_id` int(10) unsigned NOT NULL,
	`espn_team_id` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
	`espn_display_name` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
	`matched_at` datetime DEFAULT NULL,
	`matched_by` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
	PRIMARY KEY (`football_team_id`),
	KEY `espn_team_id` (`espn_team_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
