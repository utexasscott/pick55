# Droplet migration (2026)

**Shape: VERTICAL (process).** Moving pick55.com and pick9.golf from the Ubuntu 18.04 droplet to a new one. Why: the old box serves PHP 7.4.23 through mod_php, which apt cannot patch for PHP2Shell, on an OS that has been end-of-life since 2023. See [deploy.md](deploy.md), "PHP2Shell". When the move is done, this file becomes the record of how it was done, and [deploy.md](deploy.md) and [database-access.md](database-access.md) describe the new box.

## Decisions

| Decision | Choice | By |
|---|---|---|
| Size | DigitalOcean Basic, 2 GB / 1 vCPU, same region as today (sfo3) | owner, 2026-10-22 |
| OS and stack | Pending the local test below. Preferred: Ubuntu 26.04 LTS, with its own PHP 8.5 and MySQL 8.4 LTS, supported to 2031. Fallback: Ubuntu 24.04 with PHP 8.3 and MySQL 8.4 from Oracle's apt repository, since 24.04's own MySQL 8.0 went end-of-life upstream in April 2026. | Claude, pending |
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

- 2026-10-22: Decisions above. The local stack test is running. pick9's takeover hole is patched on the old box.
