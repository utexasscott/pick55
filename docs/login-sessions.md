# Login sessions and "stay signed in"

**Shape: VERTICAL (one process).** How a player stays signed in, why the lifetime is what it is, and what production does to sessions behind the app's back.

## The rule

A player stays signed in for `LOGIN_LIFETIME` (30 days, defined in `inc/_inc.php`) past their most recent visit, on every device they signed in on, until they sign out on that device. Owner's brief (2026-09-26): "stay logged in longer, like a month", after a report that production signed them out "after a few hours, not 7 days".

## Pieces

| Piece | File | Does |
|---|---|---|
| Lifetime | `inc/_inc.php` (`LOGIN_LIFETIME`) | One constant for the session cookies, the server-side session (`session.gc_maxlifetime`, see "Production" for why that one is moot) and the remember-me cookie. |
| Session cookies | `inc/_inc.php` | `session_id` and `fingerprint`, HttpOnly, re-sent on every request with a fresh expiry, so they slide. `fingerprint` is a constant (`md5('PICK55')`), a leftover check that never fails. |
| Remember-me | `Pick55\Auth` | `attemptCookieLogin()` runs on every request (`App::__construct`). Signed in: re-sends the `remember_token` cookie so it slides. Not signed in but carrying the cookie: looks the token up, marks it used, sets `$_SESSION[SKEY]['user_id']`. `setAuthedUserId()` (a password login) issues a new token for this device. `setNoAuth()` (logout) deletes this device's token and expires the cookie. |
| Token rows | `Pick55\Models\RememberToken`, table `er_users_remember_tokens` | One row per signed-in device: `er_user_id`, `token` (40-char `Auth::token()`), `created_at`, `last_used_at`. `findValid()` (deletes an expired row on the way past), `issue()` (creates a row and prunes expired rows), `markUsed()`, `prune()`, `adoptLegacy()`. |
| Schema | `db/changes/2026-09-26-remember-tokens.sql` | `CREATE TABLE IF NOT EXISTS`, InnoDB like `er_users_friends`; rollback `DROP` in the header. Applied locally 2026-09-26; `db/schema.sql` re-snapshotted. **Not granted to Claude's droplet MySQL account**: the rows are credentials. |

Tokens are never rotated. A device keeps the token it was issued until it signs out or goes unused for `LOGIN_LIFETIME`.

## History, and why per-device

Until 2026-09-26 the token was one column, `er_users.remember_token`, and every cookie login rotated it (new token, new cookie). Combined with production's 24-minute session purge (below), nearly every visit after a short break was a cookie login, so two devices signed in as the same player took turns invalidating each other's token. That is the "signed out after a few hours" the owner saw. The 7-day cookie was never the limit that bit.

The column still exists and is now unused. `RememberToken::adoptLegacy()` accepts a cookie carrying a pre-change token (matched against the column), moves it into the table and nulls the column, so nobody had to sign in again over the deploy. It can be removed after 2026-10-26, when every 7-day legacy cookie has expired; the column can be dropped then too (an `ALTER TABLE er_users`, so a change file the owner applies, since `er_users` is outside Claude's grant).

## Production: sessions live 24 minutes, and that is fine

Measured 2026-09-26 on the droplet (`/etc/php/7.4/apache2/php.ini`): `session.gc_probability = 0`, `session.gc_maxlifetime = 1440`, and `/etc/cron.d/php` runs `/usr/lib/php/sessionclean` at :09 and :39 every hour. That cron reads the ini file, not the app's runtime `ini_set`, so a server-side session on production is purged 24 to 54 minutes after its last request whatever `inc/_inc.php` asks for. The app is built for that: the session only carries `user_id` and flash messages, and the remember-me cookie signs the player straight back in on the next request. Raising the ini value would only keep more session files around. Locally (WampServer) the ini has `gc_probability = 1`, so the runtime value does apply there.

## Verified 2026-09-26 (local Apache, test player 2063)

- Sign-in and every later request return `session_id`, `fingerprint` and `remember_token` with `Max-Age=2592000`, HttpOnly; a request carrying only `remember_token` (session cookies dropped, as the production purge does) gets the signed-in page.
- Two cookie jars signed in as the same player, each purged and revisited in turn, both stayed signed in with their own tokens; signing out on one deleted only its row and the other stayed signed in.
- A cookie carrying a planted `er_users.remember_token` value was adopted (row created, column nulled); a row aged 31 days was refused and deleted; an unknown token was refused. Logout sends `remember_token=deleted; Max-Age=0` and the next guarded request redirects to login.

## Applying to production

Order matters (*Concept key: `ORDERED_HANDOVER`*): the table first, because the deployed code queries it on the first request from any player carrying a remember-me cookie, and a missing table there is a fatal error on every page. Owner, PowerShell:

1. Copy the change file to the droplet:
   ```powershell
   scp C:\wamp\www\pick55\db\changes\2026-09-26-remember-tokens.sql root@143.198.236.171:/root/
   ```
2. Create the table (prompts for the MySQL root password; *Concept key: `LIVE_WRITE_GATE`*, this is the shown change):
   ```powershell
   ssh -t root@143.198.236.171 "mysql -u root -p pick < /root/2026-09-26-remember-tokens.sql"
   ```
3. Push and deploy ([deploy.md](deploy.md)).
