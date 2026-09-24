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
| Last deploy | `.revision` = **149**, files dated 2026-09-18 19:33. Beanstalk deploys were an export (no `.svn` directory), copied in as `beanstalk`. So SVN received r147–r149 after this repo's r146 import; see [`svn-r147-149.md`](svn-r147-149.md) **(written once the diff is reconciled)**. |
| `inc/_config.php` on the box | exists, 397 bytes, dated 2021-09-13, **mode 644** — readable by every Unix account on the droplet, including `claude`. Claude does not read it. The owner should `chmod 640` it (Apache runs as `www-data`, so it needs group `www-data` or a `beanstalk`-owned PHP-FPM; check before tightening). |
| Old scrape cron | Ran daily at 02:00 in March 2022 (`scrape/raw/vegas-insider/*/2022-03-2x-02-00-01.html`, logs in `/home/beanstalk/logs/scrape/`). Stopped by 2023. Owned by `beanstalk`'s crontab, which `claude` cannot read. |
| What `claude` cannot do | `sudo`, read `/var/log/apache2`, read any crontab, write anywhere under `/home/beanstalk`. |

## The rule: commit freely, push on go

*Concept key: `PUSH_IS_DEPLOY`.*

- Claude commits to the local `main` **without asking**, in small commits, as work lands.
- Claude **never pushes to `origin`** on its own. A push is the deploy trigger, and the owner says when.
- Claude **suggests a push** at the end of any turn that leaves unpushed commits, so it is not forgotten.
- Until the droplet-side pull mechanism exists (below), a push only updates GitHub; the owner still has to pull on the droplet.

## Target mechanism (not yet built)

The simplest replacement for the Beanstalk hook, in order of preference:

1. **Pull on the droplet, triggered by a GitHub Actions workflow** that SSHes in on push to `main` and runs `git pull --ff-only` plus `composer install --no-dev`. Needs a deploy key on the droplet and an SSH private key in the repo's GitHub secrets.
2. **Manual**: the owner runs the same two commands over SSH. Works today with zero setup once the droplet has a git checkout.

Either way the droplet needs: a git clone at `/home/beanstalk/pick55` (replacing the Beanstalk export), `inc/_config.php` preserved in place (it is git-ignored, so a clone does not touch it), and `vendor/` built by composer on the box. The pull runs as `beanstalk` (the tree's owner), never as `claude`.

Cutover steps, once r147–r149 are reconciled into git: `mv /home/beanstalk/pick55 /home/beanstalk/pick55.svn-149`, `git clone https://github.com/utexasscott/pick55.git /home/beanstalk/pick55`, copy `inc/_config.php` and `scrape/raw/` back from the old tree, `composer install --no-dev`, load the site. Rollback is renaming the directories back.

## What must never be in a deploy

- `inc/_config.php` (credentials) — git-ignored, lives only on the box.
- `scrape/raw/` output — git-ignored.
- `vendor/` — built on the box from `composer.lock`.
