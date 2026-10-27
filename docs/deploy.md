# Deploy — the DigitalOcean droplet

**Shape: VERTICAL (process).** How code gets from this repo to production.

## Where production runs

Since **2026-10-27**, the droplet built in [droplet-migration.md](droplet-migration.md), "The new box", by [`provision-new-droplet.sh`](../scripts/provision-new-droplet.sh). The old droplet (`143.198.236.171`, Ubuntu 18.04, PHP 7.4 under mod_php, MySQL 5.7) has been retired; its facts are in that doc, "The old box".

| Fact | Value |
|---|---|
| Droplet | DigitalOcean `pick55-2026`, **`137.184.150.9`**, NYC1. 1 vCPU, 2 GB RAM plus a 2 GB swapfile, 48 GB disk. |
| OS | Ubuntu 26.04.1 LTS, US Central time, automatic security updates. |
| Sites | `pick55.com` and `pick9.golf`, each with `www`, behind one Let's Encrypt certificate (renewed by `certbot.timer` with the apache plugin; a dry run passed from this box on 2026-10-27). HTTP redirects to HTTPS, and `AllowOverride All`, so `.htaccess` is honoured. |
| Web root | `/var/www/pick55.com` → **`/home/beanstalk/pick55`**, a git clone owned by `beanstalk`. pick9's files, an SVN export not in git, are at `/home/beanstalk/pick9`. |
| PHP | **8.5.4** for both Apache (PHP-FPM) and CLI (`/usr/bin/php`); it has `dom`, `curl`, `mysqli`, `pdo_mysql`. Production's `inc/_config.php` sets `'error_reporting' => E_ALL & ~E_DEPRECATED`, because illuminate 8 deprecations would otherwise fill the log. The FPM log is `/var/log/php/fpm-error.log`. Code must still run on the local PHP 7.4 (CLAUDE.md, "Running locally"). |
| MySQL | 8.4.11, localhost only, databases `pick` and pick9's `golf`. The app logs in as `pick55`, pick9 as `pick9`, and MySQL's `root` through the server's root account (auth_socket, no password). |
| `inc/_config.php` | `beanstalk:www-data` mode 640, for both sites. |
| Cron | `beanstalk`'s crontab, installed by [`migrate-unpack.sh`](../scripts/migrate-unpack.sh) `--final`: Pick55's `run.php` at Mon 20:15 and Tue 08:00, and `live-scores.php` every 10 minutes; pick9's `get-raw.php` and `parse-raw.php` every 5 minutes. Logs go to `/home/beanstalk/logs/` and rotate weekly. |
| What `claude` can do | SSH as `claude`; read the trees; use the MySQL account in [database-access.md](database-access.md). No sudo once the setup sudo is removed after the cutover. |

## The rule: commit freely, push on go

*Concept key: `PUSH_IS_DEPLOY`.*

- Claude commits to the local `main` **without asking**, in small commits, as work lands.
- Claude **never pushes to `origin`** on its own. A push is the deploy trigger, and the owner says when.
- Claude ends any turn that leaves unpushed commits by **handing the owner both commands** below, ready to paste (owner, 2026-09-25).
- A push updates GitHub only. The droplet pulls when the owner runs the deploy command below, so "deploy" is two owner actions: push, then deploy.

```powershell
git -C C:\wamp\www\pick55 push origin main
ssh root@137.184.150.9 "bash /home/beanstalk/pick55/scripts/deploy.sh"
```

## Mechanism

The web root is a git clone of `origin/main` at `/home/beanstalk/pick55`, owned by `beanstalk`. Two scripts, both in `scripts/`:

| Script | Runs as | When | Does |
|---|---|---|---|
| [`cutover-to-git.sh`](../scripts/cutover-to-git.sh) | root, once | replacing the Beanstalk export | parks the export at `pick55.svn-149`, clones, carries over `inc/_config.php` (mode 640, group `www-data`) and `scrape/raw/`, `composer install --no-dev`, creates `cache/` owned by `www-data`, curls three URLs. Header holds the rollback line. |
| [`deploy.sh`](../scripts/deploy.sh) | `beanstalk` (root re-execs as it) | after every push | `git fetch` + `merge --ff-only origin/main`; `composer install --no-dev` only if `composer.lock` changed; prints the commits deployed. Refuses non-fast-forward. |

Deploy command the owner runs after a push:

```powershell
ssh root@137.184.150.9 "bash /home/beanstalk/pick55/scripts/deploy.sh"
```

`claude` cannot run either script (no write access to the tree, no sudo). Automating the pull from a GitHub Actions workflow is possible later (a deploy key on the droplet plus an SSH key in the repo secrets) and is not built.

**State:** cutover run by the owner 2026-09-25 15:12 CDT, verified from `claude` the same hour: `/home/beanstalk/pick55` is a clone at `4262ad7`, `inc/_config.php` is `beanstalk:www-data` mode 640 (no longer readable by `claude`), `cache/` is `www-data:beanstalk` 775, `scrape/raw/` carried over, `/` and `/rules.php` return 200. The SVN export is parked at `/home/beanstalk/pick55.svn-149` and can be deleted once a few deploys have gone through. `git` commands run as `claude` in that tree fail with *dubious ownership*; that is expected and harmless.

(That state describes the old box. On the new box, `migrate-unpack.sh` set the same layout up.) The lock file installs on the local PHP 7.4 and on production's 8.5. It was last regenerated 2026-10-22 under the local PHP 7.4, moving Carbon 2.53→2.73, illuminate 8.61→8.83.27, SendGrid 7.9→7.11.5 and Guzzle 7.3→7.16 for PHP 8.3 readiness and 21 security advisories. A `composer install --no-dev --dry-run` of it on the droplet (Composer 2.1.6, PHP 8.0.10) passed every platform check.

## PHP2Shell (CVE-2026-109618 / CVE-2026-109619)

**Resolved 2026-10-27:** production moved to the new droplet, whose PHP 8.5.4 carries the fixes. The stopgap and the unpatchable PHP 7.4 stayed behind with the old box. What follows is the record.

Two unauthenticated remote-code-execution bugs in the PHP engine, disclosed and patched 2026-10-21: fixed releases are 8.2.35, 8.3.36, 8.4.27 and 8.5.12; 7.4 to 8.1 got source backports only. Every known trigger is a `multipart/form-data` request. Apache with mod_php is the worst case, because the bug fires before any application code runs.

Measured 2026-10-22 from `claude`:

- Apache's PHP is **7.4.23, built 2021-08-26**, under mod_php (prefork). Its changelog names neither CVE. It came from the ondrej `bionic` channel, which gets no new builds, so apt cannot patch it. Ubuntu Pro ESM for 18.04 patches only the distro's own PHP 7.2, which nothing here runs.
- `session.upload_progress.enabled` was unset (so on) and `memory_limit` was 128M.
- No sign of compromise visible to `claude`. The web root matched `374475e`, with no untracked PHP besides `inc/_config.php`. `www-data` had written nothing outside `cache/` since 2026-10-15, and no `www-data` process other than Apache was running. `claude` cannot read the Apache logs.

Nothing on the site posts multipart. Since 2026-10-22 the `r/` async forms send URL-encoded bodies; before that they sent `FormData`, which is multipart. No page has a file upload, and none can have one while the stopgap is in place.

**Stopgap:** [`php2shell-stopgap.sh`](../scripts/php2shell-stopgap.sh), run as root, does two things:

- It refuses every multipart request with a 403 on every vhost and alias, including `pick9.golf` and phpMyAdmin. A repo `.htaccess` would miss both.
- It turns upload progress off for the Apache SAPI.

`--undo` removes both. The third published mitigation, `memory_limit = -1`, is left out. On 985 MB, with prefork workers and MySQL on the same box, it trades the RCE for one request that can exhaust memory.

**Fix:** a new droplet on Ubuntu 24.04 with its own PHP 8.3 packages, which get security fixes through apt (owner, 2026-10-22). The first step is a local check that the app runs on PHP 8.3. Patching this box would mean building PHP 7.4 from source with the backport, on an OS that has been end-of-life since 2023.

**State:** the stopgap was run by the owner on 2026-10-22 at `503be49`. The first attempt, at `7abe638`, failed Apache's configtest on `/multipart\/form-data/i` and rolled itself back; Apache 2.4.29 cannot compile an escaped slash in `/.../`, so the script now uses `m#...#`. The script's own checks found upload progress `'0'` for Apache and a 403 for multipart posts to `pick55.com/`, `pick55.com/r/`, `pick55.com/phpmyadmin/` and `pick9.golf/`, while a URL-encoded post got 200. From outside the box the same day, a multipart post got 403 and a GET of `/` got 200.

## What must never be in a deploy

- `inc/_config.php` (credentials) — git-ignored, lives only on the box.
- `scrape/raw/` output — git-ignored.
- `vendor/` — built on the box from `composer.lock`.
