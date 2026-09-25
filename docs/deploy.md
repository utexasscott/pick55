# Deploy — the DigitalOcean droplet

**Shape: VERTICAL (process).** How code gets from this repo to production.

## Where production runs

Measured 2026-09-24 over SSH as `claude`, except where marked.

| Fact | Value |
|---|---|
| Droplet | DigitalOcean, hostname `-s-1vcpu-1gb-sfo3-01`, public IPv4 `143.198.236.171`, private `10.124.0.2` (owner, 2026-09-07). 1 vCPU, 985 MB RAM, 25 GB disk (36% used). |
| OS | Ubuntu 18.04.6 LTS. Clock is US Central (`CDT`). |
| Web server | Apache 2, vhosts `pick55.com` (+ `www`, Let's Encrypt TLS) and `pick9.golf`. `AllowOverride All`, so `.htaccess` is honoured. |
| Web root | `DocumentRoot /var/www/pick55.com`, a symlink to **`/home/beanstalk/pick55`**, owned by `beanstalk:beanstalk`, mode 775. |
| PHP | **Apache runs PHP 7.4** (`mods-enabled/php7.4`). **The CLI `php` is 8.0.10.** Every version 5.6–8.0 is installed under `/etc/php`. CLI work (cron, scripts) uses 8.0: `php7.4-xml` is not installed, so no 7.4 SAPI (CLI or Apache) has `dom`, and the 7.4 CLI warns on `pdo_mysql` at startup (measured 2026-09-25); `/usr/bin/php` (8.0) has `dom`, `curl`, `mysqli`, `mysqlnd`, `pdo_mysql`. The 7.4 packages came from the ondrej PPA `bionic` channel, which apt no longer resolves, so 7.4 cannot gain extensions. Code must stay valid on both, and anything needing `dom` under the web server has to shell out to 8.0 (see [odds-scraper.md](odds-scraper.md)). |
| MySQL | 5.7.42, local, database `pick`. |
| Tooling | git 2.17.1, Composer 2.1.6 at `/usr/local/bin/composer`. |
| Last SVN deploy | `.revision` = **149**, files dated 2026-09-18 19:33 (superseded by the git cutover, 2026-09-25). Beanstalk deploys were an export (no `.svn` directory), copied in as `beanstalk`. SVN received r147–r149 after this repo's r146 import. Diffed 2026-09-24 (production tree vs. the import commit): they added declared properties on `App` and `Page`, `#[AllowDynamicProperties]` on `BaseModel` (PHP 8.2 readiness; inert on 7.4), bumped `illuminate/*` 8.58 → 8.61 in `composer.lock`, and added `season/week/raw.php`, a JSON dump of the pick week's games for a logged-in player. All four are now in git, so **git `main` is a superset of production** and the cutover below can proceed. |
| `inc/_config.php` on the box | 397 bytes, `beanstalk:www-data` mode 640 since the 2026-09-25 cutover (was 644 and world-readable under the SVN export). Apache reads it through the `www-data` group. |
| Scrape cron | Owned by `beanstalk`'s crontab, which `claude` cannot read. The 2022 line ran daily at 02:00 and stopped by 2023; the replacement (Monday 20:15 + Tuesday 08:00, `scrape/run.php` under `/usr/bin/php`) is specified in [odds-scraper.md](odds-scraper.md), "The cron". |
| What `claude` cannot do | `sudo`, read `/var/log/apache2`, read any crontab, write anywhere under `/home/beanstalk`. |

## The rule: commit freely, push on go

*Concept key: `PUSH_IS_DEPLOY`.*

- Claude commits to the local `main` **without asking**, in small commits, as work lands.
- Claude **never pushes to `origin`** on its own. A push is the deploy trigger, and the owner says when.
- Claude ends any turn that leaves unpushed commits by **handing the owner both commands** below, ready to paste (owner, 2026-09-25).
- A push updates GitHub only. The droplet pulls when the owner runs the deploy command below, so "deploy" is two owner actions: push, then deploy.

```powershell
git -C C:\wamp\www\pick55 push origin main
ssh root@143.198.236.171 "bash /home/beanstalk/pick55/scripts/deploy.sh"
```

## Mechanism

The web root is a git clone of `origin/main` at `/home/beanstalk/pick55`, owned by `beanstalk`. Two scripts, both in `scripts/`:

| Script | Runs as | When | Does |
|---|---|---|---|
| [`cutover-to-git.sh`](../scripts/cutover-to-git.sh) | root, once | replacing the Beanstalk export | parks the export at `pick55.svn-149`, clones, carries over `inc/_config.php` (mode 640, group `www-data`) and `scrape/raw/`, `composer install --no-dev`, creates `cache/` owned by `www-data`, curls three URLs. Header holds the rollback line. |
| [`deploy.sh`](../scripts/deploy.sh) | `beanstalk` (root re-execs as it) | after every push | `git fetch` + `merge --ff-only origin/main`; `composer install --no-dev` only if `composer.lock` changed; prints the commits deployed. Refuses non-fast-forward. |

Deploy command the owner runs after a push:

```powershell
ssh root@143.198.236.171 "bash /home/beanstalk/pick55/scripts/deploy.sh"
```

`claude` cannot run either script (no write access to the tree, no sudo). Automating the pull from a GitHub Actions workflow is possible later (a deploy key on the droplet plus an SSH key in the repo secrets) and is not built.

**State:** cutover run by the owner 2026-09-25 15:12 CDT, verified from `claude` the same hour: `/home/beanstalk/pick55` is a clone at `4262ad7`, `inc/_config.php` is `beanstalk:www-data` mode 640 (no longer readable by `claude`), `cache/` is `www-data:beanstalk` 775, `scrape/raw/` carried over, `/` and `/rules.php` return 200. The SVN export is parked at `/home/beanstalk/pick55.svn-149` and can be deleted once a few deploys have gone through. `git` commands run as `claude` in that tree fail with *dubious ownership*; that is expected and harmless.

The droplet's CLI `php` is 8.0 while Apache serves through 7.4, so `composer install` resolves under 8.0 and the site runs under 7.4. The lock file already satisfies both (it was regenerated on the droplet in SVN r147–149).

## What must never be in a deploy

- `inc/_config.php` (credentials) — git-ignored, lives only on the box.
- `scrape/raw/` output — git-ignored.
- `vendor/` — built on the box from `composer.lock`.
