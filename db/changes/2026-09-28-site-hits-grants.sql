-- PRODUCTION ONLY, OPTIONAL. Lets Claude's droplet MySQL account read the
-- traffic table created by 2026-09-28-site-hits.sql (read only: the app
-- writes the rows with its own credential). The rows hold user ids, IP
-- addresses and user agents, no names or emails. Skip this file to keep the
-- table out of Claude's reach; the admin traffic page works either way.
-- Not applied locally: no `claude` MySQL user exists there.
--
-- Rollback:
--   REVOKE SELECT ON `pick`.`er_site_hits` FROM 'claude'@'localhost', 'claude'@'127.0.0.1';

GRANT SELECT ON `pick`.`er_site_hits` TO 'claude'@'localhost', 'claude'@'127.0.0.1';
FLUSH PRIVILEGES;
