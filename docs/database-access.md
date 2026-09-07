# Database access — local and production

**Shape: VERTICAL (process).** How Claude reads and writes the Pick55 MySQL databases, and the one rule that governs writes to production.

## The rule: no live write without a shown query and a go

*Concept key: `LIVE_WRITE_GATE`.*

Claude may run any read (`SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE`) against production at will. Before any statement that changes production data or schema (`INSERT`, `UPDATE`, `DELETE`, `ALTER`, `CREATE`, `DROP`, `TRUNCATE`, `GRANT`), Claude:

1. prints the **exact** SQL it intends to run, with real values, not placeholders;
2. states the expected row count and how it will verify afterwards;
3. waits for an explicit go from the owner **in a later turn**. A go given for one batch does not cover the next.

Local database writes need no go.

## The PII boundary

*Concept key: `PII_TABLE_EXCLUDED`.*

`er_users` holds names and email addresses. Claude's production MySQL account is granted on every table **except** `er_users`. The app keeps working because it connects with its own credential; Claude's account simply cannot read that table. When Claude needs a user's id for a query, the owner supplies it.

If a task turns out to need `er_users` columns, the fix is a view exposing only `id` and `is_admin` (no name or email) granted to Claude's account, not a wider grant.

## Local (this workstation) — measured 2026-09-07

| Fact | Value |
|---|---|
| Server | WampServer MySQL 5.7.44, service `wampmysqld64`, port 3306 |
| Credentials | `root`, empty password |
| Pick55 database | `pick`, loaded 2026-09-07 from a full production dump (`C:\Users\utexa\Downloads\pick.sql`, contains `er_users` PII, never copy it into the repo) |
| Client | `C:\wamp64\bin\mysql\mysql5.7.44\bin\mysql.exe` and `mysqldump.exe` (not on PATH); PHP mysqli also works |
| Web | Apache (wamp64) serves `c:\wamp\www` as document root, so the app is at `http://127.0.0.1/pick55/` — renders as of 2026-09-07 |
| Config | `inc/_config.php` exists locally (git-ignored): root/no password, database `pick`, mail redirected to the owner |

Schema snapshot: `db/schema.sql` is produced by

```
C:\wamp64\bin\mysql\mysql5.7.44\bin\mysqldump.exe -h 127.0.0.1 -u root --no-data --skip-dump-date --skip-comments pick
```

with `AUTO_INCREMENT=N` stripped. Re-run after every schema change.

## Production (the droplet `143.198.236.171`)

Design: a dedicated Unix account `claude` on the droplet (key-only SSH), and a MySQL account `claude` granted per table on everything except `er_users`. The MySQL password is generated **on the droplet** and stored only in `/home/claude/.my.cnf` (mode 600), so it never passes through chat and Claude never holds it locally. Claude runs queries as `ssh pick55 mysql pick -e "..."`; for anything needing a local client (Eloquent scripts), an SSH tunnel `-L 3307:127.0.0.1:3306` works with the same account.

**State: not yet provisioned (2026-09-07).** The provisioning is one script, [`scripts/provision-claude-droplet.sh`](../scripts/provision-claude-droplet.sh), idempotent, run once as root. Owner-side steps (PowerShell, absolute paths):

```powershell
scp C:\wamp\www\pick55\scripts\provision-claude-droplet.sh root@143.198.236.171:/root/
scp C:\Users\utexa\.ssh\claude_pick55_droplet.pub root@143.198.236.171:/root/claude.pub
ssh root@143.198.236.171 "bash /root/provision-claude-droplet.sh /root/claude.pub pick"
```

(If the production database is not named `pick`, replace the last argument. If the owner logs in as a non-root user, replace `root@` and prefix the bash command with `sudo`.)

Then add the SSH host entry Claude will use. Claude's harness blocks writes under the owner's `.ssh` directory, so the owner runs:

```powershell
Add-Content -Path C:\Users\utexa\.ssh\config -Value "`nHost pick55`n    HostName        143.198.236.171`n    User            claude`n    IdentityFile    C:\Users\utexa\.ssh\claude_pick55_droplet`n    IdentitiesOnly  yes"
```

Verification, from the workstation: `ssh pick55 "mysql pick -e 'SHOW TABLES'"` lists tables, and `ssh pick55 "mysql pick -e 'SELECT COUNT(*) FROM er_users'"` is denied.

Schema changes (`ALTER`, `CREATE`, `DROP`) are deliberately **not** granted. When a `db/changes/` file is due, the owner applies it as root after the `LIVE_WRITE_GATE` go, or grants the DDL privilege for that one sitting and revokes it after.

## Schema changes — no migration framework

Agreed 2026-09-07: this project does not need a migrations framework. Instead:

- `db/schema.sql` — a `mysqldump --no-data` snapshot, committed, refreshed whenever the schema changes. First snapshot taken 2026-09-07 from the production dump.
- `db/changes/YYYY-MM-DD-short-name.sql` — each schema change as a hand-applied file, committed with the code that needs it. Applying one to production goes through `LIVE_WRITE_GATE` like any other write.
- A change file is applied locally first, then to production on go, then `schema.sql` is re-snapshotted.
