-- One remember-me token per signed-in device (docs/login-sessions.md).
-- Replaces the single er_users.remember_token, which every cookie login
-- rotated, so a second device signed the first out within hours.
-- Applied locally 2026-09-26. Idempotent: CREATE TABLE IF NOT EXISTS.
--
-- Not granted to Claude's droplet MySQL account on purpose: the rows are
-- login credentials.
--
-- Rollback:
--   DROP TABLE IF EXISTS `er_users_remember_tokens`;

CREATE TABLE IF NOT EXISTS `er_users_remember_tokens` (
	`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`er_user_id` int(10) unsigned NOT NULL,
	`token` char(40) COLLATE utf8_unicode_ci NOT NULL,
	`created_at` datetime NOT NULL,
	`last_used_at` datetime NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `token` (`token`),
	KEY `er_user_id` (`er_user_id`),
	KEY `last_used_at` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
