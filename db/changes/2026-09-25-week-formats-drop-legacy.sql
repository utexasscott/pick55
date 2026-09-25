-- Week formats, step 3 of 3: drop the per-week rule columns that
-- football_week_formats now holds. Apply only after step 2 (the seed) has
-- been verified: SELECT COUNT(*) FROM football_weeks WHERE football_week_format_id IS NULL must be 0.
--
-- What each column became:
--   description, description_long        -> football_week_formats.name / description_long
--   is_teams, num_pools                   -> football_week_formats.is_teams / num_pools
--   players_per_pool                      -> derived: ceil(num_players / num_pools)
--   pool_winner, weekly_bonus, num_winners, min_score_threshold
--                                         -> football_week_format_payouts rows
--   is_playoffs, advance                  -> football_week_formats.is_playoffs / advance

ALTER TABLE `football_weeks`
	DROP COLUMN `description`,
	DROP COLUMN `description_long`,
	DROP COLUMN `is_teams`,
	DROP COLUMN `num_pools`,
	DROP COLUMN `players_per_pool`,
	DROP COLUMN `pool_winner`,
	DROP COLUMN `weekly_bonus`,
	DROP COLUMN `num_winners`,
	DROP COLUMN `min_score_threshold`,
	DROP COLUMN `is_playoffs`,
	DROP COLUMN `advance`;
