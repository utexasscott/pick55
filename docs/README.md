# docs/ — how documentation works in this repo

Every document here has one of three shapes. Pick the shape before writing, and never mix them.

| Shape | What it is | Where it lives |
|---|---|---|
| **VERTICAL** | One service, page, skill, or process, described end to end. A reader who needs that one thing reads that one file. | `docs/<thing>.md` |
| **HORIZONTAL** | A cross-cutting concept identified by a `CONCEPT_KEY`. The key is grep-able: every place the concept applies carries `*Concept key: NAME*`, and the one-line definition lives in the concepts table. | table in `CLAUDE.md`; anchors in code and docs |
| **UBIQUITOUS** | A rule every session must know before doing anything. | `CLAUDE.md` |

Rules:

- **Write-back in the same turn.** The turn that changes a thing updates the doc that describes it, in the same commit.
- **Measured, not remembered.** A fact in a doc was read from the code, the server, or the owner, and says when. Anything unverified is marked **(unconfirmed — ask)**.
- **No correction banners.** Rewrite the sentence; do not append "UPDATE:" paragraphs.
- **No auto-memory.** Claude's file-based memory is disabled for this repo. Durable knowledge goes in `CLAUDE.md` or `docs/`.
- **Concepts table admission:** a concept earns a key only if more than one vertical doc or more than one session needs it. This project is small; expect single digits.

## Index

| Doc | Shape | Covers |
|---|---|---|
| [deploy.md](deploy.md) | VERTICAL (process) | The DigitalOcean droplet, how code reaches it, the push-is-deploy rule |
| [database-access.md](database-access.md) | VERTICAL (process) | Local and production MySQL access for Claude, grants, the live-write gate, schema change handling |
| [odds-scraper.md](odds-scraper.md) | VERTICAL (service) | The VegasInsider line scraper: the `VegasInsider` class and CLIs, page structure, parser rules, team matching, the Games from Scrape admin page, the cron |
| [results-cache.md](results-cache.md) | VERTICAL (page) | The week results page: what it computes, the fingerprint-keyed cache, the Gray-code enumeration, measurements |
| [week-formats.md](week-formats.md) | VERTICAL (concept + admin page) | Week formats and payout rows: the model, payout semantics, the 2026-09-25 seed of every historical week, the format admin page, applying the change to production |
| [all-time-stats.md](all-time-stats.md) | VERTICAL (page) | The `stats/` pages (Record Book, Wall of Fame, Wall of Shame, Leaderboards, Best Seasons): the rules behind every number, `AllTimeStats` and its cache, the ranked-table sort/filter behaviour |
| [live-scores.md](live-scores.md) | VERTICAL (service) | Live scores from ESPN: the `Espn` class, the two tables, game matching and the alias map, the result rule and sign convention, `scrape/live-scores.php` and its flags, the `season/week/live.php` JSON contract, the 10-minute cron |
| [login-sessions.md](login-sessions.md) | VERTICAL (process) | How a player stays signed in: `LOGIN_LIFETIME`, the sliding cookies, one remember-me token per device (`RememberToken`), production's 24-minute session purge, applying the table to production |
| [auto-picks.md](auto-picks.md) | VERTICAL (process) | Random picks for players who missed the kickoff: `Week::randomizeRemainingPicks()`, its guards (kicked off, recent, a game undecided), the lock, the record on the admin picks page, what it never touches |
| [../.claude/skills/pick-games/SKILL.md](../.claude/skills/pick-games/SKILL.md) | VERTICAL (skill) | `/pick-games`: how Claude chooses a week's 14 lines from the scrape (the pool's selection rules, the `scrape/slate.php` helper, the gated create) |
