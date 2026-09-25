# Week results page and its cache

**Shape: VERTICAL (page).** How `season/week/results.php` computes scores, ranks and win probabilities, why it was slow, and how the cache in `Pick55\WeekResults` keeps it fast.

## What the page computes

For one week and one set of players (every season player, or the members of one pool when a pool is selected), the page shows:

- each player's points, correct count and competition rank from the decided games (`football_games.correct_option` is `1` or `2`);
- for every still-undecided game, the "what if" enumeration: every one of the 2^n possible outcomes is scored and ranked, and each player's share of outcomes that leave them in 1st .. N place (and, when the format has a point threshold, at or above it) is shown as a percentage. N and the threshold come from the week's format via `Week::getNumWinners($pool_num)` / `Week::getMinScoreThreshold($pool_num)`, where `$pool_num` is the selected pool's `pool_num` (null for the overall view), so a format whose pool rows differ from its overall rows gives different columns per pool;
- the per-game cells listing who picked which side at which multiplier.

The enumeration is the cost. Measured locally on 2026-09-12 with the week 232 shape (14 games, 118 players): with all 14 games undecided the old page took 8.4 s of CPU, of which SQL was 0.1 s across 421 queries; with 4 undecided it took 0.2 s. The production droplet is slower than the workstation, so the live page took correspondingly longer on a Saturday morning before any score was entered.

## Where the code lives

| Piece | File |
|---|---|
| Page (auth, pool selection, what-if parsing, winnings form, HTML) | `season/week/results.php` |
| Calculation and cache lookup | `inc/Pick55/WeekResults.php` (`get()`, `compute()`, `fingerprint()`) |
| File cache primitive | `inc/Pick55/Cache.php` |
| Per-game pick cells | `inc/Pick55/Snippets/GameOptionCell.php` (accepts preloaded `players` and `picks`) |

`WeekResults::get($week, $focus_user_ids, $what_ifs_by_game_id, $pool_num)` returns one array: the games (attribute arrays, kickoff order), the focus users' bets grouped by game, the sorted and ranked stats keyed `u<user_id>`, the undecided game ids, and the prediction count. The page hydrates `Game` models from the attribute arrays and adds winnings (edited on the page itself, so kept out of the cache) before rendering.

## How the cache is invalidated: by fingerprint, not by hooks

The owner's requirement (2026-09-12) was "run the queries once each time a score has been updated". Rather than adding an invalidation call to every code path that can change the result (admin game save, the set-game-result buttons, pick saving, randomize picks, guaranteed points, week settings, pool membership, several of which use raw SQL), the cache key itself carries a fingerprint of the inputs:

- `football_games` for the week: count plus `BIT_XOR` and `SUM` of `CRC32(id | correct_option | date | time | title | option_1 | option_2)`;
- `football_bets` on those games: count plus `BIT_XOR` and `SUM` of `CRC32(id | user_id | option | multiplier)`;
- the week's format: `football_weeks.football_week_format_id`, the `$pool_num` being viewed, and the format's `football_week_format_payouts` rows (count plus `BIT_XOR` and `SUM` of `CRC32(id | place_type | pool_num | min_place | max_place | min_points | payout | total_payout)`), which decide the paying places and point threshold. A `'v2'` version tag is hashed in too, so changing what the fingerprint covers invalidates every older entry.

Three aggregate queries, all index-driven, run on every request. Any score update, pick change, format reassignment or payout edit yields a different fingerprint, so the next request misses, recomputes, stores under the new key, and sweeps this week's entries built from older fingerprints. Nothing else in the app knows the cache exists.

Key format: `results-<week_id>-<fingerprint16>-<variant md5>`, where the variant is the sorted focus user ids plus the what-if selection. Every pool view and every what-if combination is its own entry, computed on first view. The winnings form is not part of the key because winnings are added after the cache lookup.

A per-week file lock (`results-<week_id>-lock.lock`) makes concurrent misses wait for one computation instead of all computing at once.

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
