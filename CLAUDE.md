# Pick55

NFL/NCAA football confidence-pick pool. Plain PHP 7.x web app (no framework) using Eloquent (illuminate/database) as the ORM, Bootstrap 5 + jQuery on the front end, SendGrid for email. Served by Apache from the repo root; every page is a real `.php` file (no front controller/router). Owner: Scott North (GitHub `utexasscott`). Public repo: https://github.com/utexasscott/pick55.

## Ubiquitous rules

- **Docs, not memory.** Auto-memory is off for this repo. Durable knowledge lives in this file or in `docs/`, in one of three shapes: VERTICAL (one service/page/process per file), HORIZONTAL (a concept key in the table below, anchored by `*Concept key: NAME*` wherever it applies), UBIQUITOUS (this file). See [docs/README.md](docs/README.md). The turn that changes a thing updates its doc in the same commit.
- **Commit freely, push on go.** Commit to local `main` without asking, small and often. Never push; a push is the production deploy trigger and only the owner says when. End any turn that leaves unpushed commits by handing the owner the push and deploy commands from [docs/deploy.md](docs/deploy.md), ready to paste (owner, 2026-09-25). (`PUSH_IS_DEPLOY`)
- **No live database write without a shown query and a go.** Reads against production are free. Any write or schema change to production is printed as exact SQL first and waits for an explicit go in a later turn. (`LIVE_WRITE_GATE`)
- **`er_users` is off limits to Claude's DB account.** It holds names and emails. (`PII_TABLE_EXCLUDED`)
- **Subagents are welcome** whenever the main session's full context is not needed for a task.
- **Commands handed to the owner are PowerShell, with absolute paths, no placeholders** (owner, 2026-09-07). Claude's own shell work also goes through PowerShell; one command per call, multi-step work goes in a script file.
- **Hand-over commands are numbered steps in the order they must run, in one block per step, with a one-line "what this does" and "wait for" note where a later step depends on an earlier one.** When a deploy depends on a migration, the migration step comes first and the push/deploy step says so explicitly. Never end a turn with the push command alone when a change file is pending. (owner, 2026-09-25, after a push deployed code that queried columns the schema file had not yet added.) (`ORDERED_HANDOVER`)
- **Secrets never enter the repo.** `inc/_config.php` and anything with a credential stay git-ignored. The repo is public.

## Concepts

| Key | Info |
|---|---|
| `PUSH_IS_DEPLOY` | Committing is free and unasked; pushing to `origin` deploys production and happens only on the owner's go. |
| `LIVE_WRITE_GATE` | A production write runs only after its exact SQL was shown and the owner gave a go in a later turn. |
| `PII_TABLE_EXCLUDED` | `er_users` (names, emails) is excluded from Claude's production grant; ids come from the owner. |
| `HALF_POINT_LINES` | Every spread and total stored on a game ends in .5 so a pick can never push. |
| `ORDERED_HANDOVER` | Commands for the owner are numbered steps in dependency order; a migration step precedes the push/deploy step that needs it. |

## Where the answer lives

| Question | Doc |
|---|---|
| How does code reach the droplet? | [docs/deploy.md](docs/deploy.md) |
| How does Claude reach the local or production DB? Schema changes? | [docs/database-access.md](docs/database-access.md) |
| How do game lines get scraped from VegasInsider? | [docs/odds-scraper.md](docs/odds-scraper.md) |
| How does Claude pick a week's 14 games from the scrape? | `/pick-games` skill: [.claude/skills/pick-games/SKILL.md](.claude/skills/pick-games/SKILL.md) |
| Why is the week results page fast now, and how is its cache invalidated? | [docs/results-cache.md](docs/results-cache.md) |
| Where do a week's rules and payouts live, and how are formats created? | [docs/week-formats.md](docs/week-formats.md) |
| What do the all-time stats pages (walls of fame/shame, leaderboards, best seasons) count, and how are they cached? | [docs/all-time-stats.md](docs/all-time-stats.md) |
| How do live scores get to the results page, and how does a game's result get set automatically? | [docs/live-scores.md](docs/live-scores.md) |
| What is the `r/` site (the redesign), how is it built, and what is its contract? | [docs/redesign.md](docs/redesign.md) |

## Layout

- `index.php`, `rules.php` — public pages
- `auth/` — login, signup (email verify), forgot/reset password, logout
- `account/` — user profile
- `season/` — player views: my season (`index.php`: week grid, points charts, and a Seasons table over every season the player is in, computed by `inc/Pick55/SeasonHistory.php` in four queries), standings, `week/pick.php` (make picks), `week/results.php`, `week/save-picks.php` (AJAX JSON endpoint), `week/raw.php` (JSON dump of the pick week's games, added in SVN r147–149), `week/live.php` (JSON of a week's live scores for the results page's refresh, see [docs/live-scores.md](docs/live-scores.md))
- `stats/` — all-time stats for every logged-in player: `index.php` (record book), `fame.php`, `shame.php`, `leaderboard.php`, `seasons.php`, over `inc/Pick55/AllTimeStats.php` (cached like the results page); `_shared.php` is their common setup (see [docs/all-time-stats.md](docs/all-time-stats.md))
- `r/` — the redesigned player site (2026-09-25), running beside the classic pages on the same session and domain classes: `index.php` (landing / "Today"), `auth/`, `account/`, `season/` (my season, standings, `week/pick.php`, `week/results.php`), `stats/`, `rules.php`, `api/` (JSON: `save-picks.php`, `week.php`, `today.php`, `friends.php`), `static/` (its own CSS/JS; no Bootstrap, jQuery or Font Awesome). Its PHP layer is `inc/Pick55/R/` (`Shell`, `Context`, `Fmt`, `Icons`, `Api`, `Today`, `SeasonStandings`). Contract and as-built notes: [docs/redesign.md](docs/redesign.md). Admin pages are not part of it.
- `admin/` — admin-only CRUD for seasons, weeks, games, teams, pools, week formats (guarded by `Auth::guardAdmin()`). `admin/weeks/week/bulk-games.php` creates games from scraper output; `admin/formats/` creates and edits week formats (see [docs/week-formats.md](docs/week-formats.md)).
- `inc/_inc.php` — bootstrap: loads `inc/_config.php`, session/cookies, mysqli + Eloquent connection, PSR-ish autoloader for `inc/Pick55/`, `funcs.php`, then `global_post_handler.php`
- `inc/_config.example.php` — copy to `inc/_config.php` (git-ignored). Keys: `base_url`, `sendgrid_api_key`, `dev_email_redir`, `db.*`
- `inc/Pick55/` — app classes: `App` (singleton, season/week resolution), `Auth`, `Page` (HTML layout/nav bars), `Alert` (session flash messages), `Emailer`, `Paging`, `XHelper`, `ColorFormatter`, `Cache` (file cache), `WeekResults` (results page calculation + cache), `WeekPayouts` (a format's payout rows applied to a standing; records winners), `AllTimeStats` (stats pages calculation + cache), `SeasonHistory`, `VegasInsider` (odds scraper), `Espn` (scoreboard client and game matching for live scores)
- `cache/` — runtime file cache, git-ignored, created on first use (see [docs/results-cache.md](docs/results-cache.md))
- `inc/Pick55/Models/` — Eloquent models. Tables are legacy-named: `er_users`, `football_seasons`, `football_weeks`, `football_games`, `football_teams`, `football_bets`, `football_pools`, `football_pool_users`, `er_users_friends`, etc. All models set `$timestamps = false` and `$guarded = []`. `GameScore` (`football_game_scores`, live/final ESPN scores per game, `Game::score()`) and `EspnTeam` (`football_espn_teams`, learned ESPN team ids) were added 2026-09-25 for live scores
- `inc/Pick55/Snippets/` — static `build(array $params)` HTML/email fragment renderers, each with a short `b(...)` shortcut
- `scrape/` — VegasInsider odds scraper CLIs (`get-raw.php`, `parse-raw.php`, `run.php` for cron, `slate.php` for the `/pick-games` skill) over `inc/Pick55/VegasInsider.php`; output under git-ignored `scrape/raw/`; see [docs/odds-scraper.md](docs/odds-scraper.md). `live-scores.php` is the 10-minute live-scores cron over `inc/Pick55/Espn.php`; see [docs/live-scores.md](docs/live-scores.md)
- `.claude/skills/` — project skills, committed; `pick-games` chooses a week's lines from the scrape
- `static/` — vendored CSS/JS (Bootstrap, Font Awesome, jQuery, Chart.js, stupidtable), `global.css`, `global.js`
- `scripts/` — droplet-side shell scripts (provisioning, git cutover, deploy); see [docs/deploy.md](docs/deploy.md)
- `docs/` — project documentation (see above)

## Conventions

- Tabs for indentation, LF line endings, `<?=` short echo tags in templates.
- Page pattern: `require_once __DIR__ . '/../inc/_inc.php'`, `Auth::guard()`/`guardAdmin()`/`guardGuest()`, handle `is_post()` in a try/catch that pushes `Alert::error()` and calls `redir()`, then `ob_start()` … `$page->setContent(ob_get_clean()); print $page->render();`.
- Helpers in `inc/funcs.php`: `get()`, `post()`, `input()`, `is_post()`, `redir($rel_path)`, `config('dot.key')`, `now()`, `ago()`, `ordinal()`, `sel()`.
- Links are built with `$page->link('rel/path.php')` off `config('base_url')`.
- Flash messages via `Alert::success|error|warning|info()`; rendered by `Page::renderAlerts()`.
- Season/week resolution: `App::get()->getSeason()` (query `?id=`, else active, else latest). `Season::weeks()` is ordered by `week_num` ascending; callers that loop it (`Week::getActive()`, `Week::getNext()`, the season page) rely on that, since weeks are inserted by hand in any id order. Week state is computed from `picks_due_date` and first game datetime (`Week::canPick()`, `canSeeResults()`).
- Bump `Page::ASSET_VERSION` when changing `static/css/global.css` or `static/js/global.js` (cache-busting).
- Picks: each bet has `option` ('0' none, '1'/'2' sides, '3' guaranteed-correct) and a `multiplier` (confidence 0–10; each of 1–10 may be used once per week).
- A week's rules (name, pools, playoff flag, payouts) come from its `WeekFormat` via `Week::getName()`, `getNumPools()`, `getNumWinners($pool_num)`, `getMinScoreThreshold($pool_num)`, `isPlayoffs()`. `football_weeks` has no rule columns of its own any more (dropped 2026-09-25).
- Times: the app runs in `America/Chicago`; `football_games.date`/`time` are Central wall-clock.
- Winnings are never typed in: `Pick55\WeekPayouts` turns the week format's payout rows into money per player (ties split the places they span; a player keeps the single largest amount they qualify for, overall over pool) and the results page records `football_week_winners` from it once every game of a recent week is decided. Expected winnings and the Expected Winnings chart come from the same rules inside `WeekResults` (see [docs/results-cache.md](docs/results-cache.md), "Money").

## Running locally (measured 2026-09-07)

- PHP 7.4.33 CLI at `C:\php\php7.4.33\php.exe` (on PATH); Composer is `C:\php\php7.4.33\composer.bat` (run via `cmd //c` from bash). `vendor/` is installed. Its `php.ini` points `curl.cainfo`/`openssl.cafile` at `C:\php\php7.4.33\extras\ssl\cacert.pem` (added 2026-09-25) so CLI HTTPS works.
- Apache (wamp64) serves `c:\wamp\www`, so the app is at `http://127.0.0.1/pick55/`, working against the local database. Its PHP 7.4.33 reads `C:\wamp64\bin\apache\apache2.4.65\bin\php.ini`, which also points `curl.cainfo`/`openssl.cafile` at the bundle above (set 2026-09-25; takes effect after a WampServer restart, which Claude cannot do).
- MySQL 5.7.44 (WampServer) on 3306, `root` with empty password, database `pick` (full production dump from 2026-09-07). Client binaries under `C:\wamp64\bin\mysql\mysql5.7.44\bin\`. Schema snapshot in `db/schema.sql`; details in [docs/database-access.md](docs/database-access.md).
- Production: DigitalOcean droplet `143.198.236.171` (`pick55.com`); see [docs/deploy.md](docs/deploy.md).
- Syntax check: `php -l path/to/file.php`. There is no test suite.

## Gotchas

- Signup requires the passcode `FOOTBALL` (hardcoded in `Auth::attemptSignup`); accounts still need admin activation per season.
- Passwords are `sha1(md5(salt . password))` (legacy scheme); tokens are `sha1(uniqid(mt_rand(), true))`.
- Logins last `LOGIN_LIFETIME` (30 days, defined in `inc/_inc.php`, raised from 7 days on 2026-09-26) past the latest visit: every request re-sends the `session_id`/`fingerprint` cookies and, when signed in, the `remember_token` cookie with a fresh expiry (`Auth::attemptCookieLogin()`), and `Auth::setAuthedUserId()` rotates the token stored in `er_users.remember_token`. Since the token is one per user, signing in on a second device logs the first out once its server-side session lapses.
- Several places build SQL by string interpolation (e.g. `season/week/save-picks.php`); values there are pre-validated ints. Prefer query builder bindings for new code.
- `Emailer` honors `dev_email_redir` in config to reroute all mail in dev.
- The project was migrated from SVN (Beanstalk, r146) to git on 2026-09-04; the old `trunk/` prefix is gone, so local URLs are `/pick55/` not `/pick55/trunk/`.
- Weeks are created by hand in the database (no create-week page); the admin week page then assigns the format and the pools page creates the pool rows.
