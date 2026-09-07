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
| [odds-scraper.md](odds-scraper.md) | VERTICAL (service) | The VegasInsider line scraper: history, current page structure, the rebuild plan |
