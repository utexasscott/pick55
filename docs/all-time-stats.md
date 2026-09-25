# All-time stats pages — `stats/`

**Shape: VERTICAL (page).** The Record Book, Wall of Fame, Wall of Shame, Leaderboards and Best Seasons pages: what they show, the rules behind every number, where the computation lives, how it is cached, and how the tables sort and filter. Built 2026-09-25 from the owner's list of ideas.

## The pages

All five require a login (`Auth::guard()`), nothing more, so every player sees every season. They share a tab bar (`Page::renderStatsBar()`, `options['stats_bar']`) and a "Stats" button in the header next to Standings.

| Page | Shows |
|---|---|
| `stats/index.php` Record Book | Totals tiles (seasons, players, player-weeks, picks, paid out, perfect weeks, zero weeks); a histogram of every weekly score ever (gold and red bars for the walls); the record book (weeks, seasons, all-time, money); a season-by-season table with each regular-season leader, champion and payout |
| `stats/fame.php` Wall of Fame | A plaque per perfect (55-point) week; an Honor Roll table of 50–54 point weeks; "Wall Regulars", the players with the most 50+ weeks |
| `stats/shame.php` Wall of Shame | A plaque per zero-point week; a Single Digits table of 1–9 point weeks; the players with the most single-digit weeks |
| `stats/leaderboard.php` Leaderboards | Category-leader tiles (best and worst), then three sortable tables over every player: Money & Points; Over/Under vs Spread and NFL vs NCAA; Weeks & Records (weeks won, last places, titles, top-3 seasons, perfect / 50+ / zero / single-digit weeks, best and worst week) |
| `stats/seasons.php` Best Seasons | Tiles for the best and worst seasons, then one sortable table of every player-season in a full season |

`stats/_shared.php` does the common setup (auth, `AllTimeStats::get()`, player names, the viewer's season ids for links) and holds the formatting helpers (`stats_name`, `stats_week_label`, `stats_place`, `stats_tile`, `stats_plaque`, `stats_extreme`, ...). Money is formatted by `Pick55\Snippets\Money` (whole dollars, `-$120`, `+$90`).

## The rules (owner, 2026-09-25, and the choices made to implement them)

- **A week counts once every game in it is decided.** The week in progress is invisible everywhere until its last game is final, so a zero with a Monday game pending is not a goose egg yet.
- **A player-week counts when the player made at least one pick** (a bet with option 1, 2 or 3). Players who never picked in a week simply do not appear in it.
- **Guaranteed picks are not picks.** Option `3` bets (the Semifinals guarantee) are excluded from every pick record, pick percentage, points total and points percentage. A week in which a player held any guaranteed pick is also excluded from both walls and from best/worst week, because the walls are about real picks. This is the owner's "excluding weeks with guaranteed points", applied per player rather than per week so that a Semifinals player *without* a guarantee still gets credit for a real 55.
- **Wall of Fame** = 55 or more real points in a week. Honor Roll = 50–54. **Wall of Shame** = 0 real points with picks made. Single Digits = 1–9. (Measured 2026-09-25: 5 perfect weeks, 53 honor-roll weeks, 4 zeros, 257 single digits in 5,275 real player-weeks.)
- **Week rank** is the results page's order (points, then number correct, then the bit-mask of multipliers), competition-ranked, over the players who picked that week. "Won the week" and "last place" counts use regular-season weeks only and count ties.
- **Titles** = the biggest payout of a paid playoff week (the Finals). The Semifinals pay nothing, so they never count. `seasons[id]['champion_user_id']` is the same lookup.
- **Season finish** is the standings page's order (regular-season points, then correct) among the season's roster (paid players while the season is active, everyone linked after), the same rule `SeasonHistory` uses. Top-3 Seasons counts finishes of 1st–3rd in full seasons.
- **Entry fees and net.** A finished season charges `football_seasons.fee` to every linked player. The active season charges `(fee − 20) / num_weeks` per completed regular week plus `$20` once the season's last week is complete (owner: "$10 per week and $20 to the finals"). Net = winnings − fees. Sanity check 2026-09-25: fees $54,080 vs paid out $54,025 over all time. The 2010 season's stored fee ($120 × 6 players = $720) is below what it paid out ($780), so 2010 nets are slightly generous; correct `football_seasons.fee` for id 1 if that matters.
- **Full seasons** (Best Seasons page and the season records) are seasons with `num_weeks = 10` whose regular weeks are worth 55 points, i.e. everything from 2011 on except the 2024 College Football Playoffs mini-season; 2010 scored 13 a week. A season appears once its regular season is over. The page's default filter shows only players who picked all 10 weeks; "everyone" includes partial seasons.
- **Qualification for percentage records** (record book, leader tiles): `STATS_MIN_WEEKS = 24` weeks, about two seasons. The leaderboard's "Played at least" select changes the tables' filter (1 / 12 / 24 / 48 / 96 weeks); the tiles stay at 24.
- Player names are `User::getDisplayName()` (first name plus two letters of the last), the same as every other page, so two players can share a display name.

## Computation and cache

`Pick55\AllTimeStats::compute()` makes one pass: seasons; weeks joined to their format and game counts; winners grouped by week and player; the roster links; then **one aggregate query over `football_bets` × `football_games`** grouped by (week, player) with the right / wrong / points / possible sums for all picks and for each of the over-under, spread, NFL and NCAA splits (about 5,500 rows). Ranks per week, per-player and per-player-season aggregates, the walls and the score distribution are then built in PHP. Measured locally 2026-09-25: 0.47 s uncached, 590 KB serialized.

`AllTimeStats::get()` caches that result in `Pick55\Cache` (see [results-cache.md](results-cache.md) for the store) under the key `alltime-<fingerprint>`, where the fingerprint is count + `BIT_XOR` + `SUM` of a `CRC32` per row over seven tables (`football_bets`, `football_games`, `football_weeks`, `football_week_formats`, `football_seasons`, `football_week_winners`, `football_users_seasons`), about 0.05 s. Any score, pick, winner, roster or format change produces a new key on the next view and the old entry is swept. A page view from cache renders in about 0.1 s. **Bump the `'v2'` tag in `fingerprint()` whenever `compute()`'s output shape changes**, or a stale-shaped entry will be served (this bit once during development).

`compute()` returns `seasons`, `weeks`, `totals`, `distribution`, `fame`, `honor`, `shame`, `dishonor`, `players` (keyed by user id) and `player_seasons`; the docblock lists the fields. Player names are *not* in the cache: the pages load `er_users` rows per request.

## Sorting, ranking and filtering (`static/js/global.js`)

Tables with class `table-ranked` get:

- a `.rank-cell` first column renumbered top to bottom over the visible rows after every sort (`aftertablesort` from stupidtable) and filter, with gold / silver / bronze on the first three, and hand-made striping (`row-odd`) because `nth-child` cannot skip hidden rows;
- `data-limit="25"` on the table hides rows past 25 until a `[data-show-all]` button in the same card is clicked;
- `[data-sort-toggle="best|worst"]` buttons re-sort every ranked table on the page by its currently sorted column (or the `th[data-sort-primary]`) in the direction that means best or worst for that column, `th[data-lower-is-better]` inverting it, and swap the `.tiles-best` / `.tiles-worst` tile rows. This is the owner's "worst is just another sort";
- `[data-min-filter="weeks"]` selects hide rows whose `data-weeks` is below the chosen value.

`th[data-sort-onload="yes"]` sorts the primary column on load; because stupidtable reverses the whole list for a descending sort, ties would come out in reverse, so the primary columns carry `data-sort-multicolumn="<id of the tie-break th>"` (a string id, not a numeric index: jQuery would turn `"3"` into a number and stupidtable would fail to split it). The two-row-header pick-type table has no tie-break because stupidtable's id lookup counts every `th` in the table.

Verified 2026-09-25 by a CLI harness rendering each page as a player (no PHP notices, 0.1 s per page from cache) and by headless Edge screenshots of the rendered pages against local Apache assets (tiles, plaques, medals, striping and the on-load sort all showed). The toggle and filter handlers were checked by reading, not by clicking.
