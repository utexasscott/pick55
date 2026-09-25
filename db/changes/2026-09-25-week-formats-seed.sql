-- Week formats, step 2 of 3: seed. Generated 2026-09-25 by gen-format-seed.php (scratch)
-- from football_weeks' legacy rule columns and football_week_winners (what was actually paid).
-- Formats 1-6 (2018, owner-made) are kept; 90 formats are added with explicit ids 7-96,
-- then every one of the 197 weeks gets its football_week_format_id (the values outside season 10 were placeholders).

-- Format 5 row 12 is the 2018 '41 points' split pot; record the pot.
UPDATE `football_week_format_payouts` SET `total_payout` = 190 WHERE `id` = 12 AND `football_week_format_id` = 5;

DELETE FROM `football_week_format_payouts` WHERE `football_week_format_id` >= 7;
DELETE FROM `football_week_formats` WHERE `id` >= 7;

INSERT INTO `football_week_formats` (`id`, `num_players`, `total_payout`, `name`, `description_long`, `is_teams`, `num_pools`, `is_playoffs`, `advance`) VALUES
(7, 6, 60, 'Winner Takes All', 'All players fend for themselves. The winner takes $60.', 0, NULL, 0, NULL),
(8, 10, 100, 'Winner Takes All', 'All players fend for themselves. The winner takes $100.', 0, NULL, 0, NULL),
(9, 10, 0, 'Semifinals', 'No money this week. Top 4 players advance to the Finals.', 0, NULL, 1, 4),
(10, 10, 200, 'Finals', '', 0, NULL, 1, NULL),
(11, 11, 66, 'Winner Takes All', 'All players fend for themselves. The winner takes $66.', 0, NULL, 0, NULL),
(12, 11, 0, 'Semifinals', 'No money this week. Top 4 players advance to the Finals.', 0, NULL, 1, 4),
(13, 11, 660, 'Finals', '', 0, NULL, 1, NULL),
(14, 12, 80, 'Winner Takes All', 'All players fend for themselves. The winner takes $80.', 0, NULL, 0, NULL),
(15, 12, 0, 'Semifinals', 'No money this week. Top 4 players advance to the Finals.', 0, NULL, 1, 4),
(16, 12, 640, 'Finals', '', 0, NULL, 1, NULL),
(17, 16, 144, 'King of the Castle', 'All players fend for themselves and the winner takes all ($144).', 0, NULL, 0, NULL),
(18, 16, 144, 'Two Pools', 'All players are divided into two pools of eight. Winner of each pool takes $64. Overall winner for the week gets a bonus $16.', 0, 2, 0, NULL),
(19, 16, 144, 'Foursomes', 'All players are divided into four pools of four. Winner of each pool takes $32. Overall winner for the week gets a bonus $16.', 0, 4, 0, NULL),
(20, 16, 144, 'Head-to-Head', 'All players are placed in head-to-head matchups with one other player. Winner of each pool takes $16. Overall winner for the week gets a bonus $16.', 0, 8, 0, NULL),
(21, 16, 0, 'Semifinals', 'No money this week. Top 6 players advance to the Finals.', 0, NULL, 1, 6),
(22, 16, 480, 'Finals', '', 0, NULL, 1, NULL),
(23, 18, 162, 'King of the Castle', 'All players will fend for themselves and the winner takes all ($162).', 0, NULL, 0, NULL),
(24, 18, 162, 'Two Pools', 'All players are divided into two pools of nine. Winner of each pool takes $72. Overall winner for the week gets a bonus $18.', 0, 2, 0, NULL),
(25, 18, 162, '666', 'All players are divided into three pools of six. Winner of each pool takes $48. Overall winner for the week gets a bonus $18.', 0, 3, 0, NULL),
(26, 18, 162, 'Ménage à Trois', 'All players are divided into pools of three. Winner of each pool takes $18. Overall winner for the week gets a bonus $54.', 0, 6, 0, NULL),
(27, 18, 162, 'King of the Castle 90/45/27', 'All players will fend for themselves. 1st, 2nd, and 3rd get $90, $45, and $27.', 0, NULL, 0, NULL),
(28, 18, 0, 'Semifinals', 'No money this week. Top 8 players advance to the Finals.', 0, NULL, 1, 8),
(29, 18, 480, 'Finals', '', 0, NULL, 1, NULL),
(30, 20, 180, 'King of the Castle 100/60/20', 'All players will fend for themselves. 1st, 2nd, and 3rd get $100, $60, and $20.', 0, NULL, 0, NULL),
(31, 20, 180, 'Two Pools', 'All players are divided into two pools of ten. Winner of each pool takes $80. Overall winner for the week gets a bonus $20.', 0, 2, 0, NULL),
(32, 20, 180, 'Five-somes', 'All players are divided into four pools of five. Winner of each pool takes $30. Overall winner for the week gets $90.', 0, 4, 0, NULL),
(33, 20, 180, 'Head-to-Head', 'Players will be matched up against one other player. Winner gets $10. High points player for the week gets a bonus $80.', 0, 10, 0, NULL),
(34, 20, 0, 'Semifinals', 'No money this week. Top 8 players advance to the Finals.', 0, NULL, 1, 8),
(35, 20, 600, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL),
(36, 21, 180, 'Olympic 90/60/30', 'All players will fend for themselves. 1st, 2nd, and 3rd get $90, $60, and $30.', 0, NULL, 0, NULL),
(37, 21, 180, '3 Pools of 7', 'All players are divided into three pools of seven players. Winner of each pool takes $45. Overall winner for the week gets a bonus $45.', 0, 3, 0, NULL),
(38, 21, 180, 'Top Third', 'Overall winner wins $90. The players who come in 2nd through 7th win $15.', 0, NULL, 0, NULL),
(39, 21, 180, '7 Pools of 3', 'All players are divided into seven pools of three players. Winner of each pool receives $15. Overall winner for the week gets a bonus $75.', 0, 7, 0, NULL),
(40, 21, 180, '7 Teams of 3', 'All players are divided into seven teams of three players. The players on the winning team will receive $40 each. Also, the overall winner for the week will receive $60.', 1, 7, 0, NULL),
(41, 21, 0, 'Semifinals', 'No money this week. Top 8 players advance to the Finals.', 0, NULL, 1, 8),
(42, 21, 720, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL),
(43, 32, 0, 'Semifinals', 'No money this week. Top 12 players advance to the Finals.', 0, NULL, 1, 12),
(44, 32, 610, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL),
(45, 40, 400, 'Top Five 150/100/75/50/25', '1st gets $150. 2nd: $100. 3rd: $75. 4th: $50, 5th: $25', 0, NULL, 0, NULL),
(46, 40, 402, '5 Pools of 8', 'All players are divided into five pools. Overall winner wins $150. Other pool winners win $63.', 0, 5, 0, NULL),
(47, 40, 400, 'Top Eight', '1st gets $150. 2nd: $70. 3rd: $50. 4th: $40, 5th: $30, 6th-8th: $20', 0, NULL, 0, NULL),
(48, 40, 395, '8 Pools of 5', 'All players are divided into eight pools. Overall winner wins $150. Other pool winners win $35.', 0, 8, 0, NULL),
(49, 40, 400, 'Forty-One Points', 'Winner gets $150. All other players who get 41 points or come in the top 4 will split $250.', 0, NULL, 0, NULL),
(50, 40, 0, 'Semifinals', 'No money this week. Top 15 players advance to the Finals.', 0, NULL, 1, 15),
(51, 40, 800, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL),
(52, 44, 440, 'Top Five 150/110/80/60/40', '1st gets $150. 2nd: $110. 3rd: $80. 4th: $60, 5th: $40', 0, NULL, 0, NULL),
(53, 44, 440.01, '4 Pools of 11', 'All players are divided equally into 4 pools. Overall wins $150. Pool winners receive $96.67', 0, 4, 0, NULL),
(54, 44, 440, '11 Pools of 4', 'Overall winner gets $150. Pool winners each get $29.', 0, 11, 0, NULL),
(55, 44, 440, 'Top Seven 150/90/70/50/35/25/20', 'Top Seven: $150, 90, 70, 50, 35, 25, 20', 0, NULL, 0, NULL),
(56, 44, 440, 'Thirty-nine Points', 'Winner gets $150. All other players who get 39 points or come in the top 5 will split $290.', 0, NULL, 0, NULL),
(57, 44, 440, '4 Pools of 11 - $70/$20', '4 pools of 11. Overall wins $150. Pool winners receive $70. Pool runner-up wins $20', 0, 4, 0, NULL),
(58, 44, 0, 'Semifinals', 'No money this week. Top 15 players advance to the Finals.', 0, NULL, 1, 15),
(59, 44, 870, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL),
(60, 42, 420, 'Top Five 150/110/80/50/30', '1st gets $150. 2nd: $110. 3rd: $80. 4th: $50, 5th: $30', 0, NULL, 0, NULL),
(61, 42, 420, '3 Pools of 14', 'Three pools of 14. Overall wins $150. Pool winners receive $90. 2nd place in each pool gets $30.', 0, 3, 0, NULL),
(62, 42, 420, 'Top Seven 150/90/60/45/35/25/15', 'Top Seven: $150, 90, 60, 45, 35, 25, 15', 0, NULL, 0, NULL),
(63, 42, 420, '6 Pools of 7', 'Overall winner gets $150. Other pool winners each get $54.', 0, 6, 0, NULL),
(64, 42, 420, 'Thirty-nine Points', 'Winner gets $150. All other players who get 39 points or come in the top 5 will split $270.', 0, NULL, 0, NULL),
(65, 42, 0, 'Semifinals', 'No money this week. Top 15 players advance to the Finals.', 0, NULL, 1, 15),
(66, 42, 840, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL),
(67, 50, 420, 'Top Five', '1st gets $150. 2nd: $110. 3rd: $80. 4th: $50, 5th: $30', 0, NULL, 0, NULL),
(68, 50, 500, '5 Pools of 10', '5 pools of 10. Overall wins $150. Other pool winners receive $65. 2nd place in each pool gets $18.', 0, 5, 0, NULL),
(69, 50, 500, 'Top Eight', 'Top Eight: $150, 100, 70, 50, 40, 35, 30, 25', 0, NULL, 0, NULL),
(70, 50, 510, '10 Pools of 5', '10 pools of 5. Overall wins $150. Other pool winners receive $40.', 0, 10, 0, NULL),
(71, 50, 0, 'Semifinals', 'No money this week. Top 15 players advance to the Finals.', 0, NULL, 1, 15),
(72, 50, 1050, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL),
(73, 54, 530, 'Top Five', '1st gets $150. 2nd: $125. 3rd: $105. 4th: $85, 5th: $65', 0, NULL, 0, NULL),
(74, 54, 540, 'Top 8', '$150, 100, 75, 60, 50, 40, 35, 30', 0, NULL, 0, NULL),
(75, 54, 540, '3 Pools of 18', '3 pools. Overall winner: $150. Other pool winners $90. Pool 2nd place: $50, 3rd: $20', 0, 3, 0, NULL),
(76, 54, 540, '6 Pools of 9', '6 pools of 9. Overall winner: $150. Other pool winners get $54. Pool runners-up: $20', 0, 6, 0, NULL),
(77, 54, 540, '9 Pools of 6', '9 pools of 6. Overall winner: $150. Other pool winners get $48.75.', 0, 9, 0, NULL),
(78, 54, 540, '2 Pools of 27', '2 pools. Overall winner: $150. Other pool winner $110. Pool 2nd place: $70, 3rd: $45, 4th: $25', 0, 2, 0, NULL),
(79, 54, 0, 'Semifinals', 'No money this week. Top 16 players advance to the Finals.', 0, NULL, 1, 16),
(80, 54, 1080, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL),
(81, 38, 190, 'Top 6: $75, $40, $30, $20, $15, $10', 'Top 6: $75, $40, $30, $20, $15, $10', 0, NULL, 0, NULL),
(82, 60, 610, 'Top Six', '1st gets $160. 2nd: $125. 3rd: $105. 4th: $85, 5th: $75, 6th: $60', 0, NULL, 0, NULL),
(83, 60, 590, 'Top 8', '$160, 110, 90, 65, 55, 45, 35, 30', 0, NULL, 0, NULL),
(84, 60, 601, '3 Pools of 20', '3 pools. Overall winner: $160. Other pool winners $90. Pool 2nd place: $55, 3rd: $32', 0, 3, 0, NULL),
(85, 60, 600, '6 Pools of 10', '6 pools of 10. Overall winner: $160. Other pool winners get $64. Pool runners-up: $20', 0, 6, 0, NULL),
(86, 60, 601, '10 Pools of 6', '10 Pools of 6. Overall winner: $160. Other pool winners get $49', 0, 10, 0, NULL),
(87, 60, 600, 'Top 10', '$160, 105, 75, 60, 50, 40, 35, 30, 25, 20', 0, NULL, 0, NULL),
(88, 60, 600, '2 Pools of 30', '2 pools. Overall winner: $160. Other pool winner $100. Pool 2nd place: $70, 3rd: $40, 4th: $25, 5th: $20, 6th: $15', 0, 2, 0, NULL),
(89, 60, 597, 'Top 15', '$160, $100, $79, 4th-6th: $42, 7th-9th: $20, 10th-15th: $12', 0, NULL, 0, NULL),
(90, 60, 0, 'Semifinals', 'No money this week. Top 20 players advance to the Finals.', 0, NULL, 1, 20),
(91, 60, 1200, 'Finals', 'Pool 2 is the Finals pool, pool 1 the Consolation pool.', 0, 2, 1, NULL),
(92, 50, 490, 'Top Six', '1st gets $140. 2nd: $105. 3rd: $80. 4th: $65, 5th: $55, 6th: $45', 0, NULL, 0, NULL),
(93, 50, 500, 'Top 8', '$140, 90, 70, 60, 50, 35, 30, 25', 0, NULL, 0, NULL),
(94, 50, 500, '5 Pools of 10', '5 pools. Overall winner: $140. Other pool winners $40. Pool 2nd place: $25, 3rd: $15', 0, 5, 0, NULL),
(95, 50, 0, 'Semifinals', 'No money this week. Top 20 players advance to the Finals.', 0, NULL, 1, 20),
(96, 50, 1090, 'Finals', 'Pool 1 is the Finalists, pool 2 the Consolation pool.', 0, 2, 1, NULL);

INSERT INTO `football_week_format_payouts` (`football_week_format_id`, `place_type`, `pool_num`, `min_place`, `max_place`, `min_points`, `payout`, `total_payout`) VALUES
(7, 'overall', NULL, 1, 1, NULL, 60, NULL),
(8, 'overall', NULL, 1, 1, NULL, 100, NULL),
(10, 'overall', NULL, 1, 1, NULL, 200, NULL),
(11, 'overall', NULL, 1, 1, NULL, 66, NULL),
(13, 'overall', NULL, 1, 1, NULL, 330, NULL),
(13, 'overall', NULL, 2, 2, NULL, 198, NULL),
(13, 'overall', NULL, 3, 3, NULL, 132, NULL),
(14, 'overall', NULL, 1, 1, NULL, 80, NULL),
(16, 'overall', NULL, 1, 1, NULL, 350, NULL),
(16, 'overall', NULL, 2, 2, NULL, 170, NULL),
(16, 'overall', NULL, 3, 3, NULL, 120, NULL),
(17, 'overall', NULL, 1, 1, NULL, 144, NULL),
(18, 'overall', NULL, 1, 1, NULL, 80, NULL),
(18, 'pool', NULL, 1, 1, NULL, 64, NULL),
(19, 'overall', NULL, 1, 1, NULL, 48, NULL),
(19, 'pool', NULL, 1, 1, NULL, 32, NULL),
(20, 'overall', NULL, 1, 1, NULL, 32, NULL),
(20, 'pool', NULL, 1, 1, NULL, 16, NULL),
(22, 'overall', NULL, 1, 1, NULL, 250, NULL),
(22, 'overall', NULL, 2, 2, NULL, 150, NULL),
(22, 'overall', NULL, 3, 3, NULL, 80, NULL),
(23, 'overall', NULL, 1, 1, NULL, 162, NULL),
(24, 'overall', NULL, 1, 1, NULL, 90, NULL),
(24, 'pool', NULL, 1, 1, NULL, 72, NULL),
(25, 'overall', NULL, 1, 1, NULL, 66, NULL),
(25, 'pool', NULL, 1, 1, NULL, 48, NULL),
(26, 'overall', NULL, 1, 1, NULL, 72, NULL),
(26, 'pool', NULL, 1, 1, NULL, 18, NULL),
(27, 'overall', NULL, 1, 1, NULL, 90, NULL),
(27, 'overall', NULL, 2, 2, NULL, 45, NULL),
(27, 'overall', NULL, 3, 3, NULL, 27, NULL),
(29, 'overall', NULL, 1, 1, NULL, 250, NULL),
(29, 'overall', NULL, 2, 2, NULL, 150, NULL),
(29, 'overall', NULL, 3, 3, NULL, 80, NULL),
(30, 'overall', NULL, 1, 1, NULL, 100, NULL),
(30, 'overall', NULL, 2, 2, NULL, 60, NULL),
(30, 'overall', NULL, 3, 3, NULL, 20, NULL),
(31, 'overall', NULL, 1, 1, NULL, 100, NULL),
(31, 'pool', NULL, 1, 1, NULL, 80, NULL),
(32, 'overall', NULL, 1, 1, NULL, 90, NULL),
(32, 'pool', NULL, 1, 1, NULL, 30, NULL),
(33, 'overall', NULL, 1, 1, NULL, 90, NULL),
(33, 'pool', NULL, 1, 1, NULL, 10, NULL),
(35, 'pool', 1, 1, 1, NULL, 300, NULL),
(35, 'pool', 1, 2, 2, NULL, 200, NULL),
(35, 'pool', 1, 3, 3, NULL, 100, NULL),
(36, 'overall', NULL, 1, 1, NULL, 90, NULL),
(36, 'overall', NULL, 2, 2, NULL, 60, NULL),
(36, 'overall', NULL, 3, 3, NULL, 30, NULL),
(37, 'overall', NULL, 1, 1, NULL, 90, NULL),
(37, 'pool', NULL, 1, 1, NULL, 45, NULL),
(38, 'overall', NULL, 1, 1, NULL, 90, NULL),
(38, 'overall', NULL, 2, 7, NULL, 15, NULL),
(39, 'overall', NULL, 1, 1, NULL, 90, NULL),
(39, 'pool', NULL, 1, 1, NULL, 15, NULL),
(40, 'overall', NULL, 1, 1, NULL, 100, NULL),
(40, 'team', NULL, 1, 1, NULL, 40, NULL),
(42, 'pool', 1, 1, 1, NULL, 300, NULL),
(42, 'pool', 1, 2, 2, NULL, 200, NULL),
(42, 'pool', 1, 3, 3, NULL, 110, NULL),
(42, 'pool', 1, 4, 4, NULL, 70, NULL),
(42, 'pool', 2, 1, 1, NULL, 40, NULL),
(44, 'pool', 1, 1, 1, NULL, 200, NULL),
(44, 'pool', 1, 2, 2, NULL, 140, NULL),
(44, 'pool', 1, 3, 3, NULL, 100, NULL),
(44, 'pool', 1, 4, 4, NULL, 80, NULL),
(44, 'pool', 2, 1, 2, NULL, 45, NULL),
(45, 'overall', NULL, 1, 1, NULL, 150, NULL),
(45, 'overall', NULL, 2, 2, NULL, 100, NULL),
(45, 'overall', NULL, 3, 3, NULL, 75, NULL),
(45, 'overall', NULL, 4, 4, NULL, 50, NULL),
(45, 'overall', NULL, 5, 5, NULL, 25, NULL),
(46, 'overall', NULL, 1, 1, NULL, 150, NULL),
(46, 'pool', NULL, 1, 1, NULL, 63, NULL),
(47, 'overall', NULL, 1, 1, NULL, 150, NULL),
(47, 'overall', NULL, 2, 2, NULL, 70, NULL),
(47, 'overall', NULL, 3, 3, NULL, 50, NULL),
(47, 'overall', NULL, 4, 4, NULL, 40, NULL),
(47, 'overall', NULL, 5, 5, NULL, 30, NULL),
(47, 'overall', NULL, 6, 8, NULL, 20, NULL),
(48, 'overall', NULL, 1, 1, NULL, 150, NULL),
(48, 'pool', NULL, 1, 1, NULL, 35, NULL),
(49, 'overall', NULL, 1, 1, NULL, 150, NULL),
(49, 'overall', NULL, 2, 4, 41, NULL, 250),
(51, 'pool', 1, 1, 1, NULL, 250, NULL),
(51, 'pool', 1, 2, 2, NULL, 170, NULL),
(51, 'pool', 1, 3, 3, NULL, 130, NULL),
(51, 'pool', 1, 4, 4, NULL, 100, NULL),
(51, 'pool', 1, 5, 5, NULL, 70, NULL),
(51, 'pool', 2, 1, 2, NULL, 40, NULL),
(52, 'overall', NULL, 1, 1, NULL, 150, NULL),
(52, 'overall', NULL, 2, 2, NULL, 110, NULL),
(52, 'overall', NULL, 3, 3, NULL, 80, NULL),
(52, 'overall', NULL, 4, 4, NULL, 60, NULL),
(52, 'overall', NULL, 5, 5, NULL, 40, NULL),
(53, 'overall', NULL, 1, 1, NULL, 150, NULL),
(53, 'pool', NULL, 1, 1, NULL, 96.67, NULL),
(54, 'overall', NULL, 1, 1, NULL, 150, NULL),
(54, 'pool', NULL, 1, 1, NULL, 29, NULL),
(55, 'overall', NULL, 1, 1, NULL, 150, NULL),
(55, 'overall', NULL, 2, 2, NULL, 90, NULL),
(55, 'overall', NULL, 3, 3, NULL, 70, NULL),
(55, 'overall', NULL, 4, 4, NULL, 50, NULL),
(55, 'overall', NULL, 5, 5, NULL, 35, NULL),
(55, 'overall', NULL, 6, 6, NULL, 25, NULL),
(55, 'overall', NULL, 7, 7, NULL, 20, NULL),
(56, 'overall', NULL, 1, 1, NULL, 150, NULL),
(56, 'overall', NULL, 2, 5, 39, NULL, 290),
(57, 'overall', NULL, 1, 1, NULL, 150, NULL),
(57, 'pool', NULL, 1, 1, NULL, 70, NULL),
(57, 'pool', NULL, 2, 2, NULL, 20, NULL),
(59, 'pool', 1, 1, 1, NULL, 250, NULL),
(59, 'pool', 1, 2, 2, NULL, 170, NULL),
(59, 'pool', 1, 3, 3, NULL, 130, NULL),
(59, 'pool', 1, 4, 4, NULL, 100, NULL),
(59, 'pool', 1, 5, 5, NULL, 70, NULL),
(59, 'pool', 1, 6, 6, NULL, 40, NULL),
(59, 'pool', 2, 1, 1, NULL, 40, NULL),
(59, 'pool', 2, 2, 2, NULL, 30, NULL),
(59, 'pool', 2, 3, 4, NULL, 20, NULL),
(60, 'overall', NULL, 1, 1, NULL, 150, NULL),
(60, 'overall', NULL, 2, 2, NULL, 110, NULL),
(60, 'overall', NULL, 3, 3, NULL, 80, NULL),
(60, 'overall', NULL, 4, 4, NULL, 50, NULL),
(60, 'overall', NULL, 5, 5, NULL, 30, NULL),
(61, 'overall', NULL, 1, 1, NULL, 150, NULL),
(61, 'pool', NULL, 1, 1, NULL, 90, NULL),
(61, 'pool', NULL, 2, 2, NULL, 30, NULL),
(62, 'overall', NULL, 1, 1, NULL, 150, NULL),
(62, 'overall', NULL, 2, 2, NULL, 90, NULL),
(62, 'overall', NULL, 3, 3, NULL, 60, NULL),
(62, 'overall', NULL, 4, 4, NULL, 45, NULL),
(62, 'overall', NULL, 5, 5, NULL, 35, NULL),
(62, 'overall', NULL, 6, 6, NULL, 25, NULL),
(62, 'overall', NULL, 7, 7, NULL, 15, NULL),
(63, 'overall', NULL, 1, 1, NULL, 150, NULL),
(63, 'pool', NULL, 1, 1, NULL, 54, NULL),
(64, 'overall', NULL, 1, 1, NULL, 150, NULL),
(64, 'overall', NULL, 2, 5, 39, NULL, 270),
(66, 'pool', 1, 1, 1, NULL, 260, NULL),
(66, 'pool', 1, 2, 2, NULL, 185, NULL),
(66, 'pool', 1, 3, 3, NULL, 125, NULL),
(66, 'pool', 1, 4, 4, NULL, 80, NULL),
(66, 'pool', 1, 5, 5, NULL, 60, NULL),
(66, 'pool', 1, 6, 6, NULL, 40, NULL),
(66, 'pool', 2, 1, 1, NULL, 40, NULL),
(66, 'pool', 2, 2, 2, NULL, 20, NULL),
(66, 'pool', 2, 3, 4, NULL, 15, NULL),
(67, 'overall', NULL, 1, 1, NULL, 150, NULL),
(67, 'overall', NULL, 2, 2, NULL, 110, NULL),
(67, 'overall', NULL, 3, 3, NULL, 80, NULL),
(67, 'overall', NULL, 4, 4, NULL, 50, NULL),
(67, 'overall', NULL, 5, 5, NULL, 30, NULL),
(68, 'overall', NULL, 1, 1, NULL, 150, NULL),
(68, 'pool', NULL, 1, 1, NULL, 65, NULL),
(68, 'pool', NULL, 2, 2, NULL, 18, NULL),
(69, 'overall', NULL, 1, 1, NULL, 150, NULL),
(69, 'overall', NULL, 2, 2, NULL, 100, NULL),
(69, 'overall', NULL, 3, 3, NULL, 70, NULL),
(69, 'overall', NULL, 4, 4, NULL, 50, NULL),
(69, 'overall', NULL, 5, 5, NULL, 40, NULL),
(69, 'overall', NULL, 6, 6, NULL, 35, NULL),
(69, 'overall', NULL, 7, 7, NULL, 30, NULL),
(69, 'overall', NULL, 8, 8, NULL, 25, NULL),
(70, 'overall', NULL, 1, 1, NULL, 150, NULL),
(70, 'pool', NULL, 1, 1, NULL, 40, NULL),
(72, 'pool', 1, 1, 1, NULL, 260, NULL),
(72, 'pool', 1, 2, 2, NULL, 180, NULL),
(72, 'pool', 1, 3, 3, NULL, 120, NULL),
(72, 'pool', 1, 4, 4, NULL, 100, NULL),
(72, 'pool', 1, 5, 5, NULL, 80, NULL),
(72, 'pool', 1, 6, 6, NULL, 60, NULL),
(72, 'pool', 1, 7, 7, NULL, 40, NULL),
(72, 'pool', 1, 8, 10, NULL, 20, NULL),
(72, 'pool', 2, 1, 1, NULL, 60, NULL),
(72, 'pool', 2, 2, 2, NULL, 40, NULL),
(72, 'pool', 2, 3, 3, NULL, 30, NULL),
(72, 'pool', 2, 4, 4, NULL, 20, NULL),
(73, 'overall', NULL, 1, 1, NULL, 150, NULL),
(73, 'overall', NULL, 2, 2, NULL, 125, NULL),
(73, 'overall', NULL, 3, 3, NULL, 105, NULL),
(73, 'overall', NULL, 4, 4, NULL, 85, NULL),
(73, 'overall', NULL, 5, 5, NULL, 65, NULL),
(74, 'overall', NULL, 1, 1, NULL, 150, NULL),
(74, 'overall', NULL, 2, 2, NULL, 100, NULL),
(74, 'overall', NULL, 3, 3, NULL, 75, NULL),
(74, 'overall', NULL, 4, 4, NULL, 60, NULL),
(74, 'overall', NULL, 5, 5, NULL, 50, NULL),
(74, 'overall', NULL, 6, 6, NULL, 40, NULL),
(74, 'overall', NULL, 7, 7, NULL, 35, NULL),
(74, 'overall', NULL, 8, 8, NULL, 30, NULL),
(75, 'overall', NULL, 1, 1, NULL, 150, NULL),
(75, 'pool', NULL, 1, 1, NULL, 90, NULL),
(75, 'pool', NULL, 2, 2, NULL, 50, NULL),
(75, 'pool', NULL, 3, 3, NULL, 20, NULL),
(76, 'overall', NULL, 1, 1, NULL, 150, NULL),
(76, 'pool', NULL, 1, 1, NULL, 54, NULL),
(76, 'pool', NULL, 2, 2, NULL, 20, NULL),
(77, 'overall', NULL, 1, 1, NULL, 150, NULL),
(77, 'pool', NULL, 1, 1, NULL, 48.75, NULL),
(78, 'overall', NULL, 1, 1, NULL, 150, NULL),
(78, 'pool', NULL, 1, 1, NULL, 110, NULL),
(78, 'pool', NULL, 2, 2, NULL, 70, NULL),
(78, 'pool', NULL, 3, 3, NULL, 45, NULL),
(78, 'pool', NULL, 4, 4, NULL, 25, NULL),
(80, 'pool', 1, 1, 1, NULL, 260, NULL),
(80, 'pool', 1, 2, 2, NULL, 180, NULL),
(80, 'pool', 1, 3, 3, NULL, 120, NULL),
(80, 'pool', 1, 4, 4, NULL, 100, NULL),
(80, 'pool', 1, 5, 5, NULL, 80, NULL),
(80, 'pool', 1, 6, 6, NULL, 60, NULL),
(80, 'pool', 1, 7, 7, NULL, 40, NULL),
(80, 'pool', 1, 8, 10, NULL, 20, NULL),
(80, 'pool', 2, 1, 1, NULL, 70, NULL),
(80, 'pool', 2, 2, 2, NULL, 50, NULL),
(80, 'pool', 2, 3, 3, NULL, 35, NULL),
(80, 'pool', 2, 4, 4, NULL, 25, NULL),
(81, 'overall', NULL, 1, 1, NULL, 75, NULL),
(81, 'overall', NULL, 2, 2, NULL, 40, NULL),
(81, 'overall', NULL, 3, 3, NULL, 30, NULL),
(81, 'overall', NULL, 4, 4, NULL, 20, NULL),
(81, 'overall', NULL, 5, 5, NULL, 15, NULL),
(81, 'overall', NULL, 6, 6, NULL, 10, NULL),
(82, 'overall', NULL, 1, 1, NULL, 160, NULL),
(82, 'overall', NULL, 2, 2, NULL, 125, NULL),
(82, 'overall', NULL, 3, 3, NULL, 105, NULL),
(82, 'overall', NULL, 4, 4, NULL, 85, NULL),
(82, 'overall', NULL, 5, 5, NULL, 75, NULL),
(82, 'overall', NULL, 6, 6, NULL, 60, NULL),
(83, 'overall', NULL, 1, 1, NULL, 160, NULL),
(83, 'overall', NULL, 2, 2, NULL, 110, NULL),
(83, 'overall', NULL, 3, 3, NULL, 90, NULL),
(83, 'overall', NULL, 4, 4, NULL, 65, NULL),
(83, 'overall', NULL, 5, 5, NULL, 55, NULL),
(83, 'overall', NULL, 6, 6, NULL, 45, NULL),
(83, 'overall', NULL, 7, 7, NULL, 35, NULL),
(83, 'overall', NULL, 8, 8, NULL, 30, NULL),
(84, 'overall', NULL, 1, 1, NULL, 160, NULL),
(84, 'pool', NULL, 1, 1, NULL, 90, NULL),
(84, 'pool', NULL, 2, 2, NULL, 55, NULL),
(84, 'pool', NULL, 3, 3, NULL, 32, NULL),
(85, 'overall', NULL, 1, 1, NULL, 160, NULL),
(85, 'pool', NULL, 1, 1, NULL, 64, NULL),
(85, 'pool', NULL, 2, 2, NULL, 20, NULL),
(86, 'overall', NULL, 1, 1, NULL, 160, NULL),
(86, 'pool', NULL, 1, 1, NULL, 49, NULL),
(87, 'overall', NULL, 1, 1, NULL, 160, NULL),
(87, 'overall', NULL, 2, 2, NULL, 105, NULL),
(87, 'overall', NULL, 3, 3, NULL, 75, NULL),
(87, 'overall', NULL, 4, 4, NULL, 60, NULL),
(87, 'overall', NULL, 5, 5, NULL, 50, NULL),
(87, 'overall', NULL, 6, 6, NULL, 40, NULL),
(87, 'overall', NULL, 7, 7, NULL, 35, NULL),
(87, 'overall', NULL, 8, 8, NULL, 30, NULL),
(87, 'overall', NULL, 9, 9, NULL, 25, NULL),
(87, 'overall', NULL, 10, 10, NULL, 20, NULL),
(88, 'overall', NULL, 1, 1, NULL, 160, NULL),
(88, 'pool', NULL, 1, 1, NULL, 100, NULL),
(88, 'pool', NULL, 2, 2, NULL, 70, NULL),
(88, 'pool', NULL, 3, 3, NULL, 40, NULL),
(88, 'pool', NULL, 4, 4, NULL, 25, NULL),
(88, 'pool', NULL, 5, 5, NULL, 20, NULL),
(88, 'pool', NULL, 6, 6, NULL, 15, NULL),
(89, 'overall', NULL, 1, 1, NULL, 160, NULL),
(89, 'overall', NULL, 2, 2, NULL, 100, NULL),
(89, 'overall', NULL, 3, 3, NULL, 79, NULL),
(89, 'overall', NULL, 4, 6, NULL, 42, NULL),
(89, 'overall', NULL, 7, 9, NULL, 20, NULL),
(89, 'overall', NULL, 10, 15, NULL, 12, NULL),
(91, 'pool', 2, 1, 1, NULL, 220, NULL),
(91, 'pool', 2, 2, 2, NULL, 140, NULL),
(91, 'pool', 2, 3, 3, NULL, 110, NULL),
(91, 'pool', 2, 4, 4, NULL, 90, NULL),
(91, 'pool', 2, 5, 5, NULL, 70, NULL),
(91, 'pool', 2, 6, 6, NULL, 50, NULL),
(91, 'pool', 2, 7, 7, NULL, 40, NULL),
(91, 'pool', 2, 8, 9, NULL, 30, NULL),
(91, 'pool', 2, 10, 15, NULL, 20, NULL),
(91, 'pool', 1, 1, 1, NULL, 100, NULL),
(91, 'pool', 1, 2, 2, NULL, 60, NULL),
(91, 'pool', 1, 3, 3, NULL, 40, NULL),
(91, 'pool', 1, 4, 4, NULL, 30, NULL),
(91, 'pool', 1, 5, 5, NULL, 25, NULL),
(91, 'pool', 1, 6, 6, NULL, 20, NULL),
(91, 'pool', 1, 7, 7, NULL, 15, NULL),
(91, 'pool', 1, 8, 8, NULL, 10, NULL),
(92, 'overall', NULL, 1, 1, NULL, 140, NULL),
(92, 'overall', NULL, 2, 2, NULL, 105, NULL),
(92, 'overall', NULL, 3, 3, NULL, 80, NULL),
(92, 'overall', NULL, 4, 4, NULL, 65, NULL),
(92, 'overall', NULL, 5, 5, NULL, 55, NULL),
(92, 'overall', NULL, 6, 6, NULL, 45, NULL),
(93, 'overall', NULL, 1, 1, NULL, 140, NULL),
(93, 'overall', NULL, 2, 2, NULL, 90, NULL),
(93, 'overall', NULL, 3, 3, NULL, 70, NULL),
(93, 'overall', NULL, 4, 4, NULL, 60, NULL),
(93, 'overall', NULL, 5, 5, NULL, 50, NULL),
(93, 'overall', NULL, 6, 6, NULL, 35, NULL),
(93, 'overall', NULL, 7, 7, NULL, 30, NULL),
(93, 'overall', NULL, 8, 8, NULL, 25, NULL),
(94, 'overall', NULL, 1, 1, NULL, 140, NULL),
(94, 'pool', NULL, 1, 1, NULL, 40, NULL),
(94, 'pool', NULL, 2, 2, NULL, 25, NULL),
(94, 'pool', NULL, 3, 3, NULL, 15, NULL),
(96, 'pool', 1, 1, 1, NULL, 240, NULL),
(96, 'pool', 1, 2, 2, NULL, 140, NULL),
(96, 'pool', 1, 3, 3, NULL, 110, NULL),
(96, 'pool', 1, 4, 4, NULL, 90, NULL),
(96, 'pool', 1, 5, 5, NULL, 70, NULL),
(96, 'pool', 1, 6, 6, NULL, 50, NULL),
(96, 'pool', 1, 7, 7, NULL, 40, NULL),
(96, 'pool', 1, 8, 9, NULL, 30, NULL),
(96, 'pool', 1, 10, 15, NULL, 20, NULL),
(96, 'pool', 2, 1, 1, NULL, 80, NULL),
(96, 'pool', 2, 2, 2, NULL, 40, NULL),
(96, 'pool', 2, 3, 3, NULL, 30, NULL),
(96, 'pool', 2, 4, 4, NULL, 20, NULL);

-- Assign every week its format
UPDATE `football_weeks` SET `football_week_format_id` = 1 WHERE `id` IN (99, 104, 108);  -- existing 2018 format
UPDATE `football_weeks` SET `football_week_format_id` = 2 WHERE `id` IN (100, 107);  -- existing 2018 format
UPDATE `football_weeks` SET `football_week_format_id` = 3 WHERE `id` IN (101, 106);  -- existing 2018 format
UPDATE `football_weeks` SET `football_week_format_id` = 4 WHERE `id` IN (102);  -- existing 2018 format
UPDATE `football_weeks` SET `football_week_format_id` = 5 WHERE `id` IN (103);  -- existing 2018 format
UPDATE `football_weeks` SET `football_week_format_id` = 6 WHERE `id` IN (105);  -- existing 2018 format
UPDATE `football_weeks` SET `football_week_format_id` = 7 WHERE `id` IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13);  -- Winner Takes All (6 players)
UPDATE `football_weeks` SET `football_week_format_id` = 8 WHERE `id` IN (14, 15, 16, 17, 18, 19, 20, 21, 22, 23);  -- Winner Takes All (10 players)
UPDATE `football_weeks` SET `football_week_format_id` = 9 WHERE `id` IN (24);  -- Semifinals (10 players)
UPDATE `football_weeks` SET `football_week_format_id` = 10 WHERE `id` IN (25);  -- Finals (10 players)
UPDATE `football_weeks` SET `football_week_format_id` = 11 WHERE `id` IN (26, 27, 28, 29, 30, 31, 32, 33, 34, 35);  -- Winner Takes All (11 players)
UPDATE `football_weeks` SET `football_week_format_id` = 12 WHERE `id` IN (36);  -- Semifinals (11 players)
UPDATE `football_weeks` SET `football_week_format_id` = 13 WHERE `id` IN (37);  -- Finals (11 players)
UPDATE `football_weeks` SET `football_week_format_id` = 14 WHERE `id` IN (38, 39, 40, 41, 42, 43, 44, 45, 46, 47);  -- Winner Takes All (12 players)
UPDATE `football_weeks` SET `football_week_format_id` = 15 WHERE `id` IN (48);  -- Semifinals (12 players)
UPDATE `football_weeks` SET `football_week_format_id` = 16 WHERE `id` IN (49);  -- Finals (12 players)
UPDATE `football_weeks` SET `football_week_format_id` = 17 WHERE `id` IN (50, 58, 59);  -- King of the Castle (16 players)
UPDATE `football_weeks` SET `football_week_format_id` = 18 WHERE `id` IN (51, 52);  -- Two Pools (16 players)
UPDATE `football_weeks` SET `football_week_format_id` = 19 WHERE `id` IN (53, 54, 56, 57);  -- Foursomes (16 players)
UPDATE `football_weeks` SET `football_week_format_id` = 20 WHERE `id` IN (55);  -- Head-to-Head (16 players)
UPDATE `football_weeks` SET `football_week_format_id` = 21 WHERE `id` IN (60);  -- Semifinals (16 players)
UPDATE `football_weeks` SET `football_week_format_id` = 22 WHERE `id` IN (61);  -- Finals (16 players)
UPDATE `football_weeks` SET `football_week_format_id` = 23 WHERE `id` IN (62);  -- King of the Castle (18 players)
UPDATE `football_weeks` SET `football_week_format_id` = 24 WHERE `id` IN (63, 64);  -- Two Pools (18 players)
UPDATE `football_weeks` SET `football_week_format_id` = 25 WHERE `id` IN (65, 66, 68, 69);  -- 666 (18 players)
UPDATE `football_weeks` SET `football_week_format_id` = 26 WHERE `id` IN (67);  -- Ménage à Trois (18 players)
UPDATE `football_weeks` SET `football_week_format_id` = 27 WHERE `id` IN (70, 71);  -- King of the Castle 90/45/27 (18 players)
UPDATE `football_weeks` SET `football_week_format_id` = 28 WHERE `id` IN (72);  -- Semifinals (18 players)
UPDATE `football_weeks` SET `football_week_format_id` = 29 WHERE `id` IN (73);  -- Finals (18 players)
UPDATE `football_weeks` SET `football_week_format_id` = 30 WHERE `id` IN (75, 83, 84);  -- King of the Castle 100/60/20 (20 players)
UPDATE `football_weeks` SET `football_week_format_id` = 31 WHERE `id` IN (76, 77);  -- Two Pools (20 players)
UPDATE `football_weeks` SET `football_week_format_id` = 32 WHERE `id` IN (78, 79, 81, 82);  -- Five-somes (20 players)
UPDATE `football_weeks` SET `football_week_format_id` = 33 WHERE `id` IN (80);  -- Head-to-Head (20 players)
UPDATE `football_weeks` SET `football_week_format_id` = 34 WHERE `id` IN (85);  -- Semifinals (20 players)
UPDATE `football_weeks` SET `football_week_format_id` = 35 WHERE `id` IN (86);  -- Finals (20 players)
UPDATE `football_weeks` SET `football_week_format_id` = 36 WHERE `id` IN (87, 92, 95, 96);  -- Olympic 90/60/30 (21 players)
UPDATE `football_weeks` SET `football_week_format_id` = 37 WHERE `id` IN (88, 94);  -- 3 Pools of 7 (21 players)
UPDATE `football_weeks` SET `football_week_format_id` = 38 WHERE `id` IN (89, 93);  -- Top Third (21 players)
UPDATE `football_weeks` SET `football_week_format_id` = 39 WHERE `id` IN (90);  -- 7 Pools of 3 (21 players)
UPDATE `football_weeks` SET `football_week_format_id` = 40 WHERE `id` IN (91);  -- 7 Teams of 3 (21 players)
UPDATE `football_weeks` SET `football_week_format_id` = 41 WHERE `id` IN (97);  -- Semifinals (21 players)
UPDATE `football_weeks` SET `football_week_format_id` = 42 WHERE `id` IN (98);  -- Finals (21 players)
UPDATE `football_weeks` SET `football_week_format_id` = 43 WHERE `id` IN (109);  -- Semifinals (32 players)
UPDATE `football_weeks` SET `football_week_format_id` = 44 WHERE `id` IN (110);  -- Finals (32 players)
UPDATE `football_weeks` SET `football_week_format_id` = 45 WHERE `id` IN (112, 117, 119, 121);  -- Top Five 150/100/75/50/25 (40 players)
UPDATE `football_weeks` SET `football_week_format_id` = 46 WHERE `id` IN (113, 118);  -- 5 Pools of 8 (40 players)
UPDATE `football_weeks` SET `football_week_format_id` = 47 WHERE `id` IN (114);  -- Top Eight (40 players)
UPDATE `football_weeks` SET `football_week_format_id` = 48 WHERE `id` IN (115, 120);  -- 8 Pools of 5 (40 players)
UPDATE `football_weeks` SET `football_week_format_id` = 49 WHERE `id` IN (116);  -- Forty-One Points (40 players)
UPDATE `football_weeks` SET `football_week_format_id` = 50 WHERE `id` IN (122);  -- Semifinals (40 players)
UPDATE `football_weeks` SET `football_week_format_id` = 51 WHERE `id` IN (123);  -- Finals (40 players)
UPDATE `football_weeks` SET `football_week_format_id` = 52 WHERE `id` IN (125);  -- Top Five 150/110/80/60/40 (44 players)
UPDATE `football_weeks` SET `football_week_format_id` = 53 WHERE `id` IN (126, 131);  -- 4 Pools of 11 (44 players)
UPDATE `football_weeks` SET `football_week_format_id` = 54 WHERE `id` IN (127, 132);  -- 11 Pools of 4 (44 players)
UPDATE `football_weeks` SET `football_week_format_id` = 55 WHERE `id` IN (128, 130, 134);  -- Top Seven 150/90/70/50/35/25/20 (44 players)
UPDATE `football_weeks` SET `football_week_format_id` = 56 WHERE `id` IN (129);  -- Thirty-nine Points (44 players)
UPDATE `football_weeks` SET `football_week_format_id` = 57 WHERE `id` IN (133);  -- 4 Pools of 11 - $70/$20 (44 players)
UPDATE `football_weeks` SET `football_week_format_id` = 58 WHERE `id` IN (135);  -- Semifinals (44 players)
UPDATE `football_weeks` SET `football_week_format_id` = 59 WHERE `id` IN (136);  -- Finals (44 players)
UPDATE `football_weeks` SET `football_week_format_id` = 60 WHERE `id` IN (137);  -- Top Five 150/110/80/50/30 (42 players)
UPDATE `football_weeks` SET `football_week_format_id` = 61 WHERE `id` IN (138, 144);  -- 3 Pools of 14 (42 players)
UPDATE `football_weeks` SET `football_week_format_id` = 62 WHERE `id` IN (139, 143, 145, 146);  -- Top Seven 150/90/60/45/35/25/15 (42 players)
UPDATE `football_weeks` SET `football_week_format_id` = 63 WHERE `id` IN (140, 142);  -- 6 Pools of 7 (42 players)
UPDATE `football_weeks` SET `football_week_format_id` = 64 WHERE `id` IN (141);  -- Thirty-nine Points (42 players)
UPDATE `football_weeks` SET `football_week_format_id` = 65 WHERE `id` IN (147);  -- Semifinals (42 players)
UPDATE `football_weeks` SET `football_week_format_id` = 66 WHERE `id` IN (148);  -- Finals (42 players)
UPDATE `football_weeks` SET `football_week_format_id` = 67 WHERE `id` IN (149);  -- Top Five (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 68 WHERE `id` IN (150, 152, 157);  -- 5 Pools of 10 (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 69 WHERE `id` IN (151, 153, 154, 156, 158);  -- Top Eight (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 70 WHERE `id` IN (155);  -- 10 Pools of 5 (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 71 WHERE `id` IN (159);  -- Semifinals (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 72 WHERE `id` IN (160);  -- Finals (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 73 WHERE `id` IN (173);  -- Top Five (54 players)
UPDATE `football_weeks` SET `football_week_format_id` = 74 WHERE `id` IN (174, 178, 182);  -- Top 8 (54 players)
UPDATE `football_weeks` SET `football_week_format_id` = 75 WHERE `id` IN (175, 180);  -- 3 Pools of 18 (54 players)
UPDATE `football_weeks` SET `football_week_format_id` = 76 WHERE `id` IN (176, 181);  -- 6 Pools of 9 (54 players)
UPDATE `football_weeks` SET `football_week_format_id` = 77 WHERE `id` IN (177);  -- 9 Pools of 6 (54 players)
UPDATE `football_weeks` SET `football_week_format_id` = 78 WHERE `id` IN (179);  -- 2 Pools of 27 (54 players)
UPDATE `football_weeks` SET `football_week_format_id` = 79 WHERE `id` IN (183);  -- Semifinals (54 players)
UPDATE `football_weeks` SET `football_week_format_id` = 80 WHERE `id` IN (184);  -- Finals (54 players)
UPDATE `football_weeks` SET `football_week_format_id` = 81 WHERE `id` IN (185, 186, 187, 188);  -- Top 6: $75, $40, $30, $20, $15, $10 (38 players)
UPDATE `football_weeks` SET `football_week_format_id` = 82 WHERE `id` IN (209);  -- Top Six (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 83 WHERE `id` IN (210);  -- Top 8 (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 84 WHERE `id` IN (211, 216, 225);  -- 3 Pools of 20 (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 85 WHERE `id` IN (212, 217, 224, 229);  -- 6 Pools of 10 (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 86 WHERE `id` IN (213, 228);  -- 10 Pools of 6 (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 87 WHERE `id` IN (214, 227);  -- Top 10 (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 88 WHERE `id` IN (215, 226);  -- 2 Pools of 30 (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 89 WHERE `id` IN (218, 223);  -- Top 15 (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 90 WHERE `id` IN (219);  -- Semifinals (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 91 WHERE `id` IN (220);  -- Finals (60 players)
UPDATE `football_weeks` SET `football_week_format_id` = 92 WHERE `id` IN (232);  -- Top Six (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 93 WHERE `id` IN (231);  -- Top 8 (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 94 WHERE `id` IN (230);  -- 5 Pools of 10 (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 95 WHERE `id` IN (222);  -- Semifinals (50 players)
UPDATE `football_weeks` SET `football_week_format_id` = 96 WHERE `id` IN (221);  -- Finals (50 players)

-- Expected afterwards: SELECT COUNT(*) FROM football_weeks WHERE football_week_format_id IS NULL;  -- 0
-- SELECT COUNT(*) FROM football_week_formats;  -- 96
