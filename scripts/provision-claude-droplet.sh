#!/usr/bin/env bash
# Provision Claude's access on the pick55 droplet. Run ONCE as root:
#
#   bash /root/provision-claude-droplet.sh /root/claude.pub pick
#
# Creates:
#   - Unix user `claude` (no password, SSH key only) from the given public key file
#   - MySQL user claude@localhost and claude@127.0.0.1 with SELECT/INSERT/UPDATE/DELETE
#     on every table of the given database EXCEPT er_users (PII_TABLE_EXCLUDED)
#   - /home/claude/.my.cnf holding the generated MySQL password (mode 600), so the
#     password never travels through chat; Claude reads it over SSH as `claude`.
#
# Safe to re-run: every step is idempotent.
set -euo pipefail

PUBKEY_FILE="${1:?usage: $0 <public-key-file> <database-name>}"
DB="${2:?usage: $0 <public-key-file> <database-name>}"
USER=claude

[ -r "$PUBKEY_FILE" ] || { echo "cannot read $PUBKEY_FILE" >&2; exit 1; }
command -v mysql >/dev/null || { echo "mysql client not found on this box; is MySQL local?" >&2; exit 1; }
mysql -e "USE \`$DB\`" || { echo "database $DB not found" >&2; exit 1; }

echo "== Unix user"
if ! id -u "$USER" >/dev/null 2>&1; then
	adduser --disabled-password --gecos "" "$USER"
fi
install -d -m 700 -o "$USER" -g "$USER" "/home/$USER/.ssh"
install -m 600 -o "$USER" -g "$USER" "$PUBKEY_FILE" "/home/$USER/.ssh/authorized_keys"

echo "== MySQL user"
if [ -f "/home/$USER/.my.cnf" ]; then
	PW=$(sed -n 's/^password=//p' "/home/$USER/.my.cnf")
else
	PW=$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-28)
fi
for HOST in localhost 127.0.0.1; do
	mysql -e "CREATE USER IF NOT EXISTS '$USER'@'$HOST' IDENTIFIED BY '$PW';"
	mysql -e "ALTER USER '$USER'@'$HOST' IDENTIFIED BY '$PW';"
	mysql -e "REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$USER'@'$HOST';"
done
mysql -N -e "SELECT CONCAT('GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB\`.\`', table_name, '\` TO ''$USER''@''localhost'', ''$USER''@''127.0.0.1'';') FROM information_schema.tables WHERE table_schema='$DB' AND table_type='BASE TABLE' AND table_name <> 'er_users';" | mysql
mysql -e "FLUSH PRIVILEGES;"

printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\n' "$USER" "$PW" > "/home/$USER/.my.cnf"
chown "$USER:$USER" "/home/$USER/.my.cnf"
chmod 600 "/home/$USER/.my.cnf"

echo "== Verify"
sudo -u "$USER" mysql "$DB" -e "SHOW TABLES;" | head -5
echo "(er_users must fail below)"
sudo -u "$USER" mysql "$DB" -e "SELECT COUNT(*) FROM er_users;" && { echo "ERROR: er_users is readable" >&2; exit 1; } || echo "OK: er_users denied"
echo "== Web root hint (for the deploy doc)"
ls -ld /var/www/* 2>/dev/null || true
echo "done"
