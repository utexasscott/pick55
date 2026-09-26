# Automatic picks at kickoff

**Shape: VERTICAL (process).** How a player who has not finished their picks gets random ones the moment the week's results first exist, replacing the owner's kickoff-time visit to the admin picks page. Owner's request 2026-09-26: "when the first game kicks off each week I go to `admin/weeks/week/picks.php` and randomize all remaining picks. I would like this to instead be done automatically the first time the scores are loaded for the week, so that all picks are randomized before the first time someone sees the scores."

## What happens

The first time the week's results are computed after its first kickoff, every player of the season who is still missing a side on any game of the week is given random picks, exactly as the admin page's "Randomize" did for one player at a time:

- a missing side (`football_bets.option` `'0'`, or no row) becomes `'1'` or `'2'` at random; sides already chosen, including guaranteed `'3'`s, are kept;
- point values already placed are kept; the player's unused values 1..10 go to their unvalued picks, in random game order, until the values run out; the rest stay 0.

A player with a side on every game is not touched, whatever their point values (that is the admin page's definition of "all picks done"). "Player of the season" is a `football_users_seasons` row, paid or not, as the admin page lists them.

The fill is written before `WeekResults` runs, and the results cache is keyed by a fingerprint of the bets ([results-cache.md](results-cache.md)), so the first results anyone sees are of complete picks.

## Where the code lives

| Piece | File |
|---|---|
| `Week::randomizeRemainingPicks()` — the guarded, locked, logged fill; returns the user ids filled | `inc/Pick55/Models/Week.php` |
| `Week::randomizePicksForUser($user_id, $all = false)` — one player's random sides and leftover values (moved here from `User::randomizePicksForWeek()`, which now delegates) | `inc/Pick55/Models/Week.php` |
| `Week::getIncompletePickerIds()` — the season players missing a side, one query | `inc/Pick55/Models/Week.php` |
| `Week::getAutoPickRuns()` — what the fill has done for a week (file cache) | `inc/Pick55/Models/Week.php` |
| `Cache::withLock($name, $fn)` — the per-week file lock (`cache/auto-picks-<week_id>.lock`), also used by `Cache::remember()` since this change | `inc/Pick55/Cache.php` |
| Call site for `r/season/week/results.php`, `r/api/week.php`, Today (`r/index.php`, `r/api/today.php`): `Context::resultsBase()`, once per week per request, before `WeekResults::get()` | `inc/Pick55/R/Context.php` |
| Call site for the classic `season/week/results.php`: after the access checks, before `WeekResults::get()` | `season/week/results.php` |
| The admin page: still the manual fallback; shows the automatic runs for the week | `admin/weeks/week/picks.php` |

## When it runs, and when it never does

`randomizeRemainingPicks()` does nothing unless all of these hold, checked in one aggregate query over the week's games:

1. the week has games and its first kickoff is at or before now (`Week::canSeeResults()`'s rule);
2. the first kickoff is within the last `Week::AUTO_PICKS_WINDOW_DAYS` (7) days;
3. at least one game is still undecided (`correct_option = '0'`).

Then one query finds the incomplete players; when there are none (every call but the first) it returns. Cost on a normal results request: two index-driven queries, in the same class as the cache's three fingerprint queries.

Rule 3 is what protects history. The local database (a production copy) holds finished weeks with a linked player who never picked and was left blank on purpose: season 17 (2025) weeks 2–12 all have one such unpaid player, season 14 weeks 5 and 6 have two and three partial pickers, and 2026 week 1 (`#232`) has one blank player. A finished week has no undecided game, so viewing it never fills anyone. Rule 2 covers a week whose games were never all decided.

Because the fill is "every linked player", the one judgment the owner used to make by hand is gone: an unpaid player who is not really playing now gets random picks every week, as soon as the results are first viewed, and appears in the standings with them. If that is not wanted, the fix is a one-line filter on `paid_at` in `getIncompletePickerIds()` **(owner's call — ask)**.

## Concurrency, failure, record

- Two players loading the results in the same second both see incomplete picks; the second waits on the lock, re-queries, finds nothing missing and does nothing. Without the lock, both would run `Bet::firstOrCreate` for the same rows and one would die on the unique key.
- Any exception inside the fill is caught and written to the PHP error log (`pick55: auto-fill of week #N picks failed: …`); the results page renders with the picks as they are and the next request tries again. The admin page remains as the manual fallback.
- A successful fill logs `pick55: auto-filled the remaining picks of week #N for K player(s): ids…` and appends a run (`at`, `user_ids`) to the file-cache entry `auto-picks-<week_id>`, which the admin picks page prints ("Automatic fills this week"). A cache clear loses the record, not the picks.

## Verified locally 2026-09-26

Against the local `pick` database (week `#230`, first kickoff 2026-09-24 19:15, all 14 games undecided, 51 players of whom 31 were missing a side: 29 with no picks, one with 13 sides, one with 10):

- `#229` (kicks off 2026-10-01) and `#232` (finished, one blank player): `randomizeRemainingPicks()` returned `[]` twice and wrote nothing.
- `#230` by CLI: the first call filled the 31 in 0.29 s, the second returned `[]` in 5 ms. Every one of the 51 players then had 14 sides and each of the values 1..10 exactly once; all 303 pre-existing sides and every pre-existing value were unchanged (compared row by row against a snapshot of the 350 bet rows).
- `#230` through Apache after restoring the snapshot: `r/season/week/results.php?id=230&pool=0` as a planted admin login returned 200 in 2.2 s (the cold `WeekResults` computation plus the fill) with no PHP notice, standings of 51 players, the same 31 filled, the log line and the cache record written. Restored again, the classic `season/week/results.php?id=230&pool=0` did the same. The admin picks page then listed the run.

Nothing was changed on production. No schema change: deploying is the push and deploy from [deploy.md](deploy.md); the `cache/` directory the results cache already uses holds the lock and the record.
