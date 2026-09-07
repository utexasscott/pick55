# Odds scraper — VegasInsider game lines

**Shape: VERTICAL (service).** Pulls the week's NFL and NCAA games with consensus spreads and totals so the admin does not key games in by hand.

## Status (2026-09-07)

Two generations of scraper exist in the repo and **neither works against today's site**:

| Generation | Files | Fetch | Parse target | State |
|---|---|---|---|---|
| 1 (2019-era) | `scrape/odds/*` | Puppeteer (`get-odds-page.js`) | `table.frodds-data-tbl` | Dead. `driver.php` requires a `../common/config.php` that never existed here and references `TroShared` models. Candidate for deletion. |
| 2 (2021) | `scrape/get-raw.php`, `scrape/parse-raw.php` | Guzzle GET → `scrape/raw/vegas-insider/<league>/<stamp>.html` | `#frodds-imgmap-container` header + `table.frodds-data-tbl` rows → sibling `.json` | Fetch still works. Parser finds nothing: that markup is gone. `admin/weeks/week/bulk-games.php` reads the newest `.json` per league and offers its rows to the admin, so that page is the existing "pick from a list" surface. |

## The page today — measured 2026-09-07

- URLs unchanged: `https://www.vegasinsider.com/nfl/odds/las-vegas/` and `/college-football/odds/las-vegas/`. Both return 200 to a plain curl with a browser User-Agent. Server-rendered HTML, about 1 MB (NFL) and 3.6 MB (NCAA). **No headless browser needed.**
- One `<table class="odds-table">` per page. `<thead>` lists the sportsbooks in column order via `<span class="hidden">` text: Open, Bet365, BetMGM, DraftKings, Caesars, FanDuel, HardRock, Fanatics, RiversCasino, **Consensus**. Column order is not guaranteed stable; read the header, do not hardcode indexes.
- Three `<tbody>` groups per table, keyed by class: `odds-table-spread--0`, `odds-table-total--0`, `odds-table-moneyline--0`. Spread and total are what Pick55 uses.
- Inside a tbody, each game is a run of rows:
  - a header row with `td.game-time` containing `<span data-role="localtime" data-value="2026-09-10T00:20:00Z">` — **kickoff in UTC**. The app stores `date`/`time` in US Central (`date_default_timezone_set('America/Chicago')` in `inc/_inc.php`), so convert.
  - one `tr` per team (away first, then home) with `td.game-team` holding `<a href="/nfl/teams/patriots/" data-abbr="NE">` and the rotation number, followed by one `td.game-odds` per book with `<span class="data-value">+3.5</span>` and `<small class="data-odds">-110</small>`. In the totals tbody the value reads like `o47.5` / `u47.5`.
- Team identity: the URL slug (`patriots`, `alabama-crimson-tide`, …) is the stable key. Whether `football_teams` has a slug column today is **(unconfirmed — check the dump)**; the 2019 code expected `vegas_insider_url`.
- A `--4.5` oddity was observed in a HardRock cell (double minus). Parse defensively: reduce to `[+-]?\d+(\.5)?`.

## Rebuild plan

Goal stated by the owner 2026-09-07: lines settle around **Monday 8 pm Central**; the admin should open a page on the site, see the week's scraped games, tick the ones to include, and have them created with spreads and totals filled in.

1. **Scraper** (`scrape/get-raw.php` kept, `parse-raw.php` rewritten): parse `table.odds-table` into the same JSON shape `bulk-games.php` already consumes (`date`, `time`, `away_team`, `home_team`, `spread`, `over-under`) plus `away_abbr`/`home_abbr`, using the Consensus column. The half-point rule from the old parser stays: whole-number lines get `+0.5` so no pick can push.
2. **Team matching**: add a `vi_slug` column to `football_teams` (a `db/changes/` file), populate it once by hand-matching, then match on slug. Unmatched slugs are listed on the admin page so the owner can map them.
3. **Admin page**: `bulk-games.php` already lists scrape rows against the week; verify it against the new JSON, add checkboxes and a one-click create. Consider a cron on the droplet Monday 20:15 Central running `get-raw.php` and `parse-raw.php` for both leagues so the list is waiting.
4. **Delete `scrape/odds/`** once step 1 lands.

Open questions for the owner live in the session, not here; this doc records decisions once made.
