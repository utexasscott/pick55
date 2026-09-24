# Odds scraper — VegasInsider game lines

**Shape: VERTICAL (service).** Pulls the week's NFL and NCAA games with consensus spreads and totals so the admin does not key games in by hand.

## Status (2026-09-07)

Two generations of scraper exist in the repo and **neither works against today's site**:

| Generation | Files | Fetch | Parse target | State |
|---|---|---|---|---|
| 1 (2019-era) | `scrape/odds/*` | Puppeteer (`get-odds-page.js`) | `table.frodds-data-tbl` | Dead. `driver.php` requires a `../common/config.php` that never existed here and references `TroShared` models. Candidate for deletion. |
| 2 (2021) | `scrape/get-raw.php`, `scrape/parse-raw.php` | Guzzle GET → `scrape/raw/vegas-insider/<league>/<stamp>.html` | `#frodds-imgmap-container` header + `table.frodds-data-tbl` rows → sibling `.json` | Fetch still works. Parser finds nothing: that markup is gone. `admin/weeks/week/bulk-games.php` reads the newest `.json` per league and offers its rows to the admin, so that page is the existing "pick from a list" surface. |

Generation 2 **did run in production**: the droplet holds `scrape/raw/vegas-insider/{nfl,ncaa}/` files stamped daily at 02:00 through 2022-03-25 and logs in `/home/beanstalk/logs/scrape/` (last written 2022-03-25; an empty `parse.log` from 2023-04-05). So a `beanstalk` cron existed and has been dead since 2022–2023 (measured 2026-09-24).

## The page today — measured 2026-09-07

- URLs unchanged: `https://www.vegasinsider.com/nfl/odds/las-vegas/` and `/college-football/odds/las-vegas/`. Both return 200 to a plain curl with a browser User-Agent. Server-rendered HTML, about 1 MB (NFL) and 3.6 MB (NCAA). **No headless browser needed.**
- One `<table class="odds-table">` per page. `<thead>` lists the sportsbooks in column order via `<span class="hidden">` text: Open, Bet365, BetMGM, DraftKings, Caesars, FanDuel, HardRock, Fanatics, RiversCasino, **Consensus**. Column order is not guaranteed stable; read the header, do not hardcode indexes.
- Three `<tbody>` groups per table, keyed by class: `odds-table-spread--0`, `odds-table-total--0`, `odds-table-moneyline--0`. Spread and total are what Pick55 uses.
- Inside a tbody, each game is a run of rows:
  - a header row with `td.game-time` containing `<span data-role="localtime" data-value="2026-09-10T00:20:00Z">` — **kickoff in UTC**. The app stores `date`/`time` in US Central (`date_default_timezone_set('America/Chicago')` in `inc/_inc.php`), so convert.
  - one `tr` per team (away first, then home) with `td.game-team` holding `<a href="/nfl/teams/patriots/" data-abbr="NE">` and the rotation number, followed by one `td.game-odds` per book with `<span class="data-value">+3.5</span>` and `<small class="data-odds">-110</small>`. In the totals tbody the value reads like `o47.5` / `u47.5`.
- Team identity: the URL slug in the team link is the stable key. `football_teams.vegas_insider_url` exists (measured 2026-09-07 on the dump: 34 NFL + 90 NCAA rows, values like `alabama`, `louisiana-state`) and holds slugs that **still largely match** the new site (measured 2026-09-07 against the fetched pages): NFL 31 of 32 page slugs match a DB row (`commanders` is the exception, the DB row predates the rename); NCAA 61 of the 90 DB rows match a page slug, and the remaining page slugs are mostly schools the pool has never used. So matching on `vegas_insider_url` works; unmatched page slugs get surfaced to the admin rather than blocking.
- A `--4.5` oddity was observed in a HardRock cell (double minus). Parse defensively: reduce to `[+-]?\d+(\.5)?`.

## Rebuild plan

Goal stated by the owner 2026-09-07: lines settle around **Monday 8 pm Central**; the admin should open a page on the site, see the week's scraped games, tick the ones to include, and have them created with spreads and totals filled in.

1. **Scraper** (`scrape/get-raw.php` kept, `parse-raw.php` rewritten): parse `table.odds-table` into the same JSON shape `bulk-games.php` already consumes (`date`, `time`, `away_team`, `home_team`, `spread`, `over-under`) plus `away_abbr`/`home_abbr`, using the Consensus column. The half-point rule from the old parser stays: whole-number lines get `+0.5` so no pick can push.
2. **Team matching**: add a `vi_slug` column to `football_teams` (a `db/changes/` file), populate it once by hand-matching, then match on slug. Unmatched slugs are listed on the admin page so the owner can map them.
3. **Admin page**: `bulk-games.php` already lists scrape rows against the week; verify it against the new JSON, add checkboxes and a one-click create.
4. **Cron on the droplet** (owner decision, 2026-09-07): the scrape runs automatically, but **only when a `football_weeks` row exists whose `picks_due_date` is less than 7 days in the future**. One CLI entry point (`scrape/run.php`) checks that condition, exits quietly if it fails, otherwise fetches and parses both leagues. Schedule: Monday 20:15 Central, plus a Tuesday morning retry.
5. **Delete `scrape/odds/`** once step 1 lands.

Open questions for the owner live in the session, not here; this doc records decisions once made.
