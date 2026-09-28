-- One row per page request (docs/site-traffic.md): who, when, which page,
-- which of the two sites served it and which one the visitor has chosen.
-- Written by Pick55\Traffic at the end of every tracked request.
-- Applied locally 2026-09-28. Idempotent: CREATE TABLE IF NOT EXISTS.
--
-- The code tolerates the table being absent (a hit is dropped and logged),
-- so a deploy ahead of this file does not break a page; it only loses hits.
--
-- Rollback:
--   DROP TABLE IF EXISTS `er_site_hits`;

CREATE TABLE IF NOT EXISTS `er_site_hits` (
	`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`created_at` datetime NOT NULL,
	`er_user_id` int(10) unsigned DEFAULT NULL,
	`version` varchar(10) COLLATE utf8_unicode_ci NOT NULL,
	`preference` varchar(10) COLLATE utf8_unicode_ci DEFAULT NULL,
	`method` varchar(8) COLLATE utf8_unicode_ci NOT NULL,
	`path` varchar(191) COLLATE utf8_unicode_ci NOT NULL,
	`query` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
	`status` smallint(5) unsigned NOT NULL DEFAULT '200',
	`is_partial` tinyint(1) NOT NULL DEFAULT '0',
	`duration_ms` int(10) unsigned DEFAULT NULL,
	`ip` varchar(45) COLLATE utf8_unicode_ci DEFAULT NULL,
	`user_agent` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
	`referrer` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
	PRIMARY KEY (`id`),
	KEY `created_at` (`created_at`),
	KEY `er_user_id` (`er_user_id`,`created_at`),
	KEY `path` (`path`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
