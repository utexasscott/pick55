# Odds scraper — VegasInsider game lines

**Shape: VERTICAL (service).** Pulls the week's NFL and NCAA games with consensus spreads and totals so the admin does not key games in by hand.

## What exists (2026-09-25)

| Piece | File | Does |
|---|---|---|
| Scraper class | `inc/Pick55/VegasInsider.php` | Everything: URLs, fetch (Guzzle, browser User-Agent), raw-file storage, parser, newest-scrape lookup, log line. Autoloaded like any `Pick55\` class, so the admin page and the CLIs share it. |
| Fetch CLI | `scrape/get-raw.php <nfl\|ncaa>` | Fetches one league page and stores it as `scrape/raw/vegas-insider/<league>/<Y-m-d-H-i-s>.html`. Paths are anchored on `__DIR__` (the 2021 version used the working directory, so a cron run from `$HOME` wrote there). |
| Parse CLI | `scrape/parse-raw.php [--all] [file.html]` | Parses stored pages into sibling `.json`. No arguments: every `.html` without a `.json`. `--all`: re-parse everything. A file argument: that file, always re-parsed. |
| Cron CLI | `scrape/run.php [--check\|--force]` | The scheduled entry point. Queries `football_weeks` for a row with `picks_due_date` after now and less than 7 days ahead; with none it exits 0 silently. With one it fetches and parses both leagues and prints one timestamped log line per league (`week #229 (season 18 week 4, picks due …): NFL 15 games -> …json`). A failed league is logged as `FAILED` and sets exit status 1; the other league still runs. `--check` prints the decision and fetches nothing; `--force` skips the week check. Verified 2026-09-25 locally under PHP 7.4 (fetch, parse, log lines). |
| Admin page | `admin/weeks/week/bulk-games.php?week_id=N` ("Games from Scrape", linked from the week settings page while the week has no games) | See "The admin page" below. |

The 2019 generation (`scrape/odds/`: Puppeteer page grabber, `table.frodds-data-tbl` parser, a `driver.php` that required a `../common/config.php` that never existed here) was deleted 2026-09-25. It is in git history before that date if ever needed.

`scrape/raw/` is git-ignored. On the droplet it holds files stamped daily at 02:00 through 2022-03-25 from the old cron (measured 2026-09-24), carried over by the git cutover.

Old cron history: a `beanstalk` cron ran `get-raw.php` daily at 02:00 in March 2022 and logged to `/home/beanstalk/logs/scrape/` (last written 2022-03-25; an empty `parse.log` from 2023-04-05). It has been dead since 2022–2023.

## The page — measured 2026-09-07, re-read 2026-09-25

- URLs: `https://www.vegasinsider.com/nfl/odds/las-vegas/` and `/college-football/odds/las-vegas/`. Both return 200 to a plain GET with a browser User-Agent (the class sends a Chrome one). Server-rendered HTML, about 0.9 MB (NFL) and 3.2 MB (NCAA) on 2026-09-25. **No headless browser needed.**
- One `<table class="odds-table">` per page. `<thead>` row 1: `th.game-legend` ("Time") first, then one `th.book-pinup` per sportsbook with the name in `<span class="hidden">`, then a blank `th.book-pinup`. Book order differs per page (NFL on 2026-09-25: Open, Bet365, BetMGM, DraftKings, Caesars, FanDuel, Fanatics, RiversCasino, **Consensus**; NCAA also has HardRock). The parser finds `Consensus` by header text and counts the non-`book-pinup` cells before it to line the index up with `td.game-odds` cells in team rows. Never hardcode a column index.
- Three `<tbody>` groups, keyed by class: `odds-table-spread--0`, `odds-table-total--0`, `odds-table-moneyline--0`. Spread and total are what Pick55 uses.
- Inside a tbody each game is a run of four rows: a header row with `td.game-time` holding `<span data-role="localtime" data-value="2026-09-27T17:00:00Z">` (**kickoff in UTC**), then the away team row, the home team row, and a spacer row with no team link. A game already played shows `<span>Final</span>` with no `data-value`; the parser skips those (one on the 2026-09-25 NCAA page).
- Team rows: `td.game-team` holds `<img alt="Army Black Knights">` (full name) and `<a href="/college-football/teams/army/" data-abbr="ARMY">Army</a>`; the URL slug is the stable key. Then one `td.game-odds` per book with `<span class="data-value">-3.5</span>` and `<small class="data-odds">-110</small>`, plus a trailing `td.game-odds.blank`. In the totals tbody the value reads `o48.5` / `u48.5`. A book with no line shows `N/A`.
- Oddities seen: `--4.5` (double minus) in a HardRock cell on 2026-09-07. The parser reduces any run of signs to one sign and takes the first number.

## Parser rules (`VegasInsider::parse`)

- Uses the **Consensus** column only.
- Kickoff is converted from the page's UTC to `America/Chicago` (the app's timezone; `football_games.date`/`time` are Central wall-clock). Verified 2026-09-25: `2026-09-27T17:00:00Z` → `2026-09-27 12:00:00`.
- Spread is stored **against the away team** (negative = away favored), which is the app's `football_games.value` convention and exactly what the away row's Consensus cell reads. If the away cell is empty the home cell is negated.
- *Concept key: `HALF_POINT_LINES`.* Whole-number lines get half a point **added to their magnitude** so no pick can push: spread `-4` → `-4.5`, `+4` → `+4.5`, total `49` → `49.5`. `PK`/`EVEN` → `0.5`. Lines already ending in `.5` are unchanged.
- Games are keyed by away slug + home slug + UTC kickoff so the spread and total sections merge into one entry. Output is sorted by kickoff.

JSON shape, one object per game (the keys `bulk-games.php` consumed before the rewrite are kept; the rest are new):

```json
{
  "date": "2026-09-27", "time": "12:00:00", "kickoff_utc": "2026-09-27T17:00:00Z",
  "away_team": "bengals", "home_team": "steelers",
  "away_name": "Cincinnati Bengals", "home_name": "Pittsburgh Steelers",
  "away_abbr": "CIN", "home_abbr": "PIT",
  "spread": -3.5, "over-under": 42.5
}
```

`spread` or `over-under` is `null` when the Consensus column has no line for it.

Measured 2026-09-25 against fresh fetches: NFL 15 games, NCAA 70 games (71 on the page, one `Final`).

## Team matching — measured 2026-09-25 on the local dump

`football_teams.vegas_insider_url` is the match key (34 NFL + 90 NCAA rows; no schema change). Against the 2026-09-25 pages:

- NFL: 29 of 30 page slugs match; the miss is `commanders` (row 29 still carries `redskins`). The owner fixes it on the team edit page `admin/teams/team/index.php?id=29`.
- NCAA: 57 of 140 page slugs match. Many misses are schools the pool never uses, but these DB rows carry a slug the site no longer uses and will stay unmatched until edited in the admin UI: `texas-austin`→`texas` (id 12), `louisiana-state`→`lsu` (2), `southern-california`→`usc` (13), `texas-christian`→`tcu` (17), `california-los-angeles`→`ucla` (19), `pennsylvania-state`→`penn-state` (73), `wisconsin-madison`→`wisconsin` (94), `mississippi`→`ole-miss` (83), `illinois-urbana`→`illinois` (71), `northwestern-university`→`northwestern` (68), `southern-methodist`→`smu` (121), `north-carolina-state`→`nc-state` (59), `miami`→`miami-fl` (62). Rows 111–126 (UAB, UCF, Texas State, UTSA, Tulane, Colorado State, South Florida, Western Kentucky, Central Arkansas, Nevada, New Mexico, Connecticut, Hawaii, San Jose State) have an empty slug. Unmatched page slugs are surfaced to the admin with the page's team name, never hidden.

**Applied to production by the owner 2026-09-25** (29 `UPDATE`s: the 14 renames above plus `brigham-young`→`byu`, and slugs for the 14 empty rows; `byu` and `san-jose-state` verified by the team page's `<title>`, which is generic for a slug the site does not know while the HTTP status is 200 either way). Verified afterwards over `ssh pick55`. Against the 2026-09-25 pages the match was then NFL 30 of 30 and NCAA 83 of 140; the 57 remaining NCAA page slugs were schools with no `football_teams` row at all.

**57 NCAA teams created on production 2026-09-25** (Claude, on the owner's standing go for team creation given in that turn): one `INSERT` per unmatched slug of the 2026-09-25 16:59 scrape, with the page's school and nickname split as `team`/`nickname`, the slug as `vegas_insider_url`, and colors researched from official brand guides and teamcolorcodes.com (`color_1` primary as background, `color_2` white or a readable secondary as text, `color_3` secondary as border). Applied locally first, then over `ssh pick55`; `football_teams` has 147 NCAA rows on both, and every slug on both 2026-09-25 pages now matches (NFL 30 of 30, NCAA 140 of 140). Note the site's slug for William & Mary is `william--mary` (two hyphens). New teams are still created on `admin/teams/create.php` with the page slug in the Vegas Insider URL field; the slug rename fixes above plus these inserts are the only production writes to the table.

## The admin page — `admin/weeks/week/bulk-games.php`

Rewritten 2026-09-25; the 2021 bulk editor (add/remove/save-all rows, deleting unsubmitted games) is gone. Games are edited on `admin/games/game/index.php` as before.

- Takes `week_id` (or `id`). A select at the top switches to any week of the same season, showing each week's picks-due date and game count.
- One card per league showing the **newest** `.json` on disk (`VegasInsider::newest`), its stamp and age, one row per game: kickoff (Central, `started` badge once past), away, home, spread, total.
- **Matched-team indicator**: a green check with the `football_teams` name when the page slug equals `vegas_insider_url` for that league; a red triangle, the **page's team name** and a `slug: …` badge (linking to the teams list) when not. Unmatched games are listed, never hidden, and their lines are shown but cannot be ticked.
- A checkbox per **spread** and per **total** (a game can yield both, as the hand-entered weeks do). A line already in the week for the same away/home/bet type shows an `in week` badge linking to the game instead of a checkbox. Header checkboxes tick every enabled box in that column.
- **Create**: one button creates every ticked line in the selected week. Each row becomes a `football_games` row: `type`, `date`/`time` from the scrape (already Central), team ids, `bet_type`, `value`, `title` = "Away Name @ Home Name", options `Bengals (-3.5)` / `Steelers (+3.5)` (NFL uses the nickname, NCAA the school, the pool's hand-entry convention) or `OVER (50.5)` / `UNDER (50.5)`. Hidden `stamp_<league>` fields carry the scrape timestamps; if a newer scrape landed between render and submit the whole POST is refused with an alert and nothing is created. Skipped picks (unmatched, no line, already in week) are listed in a warning alert.
- **Scrape now** button: POSTs `action=scrape`, which fetches and parses both leagues from the web server, writing `scrape/raw/` as `www-data`. A failure is shown as an alert and changes nothing. The first production click (owner, 2026-09-25 16:45) failed because `www-data` could not write there; see "Permissions on the droplet". Since that fix the class throws at the write itself, naming the path and the process user, instead of failing later with "File does not exist".

Verified 2026-09-25 through Apache at `http://127.0.0.1/pick55/` against the local `pick` database with a planted admin session: page renders without PHP notices (15 NFL + 70 NCAA rows, 69 checkboxes, unmatched slugs visible); a POST with a stale stamp was refused; a POST ticking Bengals@Steelers spread and Chargers@Bills total created games 2413/2414 in week 229 with the fields above, skipped the unmatched Commanders game with a warning, ignored a malformed key silently, and the reload showed both as `in week`. The test rows were deleted afterwards.

## Running it locally

- `php C:\wamp\www\pick55\scrape\get-raw.php nfl` then `php C:\wamp\www\pick55\scrape\parse-raw.php`.
- The local CLI PHP (7.4.33) shipped with no CA bundle, so HTTPS failed with cURL error 60. Fixed 2026-09-25: the Mozilla bundle is at `C:\php\php7.4.33\extras\ssl\cacert.pem` and `C:\php\php7.4.33\php.ini` sets `curl.cainfo` and `openssl.cafile` to it. The droplet's PHP uses the Ubuntu CA store and needs nothing.

## Rebuild plan — remaining steps

Goal stated by the owner 2026-09-07: lines settle around **Monday 8 pm Central**; the admin should open a page on the site, see the week's scraped games, tick the ones to include, and have them created with spreads and totals filled in.

1. ~~Scraper~~ — done 2026-09-25 (this doc's "What exists").
2. ~~`scrape/run.php`~~ — done 2026-09-25 (see "What exists"). Its PHP 8.0 run on the droplet is unverified until the first cron firing; the code uses nothing newer than 7.4 syntax and only `dom`, `curl`, `mysqli`, which 8.0 there has.
3. ~~Admin page~~ — done 2026-09-25 (see "The admin page").
4. ~~Cron on the droplet~~ — installed by the owner 2026-09-25 (crontab listed and `run.php --check` ran under `/usr/bin/php` 8.0: "Would run: week #229"). See "The cron".
5. ~~Delete `scrape/odds/`~~ — done 2026-09-25.

## Permissions on the droplet

Two processes write `scrape/raw/`: the cron as `beanstalk` and the Scrape-now button as `www-data`. Measured 2026-09-25 as `claude`: the tree carried over by the cutover is `beanstalk:beanstalk`, directories mode 775, and `www-data` is in no group but its own (`id www-data` → `groups=33(www-data)`), so the web server could not write and Scrape-now failed. The fix keeps `beanstalk` as owner, gives the directories group `www-data` with the setgid bit so anything either process creates inherits that group, and group write so both can add files (owner, PowerShell):

```powershell
ssh root@143.198.236.171 "chown -R beanstalk:www-data /home/beanstalk/pick55/scrape/raw; find /home/beanstalk/pick55/scrape/raw -type d -exec chmod 2775 {} +; ls -la /home/beanstalk/pick55/scrape/raw/vegas-insider"
```

Files stay readable by everyone (644), so `newest()` reads either process's output. `VegasInsider::getLeagueDir` chmods a directory it creates to 775 and throws if the directory is not writable; `saveRaw` and `parseFile` throw if a write fails. Applied by the owner 2026-09-25 (measured afterwards: `nfl/` is `drwxrwsr-x beanstalk www-data` and Scrape-now wrote `2026-09-25-16-52-46.html` as `www-data`).

## Apache's PHP 7.4 cannot parse: the CLI fallback

Measured 2026-09-25 on the droplet: `php7.4-xml` is not installed, so neither the 7.4 CLI nor Apache's 7.4 has `dom` (`/etc/php/7.4/apache2/conf.d/` has no xml or dom ini; `php7.4 -m` lists curl, libxml, mbstring, posix). The 7.4 packages came from the ondrej PPA's `bionic` channel, and `apt-cache policy php7.4-xml` finds no candidate, so the package cannot be added. `php8.0-xml` is installed, which is why `/usr/bin/php` (8.0) parses fine. Under Apache, `new DOMDocument()` was a fatal error, so the first Scrape-now after the permission fix returned HTTP 500 with the NFL page saved and no JSON.

Handling, since 2026-09-25:

- `VegasInsider::parse` throws a plain exception when `DOMDocument` is missing, and the page catches `Throwable`, so a missing extension is an alert, never a 500.
- The Scrape-now action checks `class_exists('DOMDocument')`. With it (local Apache, any future droplet PHP) it scrapes in-process. Without it (the droplet today) it calls `VegasInsider::scrapeViaCli()`, which `exec`s `<php_cli> scrape/run.php --force 2>&1`, the cron's own command, and shows the CLI's log lines in the alert. `php_cli` is `config('scrape.php_cli')`, default `/usr/bin/php`; `exec` is not in the droplet's `disable_functions` (only `pcntl_*` are). The CLI runs as `www-data`, so the permission fix above is what lets it write `scrape/raw/`.

## The cron

Owner decision 2026-09-07: Monday 20:15 Central (lines have settled) plus a Tuesday 08:00 retry. Droplet facts that bind it (measured 2026-09-24/25, [deploy.md](deploy.md)): the crontab belongs to `beanstalk` (Claude cannot read or edit it; the owner installs the line), the box clock is US Central so cron times are local, the line invokes **`/usr/bin/php` (8.0)** — not `php7.4`, whose CLI build lacks the `dom` extension the parser needs and warns on `pdo_mysql` at startup — and output goes to `/home/beanstalk/logs/scrape/`, which already exists. `scrape/raw/` on the droplet is owned by `beanstalk`, so the cron can write it.

The two crontab lines:

```
15 20 * * 1 /usr/bin/php /home/beanstalk/pick55/scrape/run.php >> /home/beanstalk/logs/scrape/run.log 2>&1
0 8 * * 2 /usr/bin/php /home/beanstalk/pick55/scrape/run.php >> /home/beanstalk/logs/scrape/run.log 2>&1
```

Both firings run the same week check, so on a quiet week neither writes anything; on a live week both scrape (the Tuesday file simply becomes the newest, which is what the admin page shows). Install order: push, deploy (`scripts/deploy.sh`) so `run.php` exists on the box, then install. The install command replaces any earlier `run.php` lines in `beanstalk`'s crontab and keeps everything else (owner, PowerShell):

```powershell
ssh root@143.198.236.171 "(crontab -u beanstalk -l 2>/dev/null | grep -v 'pick55/scrape/run.php'; echo '15 20 * * 1 /usr/bin/php /home/beanstalk/pick55/scrape/run.php >> /home/beanstalk/logs/scrape/run.log 2>&1'; echo '0 8 * * 2 /usr/bin/php /home/beanstalk/pick55/scrape/run.php >> /home/beanstalk/logs/scrape/run.log 2>&1') | crontab -u beanstalk -"
```

Verify the crontab and dry-run the week check under the droplet's PHP 8.0 as `beanstalk`:

```powershell
ssh root@143.198.236.171 "crontab -u beanstalk -l; su - beanstalk -c '/usr/bin/php /home/beanstalk/pick55/scrape/run.php --check'"
```

After the first firing, `/home/beanstalk/logs/scrape/run.log` holds one line per league (or `FAILED: …`). Claude can read `scrape/raw/` and the log over `ssh pick55` if `beanstalk` makes them group- or world-readable; the log directory's permissions are **(unconfirmed — ask)**.

Production state that makes this urgent (measured 2026-09-24): season 18 (2026) has 12 weeks; weeks 1–3 have 14 hand-entered games each, week 4 (`picks_due_date` 2026-09-24) and later have none.
