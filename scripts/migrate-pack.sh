#!/usr/bin/env bash
# On the OLD droplet, as root WITH a terminal (it asks for MySQL's root password): pack
# everything the new droplet needs into /root/migrate-2026/stage.tar.gz (mode 600).
#
#   ssh -t root@143.198.236.171 "bash /home/beanstalk/pick55/scripts/migrate-pack.sh"
#
# The pack holds both databases (pick and pick9's), both inc/_config.php files,
# /etc/letsencrypt, pick9's files (not vendor/), Pick55's scrape/raw, and exact row counts
# for checking the import. It goes box to box through the owner's machine without being
# stored there (scp -3), and the new box unpacks it with scripts/migrate-unpack.sh.
#
# Without --final, both sites stay up; the dump holds a global read lock for a few
# seconds. With --final (cutover), it first puts both sites into maintenance (503 with a
# short message) and stops cron, so nothing changes after the dump. It leaves them that
# way: the old box is retired after the cutover. To undo by hand:
#   a2disconf maintenance-2026; systemctl reload apache2; systemctl start cron
set -euo pipefail

OUT=/root/migrate-2026
FINAL=0
if [ "${1:-}" = "--final" ]; then
	FINAL=1
fi
if [ "$(id -u)" != 0 ]; then
	echo "run as root" >&2
	exit 1
fi
if [ ! -t 0 ]; then
	echo "needs a terminal for the MySQL password: ssh -t ..." >&2
	exit 1
fi

install -d -m 700 "$OUT"
CNF=$(mktemp)
chmod 600 "$CNF"
trap 'rm -f "$CNF" "$OUT/databases.sql" "$OUT/counts.txt"' EXIT
read -r -s -p "MySQL root password on the old box: " PW
echo
printf '[client]\nuser=root\npassword="%s"\n' "$PW" > "$CNF"
unset PW
my() { mysql --defaults-extra-file="$CNF" "$@"; }
my -e 'SELECT 1' >/dev/null

PICK9_DB=$(php -r 'include "/home/beanstalk/pick9/inc/_config.php"; echo $__config["db"]["database"];')
my -e "USE \`$PICK9_DB\`"
echo "databases: pick, $PICK9_DB"

if [ $FINAL = 1 ]; then
	echo "== final: maintenance on, cron off"
	cat > /etc/apache2/conf-available/maintenance-2026.conf <<'EOF'
# Cutover to the new droplet (pick55 scripts/migrate-pack.sh --final)
ErrorDocument 503 "Pick55 and Pick9 are moving to a new server. Back shortly."
RedirectMatch 503 ^/
EOF
	a2enconf -q maintenance-2026
	systemctl reload apache2
	systemctl stop cron
	sleep 5
	echo "GET https://pick55.com/: $(curl -s -o /dev/null -w '%{http_code}' --resolve pick55.com:443:127.0.0.1 https://pick55.com/) (want 503)"
fi

echo "== dump"
mysqldump --defaults-extra-file="$CNF" --default-character-set=utf8mb4 --lock-all-tables \
	--routines --triggers --events --hex-blob --skip-dump-date --databases pick "$PICK9_DB" > "$OUT/databases.sql"
for db in pick "$PICK9_DB"; do
	for t in $(my -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$db' AND table_type = 'BASE TABLE' ORDER BY table_name"); do
		echo "$db.$t $(my -N -e "SELECT COUNT(*) FROM \`$db\`.\`$t\`")"
	done
done > "$OUT/counts.txt"
echo "$(wc -l < "$OUT/counts.txt") tables counted; dump $(du -h "$OUT/databases.sql" | cut -f1)"

echo "== pack"
tar -czf "$OUT/stage.tar.gz" --exclude=home/beanstalk/pick9/vendor -C / \
	etc/letsencrypt \
	home/beanstalk/pick55/inc/_config.php \
	home/beanstalk/pick55/scrape/raw \
	home/beanstalk/pick9 \
	-C "$OUT" databases.sql counts.txt
chmod 600 "$OUT/stage.tar.gz"
ls -lh "$OUT/stage.tar.gz"
sha256sum "$OUT/stage.tar.gz"
if [ $FINAL = 1 ]; then
	echo "Old box is in maintenance with cron stopped. Next: copy the pack to the new box."
fi
