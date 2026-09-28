# Site traffic

**Shape: VERTICAL (one service and its admin page).** What is recorded about every page request, where, and how the owner reads it.

## The rule

Every page request is recorded as one row of `er_site_hits` when the request ends: who, when, which page, which site served it, which site the visitor has chosen. Owner's brief (2026-09-28): "site tracking … on every page - user id, date, url, etc. … a page added to the admin suite to see site traffic data … a column for version, then I could easily get a summary of everyone's preference."

Tracking never breaks a page. Any failure (the table missing, the database gone) is written to the PHP error log and the hit is dropped.

## Pieces

| Piece | Where | Does |
|---|---|---|
| `Pick55\Traffic` | `inc/Pick55/Traffic.php` | `start()` (called from `inc/_inc.php` on every web request, after the cookie login) registers `finish()` as a shutdown function, so redirects and `exit()` are recorded too, with the real status and duration. `view($rel, $query)` records a view reported by `app.js`. `cleanQuery()`, `device($user_agent)`, `isTracked($rel)`. |
| `Pick55\Models\SiteHit` | `inc/Pick55/Models/SiteHit.php` | The Eloquent model of `er_site_hits`. |
| Schema | `db/changes/2026-09-28-site-hits.sql` | `CREATE TABLE IF NOT EXISTS`, InnoDB; rollback `DROP` in the header. Applied locally 2026-09-28. |
| Grant (optional) | `db/changes/2026-09-28-site-hits-grants.sql` | `SELECT` on the table for Claude's droplet MySQL account. Production only. |
| Report endpoint | `r/api/hit.php` | `POST {url}` (relative to `r/`): records a prefetched page that was shown. 404 for a path that is not a page of `r/`. |
| Admin page | `admin/traffic/index.php` | The "Traffic" button of the admin bar. |

## The columns

| Column | Holds |
|---|---|
| `created_at` | When the request ended, Central time. |
| `er_user_id` | The signed-in player, NULL for a guest. A sign-out is recorded under the player who signed out. |
| `version` | The site that served the request: `r` for a path under `r/`, else `classic` (admin pages included). |
| `preference` | The visitor's `site_version` cookie as the request left it (`classic`, `r`), NULL when they never chose ([site-version.md](site-version.md)). |
| `method`, `path`, `query` | `path` is the serving script relative to the site (`season/week/results.php`, `r/index.php`; a directory request is recorded as its `index.php`). `query` is the query string with `token=` values masked as `*` and the `site` and `_partial` markers removed. |
| `status` | The response status: 200 a page, 302 a redirect (guards, form posts, the chosen-site redirect), 4xx/5xx an error. |
| `is_partial` | 1 when the redesign loaded the page in place (a fragment), 0 for a full page load. |
| `duration_ms` | Time from the start of the request to its end; NULL for a view reported through `r/api/hit.php`. |
| `ip`, `user_agent`, `referrer` | As sent by the browser, cut to the column length; `referrer` has tokens masked like `query`. |

Post bodies are never recorded.

## What is not recorded

- The command line (cron scripts, `scrape/`).
- JSON endpoints, which pages poll or post to: everything under `r/api/`, and `season/week/save-picks.php`, `live.php`, `raw.php` (`Traffic::SKIP`). At one poll a minute per open results page they would bury the page views.
- Prefetches. The redesign fetches a main tab's page when the pointer hovers it; that request carries `X-P55-Prefetch: 1` and is skipped. When the visitor does click within 10 seconds, `app.js` shows the fetched page and reports it through `r/api/hit.php`, so the view is counted once.
- Static files, which never reach PHP.

## The admin page

Filters, all in the query string: `days` (`1`, `7` default, `30`, `90`, `all`), `version`, `path` (contains), `user_id` (`0` = guests; set by clicking a name), `show=all`, `p` (page of the log).

A **view** is a GET answered with a 2xx. Every count on the page is of views; the log shows views, or every request with `show=all`.

- **Tiles**: views, players seen, classic views, Version 2.0 views, signed-out views, for the filters.
- **Which site players have chosen**: all time, not filtered. Each player counts once, by the `preference` of their latest recorded request; "No choice yet" are players who never followed a switch link. The Players table says which site such a player's latest page view was on (admin pages aside).
- **Page views by day** (by hour for "Today"): stacked columns, classic blue `#2a78d6` and Version 2.0 orange `#eb6834` (validated as a colour-blind-safe pair on white, 2026-09-28), with the same numbers as a table underneath.
- **Players**: everyone seen in the range, their choice, views per site, last seen.
- **Top pages**: the 25 most viewed, with players and the average build time.
- **The log**: 50 rows a page, newest first; ↻ marks an in-place load; the device is derived from the user agent (`Traffic::device`), the full user agent and the referrer are in the cell titles.

Names on this page come from `er_users` through the app's own credential, like the rest of the admin suite.

## Size

One row is about 300 bytes. The table is not pruned; at a few thousand views on a game day it grows by a few megabytes a season.

## Verified 2026-09-28 (local Apache, test player 2063)

- A guest page, a sign-in (POST, 302), full pages and a fragment as the player, a chosen-site redirect (302) and a sign-out were each recorded once with the expected `version`, `preference`, `status`, `is_partial` and user; a reset link was recorded as `token=*`.
- A request with `X-P55-Prefetch: 1` was not recorded; `POST r/api/hit.php {"url": "stats/index.php?a=1"}` recorded `r/stats/index.php`, and `{"url": "api/today.php"}` was refused with 404.
- The admin page rendered from 620 sample rows with no PHP warning, checked in headless Edge at 1280 px. The sample rows and the test rows were removed afterwards.
- Not verified: `app.js` reporting a prefetched view from a real browser (the endpoint and the header were exercised with curl only).

## Applying to production

**State: applied by the owner 2026-09-28, grant included, and deployed.** Measured the same hour from Claude's account: the first rows arrived at 17:06 Central (the owner's own visits, including a switch to Version 2.0 recorded as a 302 on `r/index.php` followed by the page), the app's credential can write the table, `https://pick55.com/rules.php` with cookie `site_version=r` answers 302 to `//pick55.com/r/rules.php`, and `https://pick55.com/r/` with `site_version=classic` answers 302 to `//pick55.com/`.

The steps, as they were run. The code tolerates a missing table, so no order is forced, but hits are only kept once the table exists (*Concept key: `ORDERED_HANDOVER`*): table first, then deploy. Claude's droplet MySQL account has no `CREATE` privilege, so the owner applies the file (*Concept key: `LIVE_WRITE_GATE`*). Owner, PowerShell:

1. Copy the change files to the droplet:
   ```powershell
   scp C:\wamp\www\pick55\db\changes\2026-09-28-site-hits.sql C:\wamp\www\pick55\db\changes\2026-09-28-site-hits-grants.sql root@143.198.236.171:/root/
   ```
2. Create the table (prompts for the MySQL root password):
   ```powershell
   ssh -t root@143.198.236.171 "mysql -u root -p pick < /root/2026-09-28-site-hits.sql"
   ```
3. Optional: let Claude's account read the table:
   ```powershell
   ssh -t root@143.198.236.171 "mysql -u root -p pick < /root/2026-09-28-site-hits-grants.sql"
   ```
4. Push and deploy ([deploy.md](deploy.md)).
