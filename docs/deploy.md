# Deploy — the DigitalOcean droplet

**Shape: VERTICAL (process).** How code gets from this repo to production.

## Where production runs

- A DigitalOcean droplet. Hostname, IP, OS, web root, PHP version, and the deploying Unix user are all **(unconfirmed — ask)**.
- Until 2026-09-04 deploys ran from a Beanstalk SVN post-commit hook tagged `[deploy: pick55]`. That hook is dead: the SVN repo is no longer updated. **Whether the droplet still holds an SVN working copy at the web root is (unconfirmed — ask).**

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

Either way the droplet needs: a git clone at the web root (replacing the SVN checkout), `inc/_config.php` preserved in place (it is git-ignored, so a clone does not touch it), and `vendor/` built by composer on the box.

## What must never be in a deploy

- `inc/_config.php` (credentials) — git-ignored, lives only on the box.
- `scrape/raw/` output — git-ignored.
- `vendor/` — built on the box from `composer.lock`.
