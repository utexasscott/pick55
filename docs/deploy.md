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
| PHP | **Apache runs PHP 7.4** (`mods-enabled/php7.4`). **The CLI `php` is 8.0.10.** Every version 5.6–8.0 is installed under `/etc/php`. Cron scripts therefore run under 8.0 unless invoked as `/usr/bin/php7.4`; code must stay valid on both. |
| MySQL | 5.7.42, local, database `pick`. |
| Tooling | git 2.17.1, Composer 2.1.6 at `/usr/local/bin/composer`. |
| Last deploy | `.revision` = **149**, files dated 2026-09-18 19:33. Beanstalk deploys were an export (no `.svn` directory), copied in as `beanstalk`. So SVN received r147–r149 after this repo's r146 import. Diffed 2026-09-24 (production tree vs. the import commit): they added declared properties on `App` and `Page`, `#[AllowDynamicProperties]` on `BaseModel` (PHP 8.2 readiness; inert on 7.4), bumped `illuminate/*` 8.58 → 8.61 in `composer.lock`, and added `season/week/raw.php`, a JSON dump of the pick week's games for a logged-in player. All four are now in git, so **git `main` is a superset of production** and the cutover below can proceed. |
| `inc/_config.php` on the box | exists, 397 bytes, dated 2021-09-13, **mode 644** — readable by every Unix account on the droplet, including `claude`. Claude does not read it. The owner should `chmod 640` it (Apache runs as `www-data`, so it needs group `www-data` or a `beanstalk`-owned PHP-FPM; check before tightening). |
| Old scrape cron | Ran daily at 02:00 in March 2022 (`scrape/raw/vegas-insider/*/2022-03-2x-02-00-01.html`, logs in `/home/beanstalk/logs/scrape/`). Stopped by 2023. Owned by `beanstalk`'s crontab, which `claude` cannot read. |
| What `claude` cannot do | `sudo`, read `/var/log/apache2`, read any crontab, write anywhere under `/home/beanstalk`. |

## The rule: commit freely, push on go

*Concept key: `PUSH_IS_DEPLOY`.*

- Claude commits to the local `main` **without asking**, in small commits, as work lands.
- Claude **never pushes to `origin`** on its own. A push is the deploy trigger, and the owner says when.
- Claude **suggests a push** at the end of any turn that leaves unpushed commits, so it is not forgotten.
- A push updates GitHub only. The droplet pulls when the owner runs the deploy command below, so "deploy" is two owner actions: push, then deploy.

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

**State:** cutover script written 2026-09-25, **not yet run** — see the state line below once it is.

The droplet's CLI `php` is 8.0 while Apache serves through 7.4, so `composer install` resolves under 8.0 and the site runs under 7.4. The lock file already satisfies both (it was regenerated on the droplet in SVN r147–149).

## What must never be in a deploy

- `inc/_config.php` (credentials) — git-ignored, lives only on the box.
- `scrape/raw/` output — git-ignored.
- `vendor/` — built on the box from `composer.lock`.
