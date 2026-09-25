-- Week formats become the single source of a week's rules.
-- Step 1 of 3: schema. (2: ...-week-formats-seed.sql, 3: ...-week-formats-drop-legacy.sql)
--
-- football_week_formats gains the two per-week rule columns that had no home
-- there (playoff flag and how many players advance from the knock-out round).
-- football_week_format_payouts gains pool_num so a payout row can target one
-- specific pool (the Finals week pays its "Finalists" and "Consolation" pools
-- differently); NULL keeps the old meaning "every pool".

ALTER TABLE `football_week_formats`
	ADD COLUMN `is_playoffs` tinyint(1) NOT NULL DEFAULT '0' AFTER `num_pools`,
	ADD COLUMN `advance` int(10) unsigned DEFAULT NULL AFTER `is_playoffs`;

ALTER TABLE `football_week_format_payouts`
	ADD COLUMN `pool_num` int(10) unsigned DEFAULT NULL AFTER `place_type`;
