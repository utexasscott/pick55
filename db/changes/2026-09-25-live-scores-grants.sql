-- PRODUCTION ONLY. Grants Claude's droplet MySQL account on the two tables
-- created by 2026-09-25-live-scores.sql. scripts/provision-claude-droplet.sh
-- grants per table (PII_TABLE_EXCLUDED), so tables created later need this.
-- Not applied locally: no `claude` MySQL user exists there.
--
-- Rollback:
--   REVOKE SELECT, INSERT, UPDATE, DELETE ON `pick`.`football_game_scores` FROM 'claude'@'localhost', 'claude'@'127.0.0.1';
--   REVOKE SELECT, INSERT, UPDATE, DELETE ON `pick`.`football_espn_teams` FROM 'claude'@'localhost', 'claude'@'127.0.0.1';

GRANT SELECT, INSERT, UPDATE, DELETE ON `pick`.`football_game_scores` TO 'claude'@'localhost', 'claude'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `pick`.`football_espn_teams` TO 'claude'@'localhost', 'claude'@'127.0.0.1';
FLUSH PRIVILEGES;
