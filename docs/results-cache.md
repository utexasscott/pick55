# Week results page and its cache

**Shape: VERTICAL (page).** How `season/week/results.php` computes scores, ranks and win probabilities, why it was slow, and how the cache in `Pick55\WeekResults` keeps it fast.

## What the page computes

For one week and one set of players (every season player, or the members of one pool when a pool is selected), the page shows:

- each player's points, correct count and competition rank from the decided games (`football_games.correct_option` is `1` or `2`);
- for every still-undecided game, the "what if" enumeration: every one of the 2^n possible outcomes is scored and ranked, and each player's share of outcomes that leave them in 1st .. N place (and, when the format has a point threshold, at or above it) is shown as a percentage. N and the threshold come from the week's format via `Week::getNumWinners($pool_num)` / `Week::getMinScoreThreshold($pool_num)`, where `$pool_num` is the selected pool's `pool_num` (null for the overall view), so a format whose pool rows differ from its overall rows gives different columns per pool;
- the per-game cells listing who picked which side at which multiplier;
- money (added 2026-09-25): what the format pays each player on the current standing, the **expected winnings** (the mean payout over every outcome of the undecided games, shown as an `Exp $` column on desktop widths while games remain), and the **Expected Winnings chart** (each of the top five finishers' and the viewer's expected winnings after each kickoff slot). See "Money" below.

The enumeration is the cost. Measured locally on 2026-09-12 with the week 232 shape (14 games, 118 players): with all 14 games undecided the old page took 8.4 s of CPU, of which SQL was 0.1 s across 421 queries; with 4 undecided it took 0.2 s. The production droplet is slower than the workstation, so the live page took correspondingly longer on a Saturday morning before any score was entered.

## Where the code lives

| Piece | File |
|---|---|
| Page (auth, pool selection, what-if parsing, winnings form, HTML) | `season/week/results.php` |
| Calculation and cache lookup | `inc/Pick55/WeekResults.php` (`get()`, `compute()`, `fingerprint()`) |
| File cache primitive | `inc/Pick55/Cache.php` |
| Per-game pick cells | `inc/Pick55/Snippets/GameOptionCell.php` (accepts preloaded `players` and `picks`) |
| Payout rules (place amounts, ties, thresholds, split pots, overall vs pool) and recording winners | `inc/Pick55/WeekPayouts.php` (`tables()`, `assign()`, `assignAll()`, `syncWinners()`) |

`WeekResults::get($week, $focus_user_ids, $what_ifs_by_game_id, $pool_num, $options)` returns one array: the games (attribute arrays, kickoff order), the focus users' bets grouped by game, the sorted and ranked stats keyed `u<user_id>` (each with `payout` and `expected_payout`), the undecided game ids, the prediction count and `has_payouts`. `$options` carries `pool_by_user_id` (so the overall view can pay the format's pool rows) and `undecided_game_ids` (games to treat as undecided, used by the timeline). The page hydrates `Game` models from the attribute arrays and adds the recorded winnings (`football_week_winners`, written by `WeekPayouts::syncWinners()` once the week is complete, see "Money") before rendering.

The page always computes the **overall view** (every season player, with their pools) because money is decided there; when a pool is selected it computes the pool view as well for that pool's ranks and probabilities, and reads `payout` / `expected_payout` from the overall results.

## How the cache is invalidated: by fingerprint, not by hooks

The owner's requirement (2026-09-12) was "run the queries once each time a score has been updated". Rather than adding an invalidation call to every code path that can change the result (admin game save, the set-game-result buttons, pick saving, randomize picks, guaranteed points, week settings, pool membership, several of which use raw SQL), the cache key itself carries a fingerprint of the inputs:

- `football_games` for the week: count plus `BIT_XOR` and `SUM` of `CRC32(id | correct_option | date | time | title | option_1 | option_2)`;
- `football_bets` on those games: count plus `BIT_XOR` and `SUM` of `CRC32(id | user_id | option | multiplier)`;
- the week's format: `football_weeks.football_week_format_id`, the `$pool_num` being viewed, and the format's `football_week_format_payouts` rows (count plus `BIT_XOR` and `SUM` of `CRC32(id | place_type | pool_num | min_place | max_place | min_points | payout | total_payout)`), which decide the paying places and point threshold. A `'v3'` version tag is hashed in too (v3 since 2026-09-25, when payouts joined the stats), so changing what the fingerprint covers or what compute() returns invalidates every older entry.

Three aggregate queries, all index-driven, run on every request. Any score update, pick change, format reassignment or payout edit yields a different fingerprint, so the next request misses, recomputes, stores under the new key, and sweeps this week's entries built from older fingerprints. Nothing else in the app knows the cache exists.

Key format: `results-<week_id>-<fingerprint16>-<variant md5>`, where the variant is the sorted focus user ids, the what-if selection and the normalized options (pool map, pretend-undecided games). Every pool view and every what-if combination is its own entry, computed on first view. The timeline is one more entry per week, `results-<week_id>-<fingerprint16>-timeline-<md5>`, swept with the rest when the fingerprint changes. Recorded winnings are not part of the key because they are read after the cache lookup. Pool membership is in the variant, not the fingerprint, so moving a player between pools changes the key the page asks for rather than invalidating anything.

A per-week file lock (`results-<week_id>-lock.lock`) makes concurrent misses wait for one computation instead of all computing at once.

## Money: payouts, expected winnings, the timeline (2026-09-25)

The owner's requests on 2026-09-25: winnings should not be editable (they follow the week format), show expected winnings while games are unfinished, and chart how the money moved over the week.

**Payout rules** live in `Pick55\WeekPayouts`. `tables($format, $num_players, $pool_nums)` turns the format's rows into one compact table for the overall standing and one per pool (`places` = place to amount for per-player rows, `thresholds` = min_points to amount, `splits` = split-pot rows). `assign($order, $table)` walks one standing (player to sort key, sorted descending) in tie groups: a group tied over places r..r+t-1 splits the sum of those places' amounts equally (owner's tie rule, 2026-09-25); a threshold row pays its amount to anyone at or above the points; a split pot is shared by everyone qualifying by place or points; a player keeps the single largest amount they qualify for. `assignAll()` takes the larger of the overall amount and the player's pool amount, which is the "overall winner displaces their pool's 1st place" semantics of docs/week-formats.md.

**In `compute()`** (overall view only, i.e. `$pool_num === null`): `payout` is `assignAll()` on the current standing; `expected_payout` is the same call inside every step of the Gray-code enumeration, summed and divided by 2^n. The enumeration loop now walks an `arsort`ed copy of the keys in tie groups (competition rank = the group's first place), which gives the same ranks as the old `rsort` + cutoff test and the payouts in one pass. Checked 2026-09-25 against week 231 (50 players, format "Top 8"): the computed payouts equal the eight recorded winner rows exactly and sum to the format's $500; expected payouts with all 14 games undecided sum to $500.01 (rounding). Week 230 ("5 Pools of 10", 14 undecided): 0.34 s per overall computation, up from 0.22 s without the pool payouts.

**Recording winners.** When the overall computation has no undecided game and the format pays anything, the page calls `WeekPayouts::syncWinners($week, $amounts, $last_game_at)`, which makes `football_week_winners` match the computed amounts (insert, update, delete; duplicates collapsed). It writes only when something differs, and only while the week's last kickoff is within `RESYNC_DAYS` (14): older weeks keep what was actually paid (2018 week 7 and 2019 week 5 were paid differently from their formats), while a score corrected in the days after a recent week still flows through. A week with no rows yet is always written. The admin winnings form and its `save` POST are gone; the Winnings column is read-only for everyone.

**Expected winnings column.** Shown when the format pays, the week is incomplete and there are predictions; hidden below Bootstrap's `md` breakpoint (`d-none d-md-table-cell`) because the phone layout has no room (owner, 2026-09-25). In a pool view the values are the overall ones, so a player's expected money is the same number whichever tab is open.

**The timeline** (`WeekResults::timeline($week, $user_ids, $pool_by_user_id)`) groups the games by kickoff (same date and time = one slot) and runs `compute()` once per prefix of slots: point 0 with every game undecided, point k with slots 1..k at their stored results and the rest undecided (`undecided_game_ids`). It stops at the first slot with an undecided game, so the series ends at the current state during a live week and at the finished week afterwards. Cost for week 231's 9 points: 0.40 s (2^14 + 2^13 + 2^11 + ... states), cached per fingerprint. The page skips it while no game is decided (one point is not a line). The chart (Chart.js 3.5, already loaded on every page) shows the top five of the overall standing plus the viewer, the viewer's line black and thicker; line colors are the first six slots of the validated categorical palette from the dataviz skill.

**Collapsed tables** (same change): "Picks by Point Value" shows the first 10 rows plus the viewer's, the rest carry `d-none js-more-picks` behind a "Show all N players" button; each decided game's cells show the first 5 other players per side plus the viewer's own pick, the rest carry `d-none js-more-g<id>` behind a "Show all picks (+N)" link in the game's title cell (`GameOptionCell` params `collapse_after` / `collapse_class`, `GameOptionCell::hiddenCount()` for the count). Undecided games show every pick. The generic toggle is `[data-toggle-more="SELECTOR"]` in `static/js/global.js`.

**Live scores on the page** (2026-09-25; the scraper side is [live-scores.md](live-scores.md)). The page reads `GameScore::forWeek()` outside the cache (a missing `football_game_scores` table is caught, so the page works before the migration). Under each game's title it prints "Georgia 45, Arkansas 17" (NFL nicknames, NCAA school names, the option-label convention) plus the status: "Final" muted, or "Q3 4:12" in green while live. An undecided game whose score row has a leading option gets a green `leading` badge and a green inset border on that cell (`GameOptionCell` param `leading`). While any game is undecided the page polls `season/week/live.php` (every 60 s while a game is in progress, every 5 min otherwise), repaints the score lines and badges, and reloads itself when the endpoint reports a `correct_option` the page did not have, so standings, probabilities and money recompute. Checked 2026-09-25 through local Apache with a planted `football_game_scores` row (state `in`, Texas 21, Tennessee 20 on Texas -4.5): the line, the green status and the badge on the Tennessee cell rendered; the row was deleted afterwards.

**Cold render through local Apache** (2026-09-25, non-admin viewer): week 230 (5 pools, 14 undecided) pool view 1.3 s (an overall and a pool computation), all-players view 1.3 s; week 231 (finished) 0.12 s warm. Warm views are 0.1 to 0.15 s.

## Storage

`Pick55\Cache` writes serialized PHP arrays to `config('cache_dir')`, defaulting to `<repo>/cache/` (git-ignored, created on first use) and falling back to `<system temp>/pick55-cache` when the repo directory is not writable by the web server. If neither is writable the cache silently becomes a no-op and the page still works, just uncached. Writes are atomic (temp file plus rename). Entries are a few hundred KB for a 118-player week.

Production: Apache runs as `www-data` and the web root is owned by `beanstalk` (measured 2026-09-24), so PHP cannot create `cache/` itself. `scripts/cutover-to-git.sh` created it owned by `www-data`, mode 775, on 2026-09-25, so the cache lives at `/home/beanstalk/pick55/cache/` in production.

## The enumeration itself is also cheaper

The cache makes repeat views instant, but the first view after each score change still pays for the enumeration, so `WeekResults::enumeratePredictions()` replaces the old loop:

- each player's standing is one integer key `points << 24 | right << 16 | bit_mult`, which orders exactly like the old three-column `array_multisort` and ties exactly where the old float `sort_score` tied;
- outcomes are walked in Gray-code order, so each step flips one game and adds or subtracts a precomputed per-player delta instead of rebuilding every player's totals;
- rank is competition rank (1 + players with a strictly greater key), read off a sorted copy of the keys once per outcome.

Verified 2026-09-12 by diffing the rendered HTML of the old and new page across 19 scenarios (all/partial/no undecided games, what-ifs, pools, a threshold week, an AUTO-pick week, admin and non-admin viewers). Two intentional differences remain:

1. **The old page mis-ranked exact ties.** Its tie test compared the float `sort_score` (points plus tiny per-multiplier epsilons), and adding the same epsilons in a different order gives a different last bit. Over the 16,384 outcomes of a 14-undecided week that split 12,267 exact ties and changed 427 percentage cells (by about 0.1 point each). Running the old enumeration with an exact tie test reproduces the new numbers cell for cell, so the new percentages are the correct ones.
2. **Same-multiplier names inside a game cell** are now ordered by pick id. The old per-cell query ordered only by multiplier and MySQL filesort is not stable, so that order was arbitrary before.

## Other query trims made in the same change

- `Auth::user()` memoizes the logged-in `User` for the request (the results table called `Auth::isAdmin()` per row, one query each).
- `Season::getPlayers()` loads players with one `whereIn` instead of one query per season link.
- `Week::getFirstGameAt()` memoizes per week per request (the nav bars ask it for every week on every page).
- `GameOptionCell` takes preloaded `players` and `picks`, so the results page passes the cached bets instead of querying per cell.

## Measurements (local, 2026-09-12, week 232 shape: 14 games, 118 players)

Wall time of the PHP page (CLI harness, excluding Apache), and Eloquent query count.

| Scenario | Before | After, cache miss | After, cache hit | Queries before → after |
|---|---|---|---|---|
| 14 undecided games | 8.4 s | 0.26 s (0.19 s of it the enumeration) | 0.05 s | 421 → 96 |
| 4 undecided games | 0.20 s | 0.09 s | 0.06 s | 411 → 96 |
| 0 undecided games | 0.21 s | 0.07 s | 0.05 s | 531 → 96 |

Admin viewers run about a dozen more queries (admin bar). The remaining ~96 are the nav bars, auth and pool lookups, and the fingerprint queries (two at the time; three since the format-payouts hash was added on 2026-09-25); SQL time is about 0.02 s.

Through local Apache (curl, 14 undecided games, non-admin viewer): cache miss 0.60 s, cache hit 0.15 s, a new what-if variant 0.25 s. The HTTP output was byte-identical to the CLI harness output for the same scenario.

## Regenerating the local test shape

The local dump (2026-09-07) had picks for only 4 players in week 232. The scratch script used on 2026-09-12 gave every season-18 player a random full pick set locally (`INSERT IGNORE` one bet per player per game, random side, multipliers 10..1 on a random 10 of 14 games) and moved the week's games to yesterday so results are viewable. Re-create it from that description if needed; it never touches production.
