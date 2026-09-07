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
| Pick55 database | **does not exist yet** — needs a dump from production |
| Client | no `mysql` CLI on PATH; use PHP (`php -r` with mysqli) or the app's own Eloquent connection |
| Web | Apache (wamp64) serves `c:\wamp\www` as document root, so the app is at `http://127.0.0.1/pick55/` |

Once a dump exists: create database `pick55`, load the dump, then copy `inc/_config.example.php` to `inc/_config.php` with `db.database = 'pick55'`.

## Production (the droplet) — not yet provisioned

Design, mirroring what the owner already runs on other servers: a dedicated Unix account for Claude, key-only SSH, and a MySQL account reached over an SSH tunnel to `127.0.0.1:3306`, so nothing new is exposed to the internet.

Everything in this section is **(unconfirmed — ask)** until provisioned.

### Owner-side provisioning

```bash
# 1. Unix account for Claude (no password, key only)
sudo adduser --disabled-password claude
sudo -u claude mkdir -m 700 /home/claude/.ssh
# paste the public key Claude hands over into /home/claude/.ssh/authorized_keys (mode 600)
```

```sql
-- 2. MySQL account: read/write on everything except er_users.
--    MySQL has no "all but one table" grant, so grant per table.
--    Host is 127.0.0.1: the SSH tunnel arrives as TCP loopback, not the unix socket.
CREATE USER 'claude'@'127.0.0.1' IDENTIFIED BY '<generate>';
-- run the output of this against the server:
SELECT CONCAT('GRANT SELECT, INSERT, UPDATE, DELETE ON `', table_schema, '`.`', table_name, '` TO ''claude''@''127.0.0.1'';')
FROM information_schema.tables
WHERE table_schema = '<pick55_db_name>' AND table_name <> 'er_users';
-- schema changes are rare; grant ALTER/CREATE/DROP only when a change is scheduled, then revoke.
FLUSH PRIVILEGES;
```

### Claude-side

- `~/.ssh/config` entry `pick55` → droplet IP, user `claude`, key `~/.ssh/claude_pick55_droplet`. The key pair does not exist yet; the owner generates it, because Claude's harness blocks writes under `~/.ssh`:
  `ssh-keygen -t ed25519 -N "" -C claude@pick55-droplet -f ~/.ssh/claude_pick55_droplet`
- Tunnel: `ssh -N -L 3307:127.0.0.1:3306 pick55`, then connect to `127.0.0.1:3307` as `claude`.
- The MySQL password lives only in a git-ignored local file **(file name decided at provisioning)**.

## Schema changes — no migration framework

Agreed 2026-09-07: this project does not need a migrations framework. Instead:

- `db/schema.sql` — a `mysqldump --no-data` snapshot of production, committed, refreshed whenever the schema changes. **Does not exist yet; produced from the first dump.**
- `db/changes/YYYY-MM-DD-short-name.sql` — each schema change as a hand-applied file, committed with the code that needs it. Applying one to production goes through `LIVE_WRITE_GATE` like any other write.
- A change file is applied locally first, then to production on go, then `schema.sql` is re-snapshotted.
