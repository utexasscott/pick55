# Pick55

NFL/NCAA football confidence-pick pool. Plain PHP 7.x web app (no framework) using Eloquent (illuminate/database) as the ORM, Bootstrap 5 + jQuery on the front end, SendGrid for email. Served by Apache from the repo root; every page is a real `.php` file (no front controller/router).

## Layout

- `index.php`, `rules.php` — public pages
- `auth/` — login, signup (email verify), forgot/reset password, logout
- `account/` — user profile
- `season/` — player views: my season, standings, `week/pick.php` (make picks), `week/results.php`, `week/save-picks.php` (AJAX JSON endpoint)
- `admin/` — admin-only CRUD for seasons, weeks, games, teams, pools (guarded by `Auth::guardAdmin()`)
- `inc/_inc.php` — bootstrap: loads `inc/_config.php`, session/cookies, mysqli + Eloquent connection, PSR-ish autoloader for `inc/Pick55/`, `funcs.php`, then `global_post_handler.php`
- `inc/_config.example.php` — copy to `inc/_config.php` (git-ignored). Keys: `base_url`, `sendgrid_api_key`, `dev_email_redir`, `db.*`
- `inc/Pick55/` — app classes: `App` (singleton, season/week resolution), `Auth`, `Page` (HTML layout/nav bars), `Alert` (session flash messages), `Emailer`, `Paging`, `XHelper`, `ColorFormatter`
- `inc/Pick55/Models/` — Eloquent models. Tables are legacy-named: `er_users`, `football_seasons`, `football_weeks`, `football_games`, `football_bets`, `football_pools`, `football_pool_users`, `er_users_friends`, etc. All models set `$timestamps = false` and `$guarded = []`
- `inc/Pick55/Snippets/` — static `build(array $params)` HTML/email fragment renderers, each with a short `b(...)` shortcut
- `scrape/` — CLI odds scrapers (Vegas Insider). `get-raw.php` / `parse-raw.php` use Guzzle + `XHelper`; `scrape/odds/` is an older Puppeteer + PHP variant (`driver.php` references a `../common/config.php` that doesn't exist; treat as dead/legacy)
- `static/` — vendored CSS/JS (Bootstrap, Font Awesome, jQuery, Chart.js, stupidtable), `global.css`, `global.js`

## Conventions

- Tabs for indentation, LF line endings, `<?=` short echo tags in templates.
- Page pattern: `require_once __DIR__ . '/../inc/_inc.php'`, `Auth::guard()`/`guardAdmin()`/`guardGuest()`, handle `is_post()` in a try/catch that pushes `Alert::error()` and calls `redir()`, then `ob_start()` … `$page->setContent(ob_get_clean()); print $page->render();`.
- Helpers in `inc/funcs.php`: `get()`, `post()`, `input()`, `is_post()`, `redir($rel_path)`, `config('dot.key')`, `now()`, `ago()`, `ordinal()`, `sel()`.
- Links are built with `$page->link('rel/path.php')` off `config('base_url')`.
- Flash messages via `Alert::success|error|warning|info()`; rendered by `Page::renderAlerts()`.
- Season/week resolution: `App::get()->getSeason()` (query `?id=`, else active, else latest). Week state is computed from `picks_due_date` and first game datetime (`Week::canPick()`, `canSeeResults()`).
- Bump `Page::ASSET_VERSION` when changing `static/css/global.css` or `static/js/global.js` (cache-busting).
- Picks: each bet has `option` ('0' none, '1'/'2' sides, '3' guaranteed-correct) and a `multiplier` (confidence 0–10; each of 1–10 may be used once per week).

## Running locally

- PHP 7.4 CLI: `C:\php\php7.4.33\php.exe`; Composer: `C:\php\php7.4.33\composer.bat`
- `composer install` to populate `vendor/` (git-ignored).
- Needs a MySQL database restored from live/backup; no schema/migrations live in the repo.
- Syntax check: `php -l path/to/file.php`. There is no test suite.

## Gotchas

- Signup requires the passcode `FOOTBALL` (hardcoded in `Auth::attemptSignup`); accounts still need admin activation per season.
- Passwords are `sha1(md5(salt . password))` (legacy scheme); tokens are `sha1(uniqid(mt_rand(), true))`.
- Several places build SQL by string interpolation (e.g. `season/week/save-picks.php`); values there are pre-validated ints. Prefer query builder bindings for new code.
- `Emailer` honors `dev_email_redir` in config to reroute all mail in dev.
- The project was migrated from SVN (Beanstalk, r146) to git on 2026-09-04; the old `trunk/` prefix is gone, so local URLs are `/pick55/` not `/pick55/trunk/`.
