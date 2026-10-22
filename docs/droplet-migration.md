# Droplet migration (2026)

**Shape: VERTICAL (process).** Moving pick55.com and pick9.golf from the Ubuntu 18.04 droplet to a new one. Why: the old box serves PHP 7.4.23 through mod_php, which apt cannot patch for PHP2Shell, on an OS that has been end-of-life since 2023. See [deploy.md](deploy.md), "PHP2Shell". When the move is done, this file becomes the record of how it was done, and [deploy.md](deploy.md) and [database-access.md](database-access.md) describe the new box.

## Decisions

| Decision | Choice | By |
|---|---|---|
| Size | DigitalOcean Basic, 2 GB / 1 vCPU, in **NYC1**: Regular plans weren't available in SFO3 that day | owner, 2026-10-22 |
| OS and stack | **Ubuntu 26.04 LTS** with its own PHP 8.5 and MySQL 8.4 LTS, supported to 2031; the local test below passed. The alternative was Ubuntu 24.04 with PHP 8.3 and MySQL 8.4 from Oracle's apt repository, since 24.04's own MySQL 8.0 went end-of-life upstream in April 2026. | owner, 2026-10-22 |
| Setup access | Claude gets temporary passwordless sudo on the new box and does the setup itself. The droplet's "User data" ([`new-droplet-user-data.yaml`](../scripts/new-droplet-user-data.yaml), cloud-init) creates the `claude` account with the same public key as on the old box. The sudo rule, in `/etc/sudoers.d/90-cloud-init-users`, is removed before cutover, leaving `claude` as limited as on the old box. In the event the user data arrived empty, so the owner ran the equivalent commands as root: the account, key and sudo rule in that same file, plus Central time. | owner, 2026-10-22 |
| Web server | Apache 2.4 with PHP-FPM (`mpm_event`). The root `.htaccess` keeps working (`AllowOverride All`). | Claude |
| pick9.golf | Moves too, as a second site | owner, 2026-10-22 |
| phpMyAdmin | Not installed. Database work goes over SSH. | owner, 2026-10-22 |
| DNS | Stays at DigitalOcean; both domains' NS are `ns1-3.digitalocean.com`. The switch changes the A records, which currently have a 3600 s TTL; lower them to 300 a day before. | Claude |
| Cutover window | Daytime, with an emailed notice that the sites may be down for the day; the real outage should be under an hour (owner, 2026-10-22). Best is a **Tuesday morning**: Monday night's results are final, and the next week's picks open at noon (`picks_due_date` is when picks open; they close at the first game on Saturday 11:00). The week's games are then created on the new box. A Wednesday or Thursday also works, since players still have until Saturday 11:00. Avoid Friday through Monday. | owner + Claude |
| TLS | Copy `/etc/letsencrypt` from the old box, so HTTPS works the moment DNS flips. One certificate covers `pick55.com`, `pick9.golf` and both `www` names. certbot renews it on the new box. | Claude |

## The old box (measured 2026-10-22 from `claude`)

| Fact | Value |
|---|---|
| MySQL | 5.7.42. `sql_mode` is `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`. `time_zone` is `SYSTEM` (US Central). The server charset is `latin1`, but the tables declare `utf8`/`utf8_unicode_ci`. |
| `pick` database | 19.7 MB in 19 tables: 17 MyISAM and 2 InnoDB. |
| pick9.golf | A sibling app of Pick55, with the same `composer.json` and layout and namespace `Pick9`. Its code is an SVN r263 export at `/home/beanstalk/pick9`, not in git. It has its own database, which `claude` cannot see, and its own `inc/_config.php` (mode 644). Its locked libraries are Carbon 2.52.0 and illuminate 8.58, so it needs the same library update as Pick55 before PHP 8.2+. |
| Cron | `beanstalk`'s crontab, as listed by the owner on 2026-10-22. Pick55 has three lines: `scrape/run.php` at Mon 20:15 and Tue 08:00, and `scrape/live-scores.php` every 10 minutes (see [odds-scraper.md](odds-scraper.md), "The cron", and [live-scores.md](live-scores.md)). pick9 has two, both every 5 minutes, year-round: `cd ~/pick9/scrape && php get-raw.php >> ~/logs/pick9/get-raw.log 2>&1` and the same with `parse-raw.php`. `php` there is `/usr/bin/php` 8.0, so pick9's scrapers already run on PHP 8. |
| pick9 takeover hole | pick9 had Pick55's `LIKE` email and token lookups (see `cfacc9d`). The owner patched it in place on 2026-10-22 with a one-off root script, using the same change: four email lookups in `inc/Pick9/Auth.php` and the token guard in `auth/reset.php`. The originals are in `/root/pick9-backup-2026-10-22`. Verified from `claude`: no `LIKE` left in either file. pick9's files use CRLF line endings. |
| Web root | Both sites are symlinks: `/var/www/pick55.com` → `/home/beanstalk/pick55` (git clone) and `/var/www/pick9.golf` → `/home/beanstalk/pick9`. |
| Disk | Pick55's tree is 176 MB; the SVN export parked at `pick55.svn-149` is 190 MB and is not moved. |

## The new box (built 2026-10-22)

[`provision-new-droplet.sh`](../scripts/provision-new-droplet.sh), run with Claude's setup sudo. It is safe to re-run, and [`migrate-unpack.sh`](../scripts/migrate-unpack.sh) re-runs it.

| Fact | Value |
|---|---|
| Droplet | `pick55-2026`, `137.184.150.9`, NYC1. 1 vCPU, 2 GB RAM plus a 2 GB swapfile, 48 GB disk. |
| OS | Ubuntu 26.04.1 LTS, US Central time, with automatic security updates (`20auto-upgrades`). Root logs in by SSH key only. |
| PHP | 8.5.4 through PHP-FPM (`pm.max_children = 10`). Its changelog lists the PHP2Shell CVEs. `99-pick55.ini` for FPM and CLI sets display off, logging on and `expose_php` off; FPM logs to `/var/log/php/fpm-error.log`, rotated weekly. |
| MySQL | 8.4.11, localhost only, X Protocol off, `performance_schema` off, 256 MB InnoDB buffer, 32 MB key buffer (`99-pick55.cnf`). MySQL's root logs in through the server's root account (auth_socket); each app has its own MySQL user. |
| Apache | 2.4.66 with `mpm_event` and `proxy_fcgi`. `pick55-server.conf` sets `ServerTokens Prod` and returns 404 for `.git`/`.svn` paths. Both vhosts have `Options -Indexes` (the old box listed directories) and `AllowOverride All`. Until `/etc/letsencrypt` arrives the sites are HTTP only; after that, HTTP redirects to HTTPS. |
| Firewall | ufw: SSH, 80 and 443 only. |
| Layout | Same paths as the old box: `beanstalk` owns `/home/beanstalk/pick55`, a git clone of `origin` that `deploy.sh` works on; `pick9` and `logs/` sit beside it, and both `/var/www/<site>` symlinks point there. |

### Copying the data: `migrate-pack.sh` and `migrate-unpack.sh`

The databases hold names and emails, so they travel box to box and never through Claude.

1. On the old box, the owner runs [`migrate-pack.sh`](../scripts/migrate-pack.sh) as root, which prompts for MySQL's root password. It writes `/root/migrate-2026/stage.tar.gz` (mode 600) containing:
   - both databases (`pick` and pick9's `golf`)
   - exact row counts for every table
   - both `inc/_config.php` files
   - `/etc/letsencrypt`
   - pick9's files (without `vendor/`)
   - Pick55's `scrape/raw`

   Without `--final` both sites stay up, and the dump holds a global read lock for a few seconds. With `--final` it first puts both sites into maintenance (503 with a short message, checked on the old box's Apache 2.4.29) and stops cron.
2. The owner copies the pack with `scp -3`, which streams it through their machine without storing it.
3. On the new box, Claude runs [`migrate-unpack.sh`](../scripts/migrate-unpack.sh) as root. It:
   - puts the files in place
   - drops and re-imports both databases
   - gives each site its own MySQL user: pick9's config logged in as `root`, so it gets a `pick9` user and a generated password, written into its config without printing
   - adds `'error_reporting' => E_ALL & ~E_DEPRECATED` to Pick55's config
   - creates Claude's MySQL account as on the old box (no `er_users`)
   - compares row counts
   - installs pick9's libraries from Pick55's `composer.lock` (the two `composer.json` files are identical)
   - re-runs the setup script for the HTTPS vhosts

## Local stack test (2026-10-22)

A subagent loaded a mysqldump of the local `pick` database (5.7) into a private MySQL 8.4.7 left at its defaults. On top of that it ran the app on PHP 8.5.0 and on 8.3.28 and compared both against PHP 7.4 on MySQL 5.7:

- **Requests:** 261 GETs as a guest, a player and an admin, 252 fuzzed URLs, 19 POST cases, and the scrape CLIs.
- **Import:** no errors. The warnings were deprecations (integer display width, `utf8` meaning `utf8mb3`) and one legacy `football_bets` row (id 4441) whose ENUM already holds `''` on 5.7. A row-by-row dump of 5.7 and 8.4 (99,186 rows) matched exactly.
- **Login:** `caching_sha2_password` users connect from PHP 7.4, 8.3 and 8.5 through both PDO and mysqli.
- **Results:** statuses, POST bodies, database end states, JSON endpoints and CLI output all matched 7.4 + 5.7. There were no fatals, exceptions or warnings, apart from the existing `App::selectSeason()` bug.
- **Display differences,** fixed in `fbd0103`:
  - MySQL 8 no longer sorts GROUP BY output, which changed the order of two all-time-stats queries.
  - PHP 8.4's `round()` gave 51.2 where 7.4 gave 51.3.
  - Week formats tied on playoffs, size and name came back in a different order.
- **App deprecations on 8.5,** fixed in `5784420`: implicitly nullable parameters, and null array keys on the traffic and results pages.
- **Library deprecations on 8.5:** illuminate 8 raises about 90 implicitly-nullable notices when its classes compile, and SendGrid and portable-ascii raise one each. No illuminate 8 release fixes them. Production on 8.4+ sets `'error_reporting' => E_ALL & ~E_DEPRECATED` in `inc/_config.php`, which `inc/_inc.php` reads since `5784420`. An illuminate upgrade becomes possible once nothing runs 7.4 any more; PHP 9 will need it.

### Production settings for the new box

- **php.ini, both the FPM and CLI SAPIs:**
  - `display_errors=Off`, `display_startup_errors=Off`, `log_errors=On`, a dedicated `error_log`, `html_errors=Off`, `expose_php=Off`, `zend.assertions=-1`
  - `opcache.enable=1`, with `opcache.validate_timestamps=1` so a `git pull` deploy is picked up
- **Moving the database:** stop Apache and cron first, because 17 of 19 tables are MyISAM and `--single-transaction` doesn't make them consistent.
  1. Dump with 5.7's own `mysqldump --default-character-set=utf8mb4 --lock-all-tables --routines --triggers --events --hex-blob --skip-dump-date --databases pick`.
  2. Import with the 8.4 client: `mysql --default-character-set=utf8mb4 --show-warnings`.
  3. Recreate the app user and Claude's read-only user with `caching_sha2_password`. Don't copy the `mysql` schema.
  4. Verify with a row-level diff of `--no-create-info --skip-extended-insert --order-by-primary` dumps from both servers.

## Phases

1. **Prepare (no downtime):**
   - Test the app locally on the new stack (PHP 8.5 and 8.3 against MySQL 8.4, loaded through mysqldump from 5.7).
   - Write `scripts/provision-new-droplet.sh`.
   - The owner creates the droplet with an SSH key and runs the script as root. Claude verifies.
2. **Stage (no downtime):**
   - Copy each site's code, config, `scrape/raw` and `/etc/letsencrypt` to the new box.
   - Restore both databases from dumps.
   - Claude tests both sites on the new box with `curl --resolve`, before any DNS change.
   - Bring pick9 up to PHP 8 the same way as Pick55.
3. **Cutover (a short write freeze at a quiet time):**
   - Put the old box into maintenance.
   - Take the final dumps and restore them on the new box.
   - Point the A records at the new IP.
   - Move the crons and verify.
4. **After:**
   - Power off the old droplet but keep it for a week as the rollback, then snapshot and destroy it.
   - Rewrite [deploy.md](deploy.md) and [database-access.md](database-access.md) for the new box.
   - Retire the PHP2Shell stopgap with the old box.

## State

- 2026-10-22: Decisions above. pick9's takeover hole is patched on the old box. The local stack test passed, and its fixes are committed (`fbd0103`, `5784420`). The owner chose Ubuntu 26.04 and temporary sudo for setup. The droplet was created in NYC1 and provisioned (above). The old box served `/.git/` publicly (its web root is a git clone); the repo `.htaccess` now returns 404 for it. Next: a staging pack, the copy, then unpacking and testing on the new box.
