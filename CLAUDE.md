# Pick55

NFL/NCAA football confidence-pick pool. Plain PHP 7.x web app (no framework) using Eloquent (illuminate/database) as the ORM, Bootstrap 5 + jQuery on the front end, SendGrid for email. Served by Apache from the repo root; every page is a real `.php` file (no front controller/router). Owner: Scott North (GitHub `utexasscott`). Public repo: https://github.com/utexasscott/pick55.

## Ubiquitous rules

- **Docs, not memory.** Auto-memory is off for this repo. Durable knowledge lives in this file or in `docs/`, in one of three shapes: VERTICAL (one service/page/process per file), HORIZONTAL (a concept key in the table below, anchored by `*Concept key: NAME*` wherever it applies), UBIQUITOUS (this file). See [docs/README.md](docs/README.md). The turn that changes a thing updates its doc in the same commit.
- **Commit freely, push on go.** Commit to local `main` without asking, small and often. Never push; a push is the production deploy trigger and only the owner says when. End any turn that leaves unpushed commits by suggesting a push. (`PUSH_IS_DEPLOY`)
- **No live database write without a shown query and a go.** Reads against production are free. Any write or schema change to production is printed as exact SQL first and waits for an explicit go in a later turn. (`LIVE_WRITE_GATE`)
- **`er_users` is off limits to Claude's DB account.** It holds names and emails. (`PII_TABLE_EXCLUDED`)
- **Subagents are welcome** whenever the main session's full context is not needed for a task.
- **Commands handed to the owner are PowerShell, with absolute paths, no placeholders** (owner, 2026-09-07). Claude's own shell work also goes through PowerShell; one command per call, multi-step work goes in a script file.
- **Secrets never enter the repo.** `inc/_config.php` and anything with a credential stay git-ignored. The repo is public.

## Concepts

| Key | Info |
|---|---|
| `PUSH_IS_DEPLOY` | Committing is free and unasked; pushing to `origin` deploys production and happens only on the owner's go. |
| `LIVE_WRITE_GATE` | A production write runs only after its exact SQL was shown and the owner gave a go in a later turn. |
| `PII_TABLE_EXCLUDED` | `er_users` (names, emails) is excluded from Claude's production grant; ids come from the owner. |
| `HALF_POINT_LINES` | Every spread and total stored on a game ends in .5 so a pick can never push. |

## Where the answer lives

| Question | Doc |
|---|---|
| How does code reach the droplet? | [docs/deploy.md](docs/deploy.md) |
| How does Claude reach the local or production DB? Schema changes? | [docs/database-access.md](docs/database-access.md) |
| How do game lines get scraped from VegasInsider? | [docs/odds-scraper.md](docs/odds-scraper.md) |
| Why is the week results page fast now, and how is its cache invalidated? | [docs/results-cache.md](docs/results-cache.md) |

## Layout

- `index.php`, `rules.php` — public pages
- `auth/` — login, signup (email verify), forgot/reset password, logout
- `account/` — user profile
- `season/` — player views: my season, standings, `week/pick.php` (make picks), `week/results.php`, `week/save-picks.php` (AJAX JSON endpoint), `week/raw.php` (JSON dump of the pick week's games, added in SVN r147–149)
- `admin/` — admin-only CRUD for seasons, weeks, games, teams, pools (guarded by `Auth::guardAdmin()`). `admin/weeks/week/bulk-games.php` creates games from scraper output.
- `inc/_inc.php` — bootstrap: loads `inc/_config.php`, session/cookies, mysqli + Eloquent connection, PSR-ish autoloader for `inc/Pick55/`, `funcs.php`, then `global_post_handler.php`
- `inc/_config.example.php` — copy to `inc/_config.php` (git-ignored). Keys: `base_url`, `sendgrid_api_key`, `dev_email_redir`, `db.*`
- `inc/Pick55/` — app classes: `App` (singleton, season/week resolution), `Auth`, `Page` (HTML layout/nav bars), `Alert` (session flash messages), `Emailer`, `Paging`, `XHelper`, `ColorFormatter`, `Cache` (file cache), `WeekResults` (results page calculation + cache)
- `cache/` — runtime file cache, git-ignored, created on first use (see [docs/results-cache.md](docs/results-cache.md))
- `inc/Pick55/Models/` — Eloquent models. Tables are legacy-named: `er_users`, `football_seasons`, `football_weeks`, `football_games`, `football_teams`, `football_bets`, `football_pools`, `football_pool_users`, `er_users_friends`, etc. All models set `$timestamps = false` and `$guarded = []`
- `inc/Pick55/Snippets/` — static `build(array $params)` HTML/email fragment renderers, each with a short `b(...)` shortcut
- `scrape/` — CLI odds scrapers; see [docs/odds-scraper.md](docs/odds-scraper.md) (both generations currently broken against the live site)
- `static/` — vendored CSS/JS (Bootstrap, Font Awesome, jQuery, Chart.js, stupidtable), `global.css`, `global.js`
- `scripts/` — droplet-side shell scripts (provisioning, git cutover, deploy); see [docs/deploy.md](docs/deploy.md)
- `docs/` — project documentation (see above)

## Conventions

- Tabs for indentation, LF line endings, `<?=` short echo tags in templates.
- Page pattern: `require_once __DIR__ . '/../inc/_inc.php'`, `Auth::guard()`/`guardAdmin()`/`guardGuest()`, handle `is_post()` in a try/catch that pushes `Alert::error()` and calls `redir()`, then `ob_start()` … `$page->setContent(ob_get_clean()); print $page->render();`.
- Helpers in `inc/funcs.php`: `get()`, `post()`, `input()`, `is_post()`, `redir($rel_path)`, `config('dot.key')`, `now()`, `ago()`, `ordinal()`, `sel()`.
- Links are built with `$page->link('rel/path.php')` off `config('base_url')`.
- Flash messages via `Alert::success|error|warning|info()`; rendered by `Page::renderAlerts()`.
- Season/week resolution: `App::get()->getSeason()` (query `?id=`, else active, else latest). Week state is computed from `picks_due_date` and first game datetime (`Week::canPick()`, `canSeeResults()`).
- Bump `Page::ASSET_VERSION` when changing `static/css/global.css` or `static/js/global.js` (cache-busting).
- Picks: each bet has `option` ('0' none, '1'/'2' sides, '3' guaranteed-correct) and a `multiplier` (confidence 0–10; each of 1–10 may be used once per week).
- Times: the app runs in `America/Chicago`; `football_games.date`/`time` are Central wall-clock.

## Running locally (measured 2026-09-07)

- PHP 7.4.33 CLI at `C:\php\php7.4.33\php.exe` (on PATH); Composer is `C:\php\php7.4.33\composer.bat` (run via `cmd //c` from bash). `vendor/` is installed.
- Apache (wamp64) serves `c:\wamp\www`, so the app is at `http://127.0.0.1/pick55/`, working against the local database.
- MySQL 5.7.44 (WampServer) on 3306, `root` with empty password, database `pick` (full production dump from 2026-09-07). Client binaries under `C:\wamp64\bin\mysql\mysql5.7.44\bin\`. Schema snapshot in `db/schema.sql`; details in [docs/database-access.md](docs/database-access.md).
- Production: DigitalOcean droplet `143.198.236.171` (`pick55.com`); see [docs/deploy.md](docs/deploy.md).
- Syntax check: `php -l path/to/file.php`. There is no test suite.

## Gotchas

- Signup requires the passcode `FOOTBALL` (hardcoded in `Auth::attemptSignup`); accounts still need admin activation per season.
- Passwords are `sha1(md5(salt . password))` (legacy scheme); tokens are `sha1(uniqid(mt_rand(), true))`.
- Several places build SQL by string interpolation (e.g. `season/week/save-picks.php`); values there are pre-validated ints. Prefer query builder bindings for new code.
- `Emailer` honors `dev_email_redir` in config to reroute all mail in dev.
- The project was migrated from SVN (Beanstalk, r146) to git on 2026-09-04; the old `trunk/` prefix is gone, so local URLs are `/pick55/` not `/pick55/trunk/`.
