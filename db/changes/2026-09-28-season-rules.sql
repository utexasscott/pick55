-- The rules page's per-season facts (docs/season-rules.md). Everything else
-- the page shows is derived: the finals pot is fee - weekly_pot * num_weeks,
-- games per week and the week 1 kickoff come from football_games, pools and
-- the number advancing come from the week formats, the guaranteed-games grid
-- from football_guaranteed_points.
--
-- weekly_pot   what each player's fee puts into each regular week's pot
-- is_cfp       the season's games are College Football Playoff lines only
-- pay_to_name  who receives entry fees ("Brad"); NULL hides "How do I join?"
-- pay_to_venmo their Venmo handle without the @
--
-- Every 120-dollar season gets the default $10 a week ($20 to the finals).
-- The 2024 College Football Playoffs (id 16: $20 fee, 4 weeks, no playoff
-- weeks) paid $5 a week and had no finals pot. Only the active 2026 Season
-- (id 18) gets a payee, the one rules.php hardcoded.
--
-- Applied locally 2026-09-28. Not idempotent: the ALTER fails if run twice.
--
-- Rollback:
--   ALTER TABLE `football_seasons` DROP COLUMN `weekly_pot`, DROP COLUMN `is_cfp`,
--     DROP COLUMN `pay_to_name`, DROP COLUMN `pay_to_venmo`;

ALTER TABLE `football_seasons`
	ADD COLUMN `weekly_pot` decimal(7,2) unsigned NOT NULL DEFAULT '10.00' AFTER `fee`,
	ADD COLUMN `is_cfp` tinyint(1) NOT NULL DEFAULT '0' AFTER `weekly_pot`,
	ADD COLUMN `pay_to_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `is_cfp`,
	ADD COLUMN `pay_to_venmo` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `pay_to_name`;

UPDATE `football_seasons` SET `weekly_pot` = 5.00, `is_cfp` = 1 WHERE `id` = 16;

UPDATE `football_seasons` SET `pay_to_name` = 'Brad', `pay_to_venmo` = 'Brad-North-2' WHERE `id` = 18;
