# Live scores — ESPN scoreboard polling

**Shape: VERTICAL (service).** Pulls live and final scores from ESPN for the games of the week, stores them per `football_games` row, marks a game's result when it completes, and feeds the results page's live display. Owner's request 2026-09-25: "show live scores on this page, and mark games as they happen. Scrape ESPN every 10 minutes while there is a game that has started and not yet completed."

Built as a cron job that fires every 10 minutes and exits in milliseconds when nothing is open (no daemon to supervise; a crash cannot kill it), the same shape as `scrape/run.php` ([odds-scraper.md](odds-scraper.md)).

## What exists (2026-09-25)

| Piece | File | Does |
|---|---|---|
| ESPN client | `inc/Pick55/Espn.php` | Fetch (Guzzle, browser User-Agent, 20 s timeout, two hosts tried in order), parse a scoreboard's events into plain arrays, match a `football_games` row to an event, learn ESPN team ids, log line. One instance per run; a league+day board is fetched once. |
| Score model | `inc/Pick55/Models/GameScore.php` | `football_game_scores` row. `getLeadingOption()`, `getStatusLabel()`, `isLive()`, `isFinal()`, `hasScore()`, static `forWeek($week_id)`, and the result rule as statics (`optionFor`, `sidesOf`, `flippedByText`, `optionLabel`, `normalize`). |
| Team-id model | `inc/Pick55/Models/EspnTeam.php` | `football_espn_teams` row; static `idsForTeams(array)`. |
| `Game::score()` | `inc/Pick55/Models/Game.php` | `hasOne(GameScore)`. |
| Cron CLI | `scrape/live-scores.php` | The scheduled entry point; flags below. |
| JSON endpoint | `season/week/live.php?id=WEEK_ID` | What the results page polls; contract below. |
| Schema | `db/changes/2026-09-25-live-scores.sql` (two `CREATE TABLE IF NOT EXISTS`, rollback `DROP`s in the header), `db/changes/2026-09-25-live-scores-grants.sql` (production only: grants Claude's per-table MySQL account on the new tables) | Applied locally 2026-09-25; `db/schema.sql` re-snapshotted. Production: **not yet applied**, see "Applying to production". |

## ESPN — measured 2026-09-25

Public scoreboard JSON, no key, past dates work:

- NFL: `https://site.web.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard?dates=YYYYMMDD`
- NCAA FBS: `https://site.web.api.espn.com/apis/site/v2/sports/football/college-football/scoreboard?dates=YYYYMMDD&groups=80&limit=300` (without `groups=80` the default page is a short top-games list).

**Host:** `site.api.espn.com` (the host in the original brief) answered HTTP 403 "Access Denied" (Akamai reference error 18) to curl, PHP/Guzzle and PowerShell from this workstation and to Anthropic's fetcher alike on 2026-09-25, with or without a browser User-Agent; `site.web.api.espn.com` serves the identical path and answered 200 with no special headers. `Espn::HOSTS` lists the web host first and the api host second; a fetch tries them in order and throws only when every host fails. The droplet's own reach is **(unconfirmed — ask)** until the first `--week=231 --dry-run` there.

**Day bucket:** `dates=` is the US **Eastern** day, not UTC and not Central: the `20260920` NFL board held the Sunday-night game (`2026-09-21T00:20Z`, 7:20 PM Central) and the `20260919` NCAA board held the 10 PM Central games (`2026-09-20T03:00Z`). `Espn::datesFor(Game)` fetches the game's Central date and adds the next day when the kickoff is at or after 23:00 Central. A game is never matched by bucket, only by teams and kickoff proximity.

**Event shape** (what `Espn::parseEvents` keeps): `id` (e.g. `401856688`), `date` (UTC, `2026-09-19T23:30Z`), `name` ("LSU Tigers at Ole Miss Rebels"), `status.period`, `status.displayClock`, `status.type.{state: pre|in|post, completed: bool, name: STATUS_SCHEDULED|STATUS_IN_PROGRESS|STATUS_HALFTIME|STATUS_END_PERIOD|STATUS_FINAL|…, detail, shortDetail}`, and `competitions[0].competitors[]` with `homeAway`, `score` (a string, `"0"` before kickoff), `winner` (absent before the end) and `team.{id, location: "Ole Miss", name: "Rebels", abbreviation, displayName: "Ole Miss Rebels"}`. Measured `shortDetail` values: `Final`, `Final/OT`, `9/27 - 1:00 PM EDT` (pre). In-progress values (`4:12 - 3rd`, `Halftime`, `End of 1st`) are from ESPN's documented behaviour, not yet observed by this code **(unconfirmed until the first live Saturday)**.

Board sizes seen: NFL Sunday 14 events (13 + Sunday night), NFL Monday 1, NFL Friday/Saturday 0, NCAA FBS Saturday 71 (9/19) and 80 (9/12).

## Tables

`football_game_scores` — one row per `football_games` row (so a real-world game backing a spread row and an over-under row has two score rows with the same `espn_event_id`):

| Column | Meaning |
|---|---|
| `football_game_id` PK | the `football_games` row |
| `espn_event_id` varchar(20) | matched event; reused first on later runs |
| `away_score`, `home_score` smallint NULL | null before kickoff; **our** away/home team's score even when ESPN lists the sides the other way round |
| `state` enum pre/in/post, `completed` tinyint | ESPN `status.type.state` / `.completed`; final = post + completed; postponed/cancelled is post + not completed |
| `period` tinyint NULL, `clock` varchar(10) NULL, `detail` varchar(60) NULL | `status.period`, `displayClock`, `type.shortDetail` |
| `fetched_at` datetime | last time the cron looked |
| `changed_at` datetime NULL | last time score or state changed |
| `result_set_at` datetime NULL | when this cron set `football_games.correct_option` (null when the result was already set by hand) |

Index `(state, completed)` for the open-game query. MyISAM, utf8_unicode_ci, like the rest.

`football_espn_teams` — `football_team_id` PK, `espn_team_id` varchar(20), `espn_display_name`, `matched_at`, `matched_by` (`name` | `slug` | `manual`). Learned automatically on the first successful name match; a team is matched by name only once. `football_teams` is not altered.

## Matching a `football_games` row to an event (`Espn::match`)

Candidates are the events on the boards fetched for the game's league. Rules, in order:

1. **Stored event id.** A score row's `espn_event_id` is looked up directly (any kickoff distance). Sides are oriented by learned team ids, else by name, else ESPN's `homeAway`.
2. **Learned team ids.** Both teams in `football_espn_teams`: the one event within **±4 hours** of the game's Central `date`+`time` whose two competitors carry those ids, in either orientation. More than one such event is reported, never guessed.
3. **Names**, within the same ±4 h window, at the best level where exactly one event has both teams matching (either orientation). Levels, after normalizing (lowercase; á é í ó ú ñ folded; `&` → `and`; everything but a-z0-9 dropped, so "Texas A&M" = "texasandm", "Miami (OH)" = "miamioh", "San José State" = "sanjosestate"):
   1. ESPN `location + name` (or `displayName`) equals `team + nickname`, **or** the alias for the team's `vegas_insider_url` slug equals ESPN `location`;
   2. ESPN `location` equals `team`;
   3. the slug with hyphens removed equals ESPN `location` (`nc-state` → "NC State", `uconn` → "UConn");
   4. ESPN `name` equals `nickname` only.
   A game matched by name learns both teams' ESPN ids (`matched_by` = `slug` for level 3, else `name`), unless `--dry-run`.

Unmatched games are logged once per run as `UNMATCHED #id title (...)`: with no event in the window, the window and board size; with events but no name match, the four closest events by kickoff (`closest: Atlanta Falcons at Green Bay Packers (19:15)`); with several equal matches, their names. Nothing is written for an unmatched game and it stays open until the 8-hour window closes.

Alias map (`Espn::getAliases`, keyed by slug): `appalachian-state` → App State, `louisiana-monroe` → UL Monroe, `umass` → Massachusetts, `uconn` → UConn, `miami-fl` → Miami, `san-jose-state` → San José State, `hawaii` → Hawai'i, `nc-state` → NC State, `pittsburgh` → Pittsburgh, `southern-miss` → Southern Miss, `sam-houston` → Sam Houston, `texas-am` → Texas A&M, `ole-miss` → Ole Miss, `liu` → Long Island University. Most are belt-and-braces (normalization or the slug rule already covers them); App State, UL Monroe, Massachusetts and Long Island University are the ones that need it. On the 2026-09-19 board every FBS school in `football_teams` with a game that day matched; "Arizona State Sun devils" (typo in the DB) matches because normalization is case-insensitive.

## The result rule and the sign convention

`football_games.value` on a **spread** row is stored against the **away** team: negative means the away team is favored (LSU @ Ole Miss, value −3.5, options "LSU (-3.5)" / "Ole Miss (+3.5)"; Kentucky @ Texas A&M, value 16.5, "Kentucky (+16.5)" / "Texas A&M (-16.5)"). So:

- spread: `away_score + value − home_score` > 0 → the **away** side wins; < 0 → the **home** side; = 0 → nobody (cannot happen with a `.5` line, *Concept key: `HALF_POINT_LINES`*, but handled: the game stays undecided).
- over-under: `away_score + home_score` > `value` → OVER; < → UNDER; = → nobody.

Which option number is the away (or OVER) side comes from the **option text** (`GameScore::flippedByText`): the option whose label (text before the parenthesized line) equals the team's `team`, `nickname` or both is that team's side; for totals the option containing OVER/UNDER. That is cross-checked against `options_flipped` (1 on 20 legacy 2010 rows where option_1 is the home side, e.g. #225 "Oklahoma@Texas": option_1 "Texas (+10.5)", value −10.5). If the text and the column disagree the cron logs `SIDES DISAGREE …` and does not write `correct_option`; `getLeadingOption()` returns null for that game. If the text names neither team (old free-text rows) the column is trusted.

Verified 2026-09-25 locally against ESPN's past boards (`--week=231 --dry-run`, `--week=232 --dry-run`): **28 of 28** games matched an event (26 by name, 2 by learned ids inside the same run for the second row of a doubled game) and the derived option equalled the stored `correct_option` for **28 of 28** (7 spreads and 7 totals per week, favorites and underdogs on both sides, one Final/OT). Then `--week=231` for real wrote 14 `football_game_scores` rows, all `post`/completed with scores, and 26 `football_espn_teams` rows; a second `--week=231` run matched all 14 by stored event id and changed nothing.

## The open-game condition and the CLI

An **open** game is a `football_games` row whose Central kickoff (`date` + `time`) is at or before now, **within the last 8 hours**, and whose score row is missing or not (`state = 'post' AND completed = 1`). The 8-hour window is what stops an unmatched, postponed or never-completed game from being polled forever. Open games are grouped by league and Central date, each needed scoreboard is fetched once, and every open game is matched and written.

`scrape/live-scores.php`:

| Invocation | Does |
|---|---|
| (none) | The cron run. No open game → exit 0 with no output (measured locally 2026-09-25: 0.31 s wall, all of it PHP start-up and one query). Otherwise one log line per fetched board (`NFL 20260920: 14 events`), per learned team id, per game whose score or state changed (`#2389 … 24-32 by name [new]`, later `[away_score 17->24, state in->post]`), per result set, per unmatched game. Exit 1 if any board fetch failed (the games needing it are skipped and stay open). |
| `--check` | Prints the open games (with their current score row) and the boards it would fetch, fetches nothing. |
| `--force` | Skips the open check: every started game of the current week (the week holding the latest kicked-off game), regardless of the 8-hour window or completion. |
| `--week=N` | Every game of that week regardless of kickoff: how a past week is verified and how a missed final is backfilled. Games not yet kicked off are written as `pre` rows. Ends with a summary line (`week #231: 14 games, 14 matched, 0 unmatched, 14 stored results agree, 0 results set`). |
| `--week=N --dry-run` | As above but writes nothing; per game prints the matched event, score, derived option and whether it equals the stored `correct_option` (`agrees` / `would set` / `DIFFERS`). |

Setting a result: when an event is `post` + completed and the derived option is not null, the cron sets `football_games.correct_option` **only if it is still `'0'`** (an `UPDATE … WHERE correct_option = '0'`) and stamps `result_set_at`. A stored non-zero value that differs from the derived one is logged (`stored correct_option=1 differs from derived 2 …; not overwriting`) and left alone, so a hand-set result always wins.

The results page cache ([results-cache.md](results-cache.md)) is keyed by a fingerprint that includes `correct_option`, so a result set here invalidates it with no hook. Scores are not in the fingerprint and do not need to be: the page reads `football_game_scores` outside the cache.

The 2026-09-25 local dry run of week 230 also showed the unmatched path: local game #2412 "Philadelphia Eagles @ Chicago Bears" dated Thursday 2026-09-24 19:15 has no ESPN counterpart (ESPN's 9/24 board holds only Falcons at Packers, its 9/25 board is empty), so it logs `UNMATCHED … closest: Atlanta Falcons at Green Bay Packers (19:15)`. That row needs the owner's eye; the code did the right thing.

## JSON endpoint — `season/week/live.php?id=WEEK_ID`

Same access rules as `season/week/results.php` (logged in, a player of the week's season, results visible), but errors are JSON with a status instead of a redirect so the page's XHR can tell them apart: `401 {"error":"Not logged in."}`, `404 {"error":"Invalid week."}`, `403 {"error":"Unauthorized."}` / `{"error":"Results are not available yet."}`. Success, `Content-Type: application/json`:

```json
{
  "week_id": 231,
  "fetched_at": "2026-09-25 18:42:19",
  "any_live": false,
  "games": {
    "2389": {"away_score": 24, "home_score": 32, "state": "post", "completed": true,
             "label": "Final", "leading_option": "2", "correct_option": "2"},
    "2412": {"away_score": null, "home_score": null, "state": "pre", "completed": false,
             "label": "", "leading_option": null, "correct_option": "0"}
  }
}
```

One entry per `football_games` row of the week in kickoff order; a game without a score row yet is the `pre` shape. `fetched_at` is the newest `fetched_at` of the week's score rows (null when none), `any_live` is true while any game is `in`. `label` is `GameScore::getStatusLabel()`: `''` before kickoff; `Q1`..`Q4`/`OT`/`2OT` + clock, `Half`, `End Q1` while live; `Final` / `Final/OT` when done; ESPN's own short detail for postponed/delayed. `leading_option` is `getLeadingOption()`: which side the current score favors under the result rule (null before kickoff, on a met line, or when the sides are undecidable), and equals `correct_option` once final.

Verified 2026-09-25 through local Apache with a planted player session: week 231 returned 200 and the 14 rows above; no session returned 401 JSON; an unknown week 404 JSON. The CLI harness for the same script took 0.036 s.

## Applying to production

Order matters (*Concept key: `ORDERED_HANDOVER`*): the tables first (the code queries them on every run), then the deploy, then the cron. Owner, PowerShell:

1. Copy the two SQL files to the droplet:
   ```powershell
   scp C:\wamp\www\pick55\db\changes\2026-09-25-live-scores.sql C:\wamp\www\pick55\db\changes\2026-09-25-live-scores-grants.sql root@143.198.236.171:/root/
   ```
2. Create the tables (prompts for the MySQL root password; *Concept key: `LIVE_WRITE_GATE`*, this is the shown change), then grant Claude's account on them:
   ```powershell
   ssh -t root@143.198.236.171 "mysql -u root -p pick < /root/2026-09-25-live-scores.sql"
   ssh -t root@143.198.236.171 "mysql -u root -p pick < /root/2026-09-25-live-scores-grants.sql"
   ```
3. Push and deploy ([deploy.md](deploy.md)).
4. Install the cron (below), then verify.

Whether the app's own MySQL account in the droplet's `inc/_config.php` is granted database-wide or per table is **(unconfirmed — ask)**; if per table, the cron's `--check` in the verify step fails with a permission error on `football_game_scores` and that account needs the same two grants.

## The cron

Every 10 minutes, `beanstalk`'s crontab, `/usr/bin/php` (8.0; the same binary and log directory as the odds scraper, see [odds-scraper.md](odds-scraper.md) "The cron"):

```
*/10 * * * * /usr/bin/php /home/beanstalk/pick55/scrape/live-scores.php >> /home/beanstalk/logs/scrape/live-scores.log 2>&1
```

Install idempotently (replaces any earlier `live-scores.php` line, keeps everything else), after the tables exist and the deploy has put `scrape/live-scores.php` on the box:

```powershell
ssh root@143.198.236.171 "(crontab -u beanstalk -l 2>/dev/null | grep -v 'pick55/scrape/live-scores.php'; echo '*/10 * * * * /usr/bin/php /home/beanstalk/pick55/scrape/live-scores.php >> /home/beanstalk/logs/scrape/live-scores.log 2>&1') | crontab -u beanstalk -"
```

Verify the crontab and run the open check as `beanstalk` under the droplet's PHP 8.0:

```powershell
ssh root@143.198.236.171 "crontab -u beanstalk -l; su - beanstalk -c '/usr/bin/php /home/beanstalk/pick55/scrape/live-scores.php --check'"
```

Then prove ESPN is reachable from the droplet and populate last week's scores (reads ESPN, writes the two new tables, sets no result because week 231's are already set):

```powershell
ssh root@143.198.236.171 "su - beanstalk -c '/usr/bin/php /home/beanstalk/pick55/scrape/live-scores.php --week=231 --dry-run'"
ssh root@143.198.236.171 "su - beanstalk -c '/usr/bin/php /home/beanstalk/pick55/scrape/live-scores.php --week=231'"
```

The log is quiet all week and gains a handful of lines per 10 minutes while games are on. Claude can read it over `ssh pick55` only if `beanstalk` makes `/home/beanstalk/logs/scrape/` readable **(unconfirmed — ask)**.
