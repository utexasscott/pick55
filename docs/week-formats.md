# Week formats — the rules and payouts of a week

**Shape: VERTICAL (concept + admin page).** Where a week's rules live, what a payout row means, how the historical formats were seeded on 2026-09-25, how the admin creates new ones, and how the change reaches production.

## The model

A week's rules live on its format: `football_weeks.football_week_format_id` → `football_week_formats` → `football_week_format_payouts`. The week itself keeps only `football_season_id`, `week_num`, `football_week_format_id` and `picks_due_date`. The legacy per-week rule columns (`description`, `description_long`, `is_teams`, `num_pools`, `players_per_pool`, `pool_winner`, `weekly_bonus`, `num_winners`, `min_score_threshold`, `is_playoffs`, `advance`) were dropped by [`db/changes/2026-09-25-week-formats-drop-legacy.sql`](../db/changes/2026-09-25-week-formats-drop-legacy.sql).

`football_week_formats` (model `Pick55\Models\WeekFormat`):

| Column | Meaning |
|---|---|
| `num_players` | the league size the format was designed for; the admin list and selects group by it |
| `name`, `description_long` | shown to players (season page, results header, rules page) |
| `num_pools` | NULL/0 = everyone in one group; ≥ 2 = players split into that many pools (`getPlayersPerPool()` = ceil(num_players / num_pools)) |
| `is_teams` | the pools are teams: team rows pay every member (used once, 2017 week 5) |
| `is_playoffs` | playoff week: excluded from the season standings |
| `advance` | knock-out round only: how many players advance to the next round |
| `total_payout` | the money the format pays out; computed by `WeekFormat::computeTotalPayout()` unless the admin overrides it |

`football_week_format_payouts` (model `Pick55\Models\WeekFormatPayout`), one row per paying place range:

| Column | Meaning |
|---|---|
| `place_type` | `overall` (whole field), `pool` (each pool), `team` (each member of the team in that place) |
| `pool_num` | NULL = the row applies to every pool; N = only pool N (`football_pools.pool_num`). The Finals week pays pool 1 "Finalists" and pool 2 "Consolation" from different tables |
| `min_place`, `max_place` | the places that qualify; `max_place` NULL = open-ended |
| `min_points` | anyone with at least this many points also qualifies ("2nd–4th or 41+ points") |
| `payout` | what each qualifying player receives; NULL = the row is a split pot |
| `total_payout` | the split pot, shared equally by everyone who qualifies |

**Payout semantics.** A player receives the single largest payout they qualify for. Ties are competition ranks, and players tied over places r..r+t-1 split the sum of those places' amounts equally (owner, 2026-09-25; before that ties were settled by hand when the winnings were typed in). Overall payouts outrank pool payouts, so the overall winner takes the overall amount and their pool's 1st-place amount goes unpaid. This is how the owner's own 2018 formats (ids 1–6) and every description since 2018 are phrased ("Overall winner wins $150. Other pool winners win $63."). The 2014–2017 descriptions phrase the overall prize as a bonus on top of the pool prize; those formats store the sum the overall winner actually received (2014 "Two Pools": pool $64, overall $80, not $16).

`computeTotalPayout()` therefore adds every overall row (payout × places, or the split pot) and every pool row (× number of pools it applies to, or × players per team for team rows), then subtracts min(overall places, num_pools) × the every-pool 1st-place payout. It assumes the overall winners sit in distinct pools; when that assumption does not hold for a new format, the admin page lets the owner override `total_payout`.

## What the pages read

| Need | Call |
|---|---|
| name / long description of a week | `Week::getName()`, `Week::getDescriptionLong()` |
| number of pools the format wants | `Week::getNumPools()`; `Week::setNumPools()` creates or removes the `football_pools` rows to match |
| paying places and point threshold for the results page | `Week::getNumWinners($pool_num)`, `Week::getMinScoreThreshold($pool_num)`; `$pool_num` is the selected pool's `pool_num`, null for the overall view. With a pool selected the pool rows decide (finals: 15 places in pool 1, 4 in pool 2); otherwise the overall rows do |
| playoff week? | `Week::isPlayoffs()`; `season/standings.php` joins the format for the same test in SQL |
| money per player for a standing (current or hypothetical) | `Pick55\WeekPayouts::tables()` / `assign()` / `assignAll()`; the results page's `payout` / `expected_payout` come from these (see [results-cache.md](results-cache.md), "Money") |
| recording a finished week's winners | `Pick55\WeekPayouts::syncWinners($week, $amounts, $last_game_at)`, called by the results page once every game is decided; `football_week_winners` is no longer typed in by the admin (owner, 2026-09-25) |
| payout table HTML / one-line text | `Pick55\Snippets\WeekFormatPayouts::b($format)` (a horizontal table: group, place, amount rows, one column per payout row, total last; owner, 2026-09-25) / `::b($format, true)` |
| formats for a select | `WeekFormat::getListForSelect($num_players)` puts the matching league size first |

The results cache (see [results-cache.md](results-cache.md)) fingerprints the format id, the selected `pool_num` and a CRC aggregate of the payout rows, so editing a format invalidates the affected weeks' cached results on the next view.

Weeks are still created by hand in the database (there is no create-week page); the admin week page (`admin/weeks/week/index.php`) then assigns the format.

## How the historical formats were seeded (2026-09-25)

Before this change `football_week_formats` held six owner-made rows for 2018 and `football_week_format_id` was set on 80 weeks, but only the ten 2018 values were right; the rest cycled 1–6 as placeholders. The truth for each week was its legacy columns plus its description, and what was actually paid was in `football_week_winners`.

The seed, [`db/changes/2026-09-25-week-formats-seed.sql`](../db/changes/2026-09-25-week-formats-seed.sql), was generated from a hand-written table of every week (scratch script `gen-format-seed.php`, not in the repo; the SQL file is the record). Rules used:

- one format per distinct (season, structure); weeks with identical rules in one season share it;
- `num_players` = the season's `football_users_seasons` count;
- payouts were read from the paid amounts (`football_week_winners`) for finished weeks and from the description for unplayed ones. 2026 week 1 was paid $140/105/80/65/55/45 although its description said $130/90/75/65/55/45; the format records what was paid. 2026 weeks 3–10 follow the descriptions on production as read on 2026-09-25 (week 3 "5 Pools of 10", weeks 4–10 all "Top 8"); the 2026 Finals came from the tables on `rules.php`;
- 2010–2013 weeks with no description became "Winner Takes All"; playoff week 11 became "Semifinals" (`is_playoffs`, `advance` from the description, no payouts) and week 12 "Finals" (two pool-specific tables where the season had a consolation pool). In the 2025 Finals pool 2 was the Finals pool and pool 1 the Consolation pool; the format follows the data;
- the 2018 formats 1–6 were kept; row 12 (the "41 points" split) got its pot, $190.

Cross-check: the computed `total_payout` equals the sum actually paid for every finished week except 2018 week 7 (a tie for 1st was paid $90 + $90 instead of the $130/$21 split, $29 over) and 2019 week 5 (a $250 split pot rounded to $249). Result: 96 formats, 331 payout rows, 197 weeks assigned, none left null.

## Admin pages (Admin > Formats)

`admin/formats/index.php` lists the formats filtered by league size (`?num_players=N`; default the active season's `getNumPlayers()`; `?num_players=all` clears). Each row shows pools or teams, a playoff badge with "top N advance", the one-line payout table, the total, and links to every week using the format. "New format" opens the create page pre-filled with that league size. The admin bar has a "Formats" button; the admin week page links "All formats", "New format" and "Edit this format" from its format select.

`admin/formats/format/index.php` creates (`?num_players=N`) or edits (`?id=N`) one format:

- league size, name, one-line description;
- week type: regular, or playoff with an optional "players advancing" (knock-out round; blank for the money round);
- grouping: one group, pools or teams, with the pool count and a live "N pools of M players" hint;
- an **Overall payouts** table and, when pools are on, a **Pool payouts** table whose rows each apply to "Every pool" or to one specific pool. A row pays places from–to ("and below" makes it open-ended) and/or anyone at or above a points threshold, as a per-player amount or a split pot (whole dollars);
- **Total payout**, kept in sync by JavaScript with a port of `WeekFormat::computeTotalPayout()` plus "≈ $X per player" and a plain-text preview; typing overrides it, "recalculate" restores the formula, blank on save stores the computed value.

Saving validates server-side (name; size ≥ 2; pool count 2..size; places ≥ 1; no overlapping place ranges and at most one open-ended row per group, where a group is the overall rows, the every-pool rows, or one specific pool's rows; exactly one of per-player / split pot per row; advancing 1..size−1) and rewrites the payout rows (insert the new ones, then delete the old by id; the tables are MyISAM so the transaction is a formality). A validation error redirects back with the submitted form restored from `$_SESSION['pick55']['format_form']`, the way `Alert` carries flash messages. **Save as new format** inserts a copy from the submitted values; **Delete** is offered only when no week uses the format. **A format is locked once a `football_week_winners` row exists for any week that uses it** (owner, 2026-09-25): the page shows an info banner naming those weeks, renders the fields in a disabled fieldset, hides Save, and the server refuses a `save` post; Save as new still works (its click handler re-enables the fieldset so the values submit), so the path for new rules is a copy assigned to the upcoming week.

POST field names: `action` (`save` | `save-as-new` | `delete`), `num_players`, `name`, `description_long`, `is_playoffs`, `advance`, `grouping` (`one` | `pools` | `teams`), `num_pools`, `total_payout`, and rows as `overall[i][…]` / `pool[i][…]` with `min_place`, `max_place`, `open`, `min_points`, `mode` (`per` | `split`), `payout`, `total_payout`, plus `pool_num` on pool rows. `place_type` is derived on the server (`team` when grouping is teams). Verified 2026-09-25 by CLI harness (create, edit with a threshold row, save-as-new with an override total, overlap rejection with form restore, delete guard); the browser-side toggles were checked by parsing and by running the pure JS functions, not by clicking.

## Applying the change to production

*Concept key: `LIVE_WRITE_GATE`.* Three files, in order, all under `db/changes/`. Claude's MySQL account has no DDL, so steps 1 and 3 are run by the owner as root on the droplet; step 2 is plain DML that Claude runs after a go, or the owner runs the same way.

1. `2026-09-25-week-formats-schema.sql` — adds the columns (safe before deploying the code; the old code ignores them).
2. `2026-09-25-week-formats-seed.sql` — deletes any format with id ≥ 7, inserts the 90 formats and 331 rows, assigns every week. It was checked against production on 2026-09-25: a CRC over every pre-2026 week's legacy columns and over every pre-2026 winner row matched the local dump exactly, formats 1–6 and their 14 rows are identical, and the 2026 weeks were re-read from production (the owner had changed weeks 4–10 since the dump). **If any week or winner row changes on production before the go, re-check.** The file is idempotent (delete then insert).
3. Deploy the code (push), then `2026-09-25-week-formats-drop-legacy.sql`. The code never reads the dropped columns, so this can wait; until it runs the columns are simply dead.

Owner-side, PowerShell:

```powershell
scp C:\wamp\www\pick55\db\changes\2026-09-25-week-formats-schema.sql root@143.198.236.171:/root/
ssh -t root@143.198.236.171 "mysql -u root -p pick < /root/2026-09-25-week-formats-schema.sql"
```

and the same for the other two files. Afterwards re-snapshot `db/schema.sql` (see [database-access.md](database-access.md)).
