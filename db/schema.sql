
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `er_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `er_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `first_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `salt` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `reset_token` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `reset_token_at` datetime DEFAULT NULL,
  `remember_token` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `paypal_email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `venmo_phone` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `remember_token` (`remember_token`),
  KEY `reset_token` (`reset_token`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `er_users_friends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `er_users_friends` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `er_user_id` int(10) unsigned NOT NULL,
  `friend_er_user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `er_user_id` (`er_user_id`,`friend_er_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_bets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_bets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `football_game_id` int(10) unsigned NOT NULL,
  `option` enum('0','1','2','3') COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
  `double` tinyint(1) NOT NULL DEFAULT '0',
  `multiplier` int(10) unsigned NOT NULL DEFAULT '0',
  `cached_option` enum('0','1','2','3') COLLATE utf8_unicode_ci NOT NULL,
  `cached_multiplier` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id-game_id` (`user_id`,`football_game_id`) USING BTREE,
  KEY `user_id` (`user_id`),
  KEY `football_game_id` (`football_game_id`),
  KEY `option` (`option`),
  KEY `multiplier` (`multiplier`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_games`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_games` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `football_week_id` int(10) unsigned NOT NULL,
  `type` enum('NCAA','NFL') COLLATE utf8_unicode_ci DEFAULT NULL,
  `away_team_id` int(10) unsigned DEFAULT NULL,
  `home_team_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `date` date DEFAULT NULL,
  `time` time DEFAULT NULL,
  `bet_type` enum('over-under','spread') COLLATE utf8_unicode_ci DEFAULT NULL,
  `value` decimal(5,1) NOT NULL DEFAULT '0.0',
  `option_1` varchar(40) COLLATE utf8_unicode_ci DEFAULT NULL,
  `option_2` varchar(40) COLLATE utf8_unicode_ci DEFAULT NULL,
  `correct_option` enum('0','1','2') COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
  `options_flipped` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `football_week_id` (`football_week_id`),
  KEY `home_team_id-away_team_id` (`away_team_id`,`home_team_id`) USING BTREE,
  KEY `away_team_id` (`away_team_id`),
  KEY `home_team_id` (`home_team_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_games_all`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_games_all` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('NCAA','NFL') COLLATE utf8_unicode_ci NOT NULL,
  `away_team_id` int(10) unsigned NOT NULL,
  `home_team_id` int(10) unsigned NOT NULL,
  `datetime` datetime NOT NULL,
  `spread` decimal(5,1) DEFAULT NULL,
  `total_points` decimal(5,1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `away_team_id` (`away_team_id`),
  KEY `home_team_id` (`home_team_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_guaranteed_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_guaranteed_points` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `week_id` int(10) unsigned NOT NULL,
  `multipliers_less_than` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `week_id` (`week_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_pool_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_pool_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `er_user_id` int(10) unsigned NOT NULL,
  `week_id` int(10) unsigned NOT NULL,
  `pool_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user-week` (`er_user_id`,`week_id`),
  KEY `user_id` (`er_user_id`,`week_id`,`pool_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_pools`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_pools` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `week_id` int(10) unsigned DEFAULT NULL,
  `pool_num` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `week_id-pool_id` (`week_id`,`pool_num`) USING BTREE,
  KEY `name` (`name`),
  KEY `week_id` (`week_id`),
  KEY `pool_num` (`pool_num`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_results_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_results_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `week_id` int(10) unsigned NOT NULL,
  `er_user_id` int(10) unsigned NOT NULL,
  `points` int(10) unsigned NOT NULL,
  `place_overall` int(10) unsigned DEFAULT NULL,
  `place_pool` int(10) unsigned DEFAULT NULL,
  `place_team` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `week_id_2` (`week_id`,`er_user_id`),
  KEY `user_id` (`er_user_id`),
  KEY `week_id` (`week_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_seasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_seasons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `num_weeks` int(10) unsigned NOT NULL DEFAULT '10',
  `playoff_weeks` int(10) unsigned NOT NULL DEFAULT '2',
  `fee` decimal(7,2) unsigned NOT NULL DEFAULT '120.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_teams` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('NCAA','NFL') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'NCAA',
  `team` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `nickname` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `ranking` int(10) unsigned DEFAULT NULL,
  `color_1` varchar(6) COLLATE utf8_unicode_ci DEFAULT NULL,
  `color_2` varchar(6) COLLATE utf8_unicode_ci DEFAULT NULL,
  `color_3` varchar(6) COLLATE utf8_unicode_ci DEFAULT NULL,
  `vegas_insider_url` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `css_custom_class` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type` (`type`,`vegas_insider_url`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_users_seasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_users_seasons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `football_season_id` int(10) unsigned NOT NULL,
  `er_user_id` int(10) unsigned NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_method` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `validated_info_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `season_id-user_id` (`football_season_id`,`er_user_id`) USING BTREE,
  KEY `football_season_id` (`football_season_id`) USING BTREE,
  KEY `er_user_id` (`er_user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_week_format_payouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_week_format_payouts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `football_week_format_id` int(10) unsigned NOT NULL,
  `place_type` enum('overall','pool','team') COLLATE utf8_unicode_ci NOT NULL,
  `min_place` int(10) unsigned DEFAULT NULL,
  `max_place` int(10) unsigned DEFAULT NULL,
  `min_points` int(10) unsigned DEFAULT NULL,
  `payout` decimal(6,2) DEFAULT NULL,
  `total_payout` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `football_week_format_id` (`football_week_format_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_week_formats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_week_formats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `num_players` int(10) unsigned NOT NULL,
  `total_payout` decimal(6,2) unsigned NOT NULL DEFAULT '0.00',
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `description_long` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `is_teams` tinyint(1) NOT NULL DEFAULT '0',
  `num_pools` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `num_players` (`num_players`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_week_winners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_week_winners` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `week_id` int(10) unsigned NOT NULL,
  `er_user_id` int(10) unsigned NOT NULL,
  `amount` decimal(6,2) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `week_id` (`week_id`),
  KEY `er_user_id` (`er_user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `football_weeks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `football_weeks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `football_season_id` int(10) unsigned NOT NULL,
  `week_num` int(10) unsigned NOT NULL,
  `football_week_format_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `description_long` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `is_teams` tinyint(1) NOT NULL DEFAULT '0',
  `num_pools` int(10) unsigned DEFAULT NULL,
  `players_per_pool` int(10) unsigned DEFAULT NULL,
  `pool_winner` decimal(7,2) unsigned DEFAULT NULL,
  `weekly_bonus` decimal(7,2) unsigned DEFAULT NULL,
  `num_winners` int(10) unsigned NOT NULL DEFAULT '1',
  `min_score_threshold` int(11) NOT NULL DEFAULT '0',
  `is_playoffs` tinyint(1) NOT NULL DEFAULT '0',
  `advance` int(10) unsigned DEFAULT NULL,
  `picks_due_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `season_id-week_num` (`football_season_id`,`week_num`) USING BTREE,
  KEY `football_season_id` (`football_season_id`),
  KEY `week_num` (`week_num`),
  KEY `football_week_format_id` (`football_week_format_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `first_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `salt` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `verify_token` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

