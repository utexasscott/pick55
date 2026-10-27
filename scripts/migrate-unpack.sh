#!/usr/bin/env bash
# On the NEW droplet, as root: put a pack from migrate-pack.sh in place.
#
#   sudo bash /home/beanstalk/pick55/scripts/migrate-unpack.sh /root/stage.tar.gz [--final]
#
# --final (cutover, after migrate-pack.sh --final) also installs beanstalk's crontab, so
# the new box starts scraping and scoring. Without it the staging box runs no cron.
#
# Safe to re-run with a newer pack (the final one at cutover): both databases are dropped
# and re-imported, and files are replaced. Steps:
# - /etc/letsencrypt, pick9's files and Pick55's scrape/raw go in place.
# - Both inc/_config.php files go in place, mode 640 beanstalk:www-data.
# - Each site gets its own MySQL user. A config that logged in as root gets a new user
#   (pick55 or pick9) and a generated password, written into the config; otherwise its own
#   user and password are recreated. Nothing secret is printed.
# - Pick55's config gets 'error_reporting' => E_ALL & ~E_DEPRECATED (docs/droplet-migration.md).
# - Claude's MySQL account: SELECT/INSERT/UPDATE/DELETE on every pick table except
#   er_users, password in /home/claude/.my.cnf, as on the old box.
# - Row counts are compared with the old box's; pick9 gets Pick55's composer.lock when the
#   two composer.json files match; then provision-new-droplet.sh re-runs to write the
#   HTTPS vhosts.
set -euo pipefail

TAR=${1:-/root/stage.tar.gz}
FINAL=0
if [ "${2:-}" = "--final" ]; then
	FINAL=1
fi
OWNER=beanstalk
H=/home/$OWNER
ST=/root/migrate-2026-stage
if [ "$(id -u)" != 0 ]; then
	echo "run as root" >&2
	exit 1
fi
[ -f "$TAR" ] || { echo "no pack at $TAR" >&2; exit 1; }

echo "== unpack $(sha256sum "$TAR" | cut -c1-16)"
rm -rf "$ST"
install -d -m 700 "$ST"
tar -xzf "$TAR" -C "$ST"

echo "== files"
rm -rf /etc/letsencrypt
cp -a "$ST/etc/letsencrypt" /etc/letsencrypt
rsync -a --delete --exclude vendor/ --exclude inc/_config.php "$ST/home/beanstalk/pick9/" "$H/pick9/"
# pick9 is not in git and hardcodes error_reporting(E_ALL): on PHP 8.2+ it logs dynamic
# property and illuminate 8 deprecations on every request and every 5-minute cron run.
sed -i 's/^error_reporting(E_ALL);/error_reporting(E_ALL \& ~E_DEPRECATED);/' "$H/pick9/inc/_inc.php"
chown -R $OWNER:$OWNER "$H/pick9"
for d in "$H/pick9/scrape" "$H/pick9/scrape/raw"; do
	[ -d "$d" ] && chown $OWNER:www-data "$d" && chmod 2775 "$d"
done
rsync -a "$ST/home/beanstalk/pick55/scrape/raw/" "$H/pick55/scrape/raw/"
chown -R $OWNER:www-data "$H/pick55/scrape/raw"
for site in pick55 pick9; do
	install -m 640 -o $OWNER -g www-data "$ST/home/beanstalk/$site/inc/_config.php" "$H/$site/inc/_config.php"
done

echo "== databases"
DBS=$(sed -n 's/^CREATE DATABASE .*`\([^`]*\)`.*/\1/p' "$ST/databases.sql")
for db in $DBS; do
	mysql -e "DROP DATABASE IF EXISTS \`$db\`"
done
mysql --default-character-set=utf8mb4 --show-warnings < "$ST/databases.sql" > "$ST/import.log" 2>&1 || {
	echo "import failed:" >&2
	grep -v -E '^(Warning|Level|Note)' "$ST/import.log" | head -20 >&2
	exit 1
}
echo "imported: $DBS; warnings by code:"
grep -o -E 'Warning \(Code [0-9]+\)' "$ST/import.log" | sort | uniq -c | sed 's/^/  /' || true

echo "== app users"
php <<'PHP' | mysql
<?php
// For each site: keep its own MySQL user, or replace root with a dedicated one. Prints SQL
// on stdout (piped into mysql); never echoes a password to the terminal.
$home = '/home/beanstalk';
foreach (['pick55', 'pick9'] as $site) {
	$file = "$home/$site/inc/_config.php";
	$src = file_get_contents($file);
	$__config = null;
	include $file;
	$db = $__config['db'];
	$user = $db['username'];
	$pass = $db['password'];
	if ($user === 'root' || $user === '') {
		$user = $site;
		$pass = bin2hex(random_bytes(18));
		$src = preg_replace("/('username'\s*=>\s*)'[^']*'/", "\${1}'$user'", $src, 1);
		$src = preg_replace("/('password'\s*=>\s*)'[^']*'/", "\${1}'$pass'", $src, 1);
	}
	if ($site === 'pick55' && strpos($src, "'error_reporting'") === false) {
		$src = preg_replace('/(\$__config\s*=\s*\[\s*\n)/', "\$1\t'error_reporting' => E_ALL & ~E_DEPRECATED,\n", $src, 1);
	}
	file_put_contents($file, $src);
	$u = str_replace(["\\", "'"], ["\\\\", "\\'"], $user);
	$p = str_replace(["\\", "'"], ["\\\\", "\\'"], $pass);
	$d = str_replace('`', '', $db['database']);
	foreach (['localhost', '127.0.0.1'] as $host) {
		echo "CREATE USER IF NOT EXISTS '$u'@'$host' IDENTIFIED BY '$p';\n";
		echo "ALTER USER '$u'@'$host' IDENTIFIED BY '$p';\n";
		echo "GRANT ALL PRIVILEGES ON `$d`.* TO '$u'@'$host';\n";
	}
	fwrite(STDERR, "$site: database {$db['database']}, user $user" . ($user === $db['username'] ? '' : ' (was root)') . "\n");
}
PHP
for site in pick55 pick9; do
	chown $OWNER:www-data "$H/$site/inc/_config.php"
	chmod 640 "$H/$site/inc/_config.php"
done

echo "== claude's MySQL account (no er_users)"
CL=/home/claude/.my.cnf
if [ -f $CL ]; then
	CPW=$(sed -n 's/^password=//p' $CL)
else
	CPW=$(openssl rand -hex 16)
fi
for host in localhost 127.0.0.1; do
	mysql -e "CREATE USER IF NOT EXISTS 'claude'@'$host' IDENTIFIED BY '$CPW'; ALTER USER 'claude'@'$host' IDENTIFIED BY '$CPW'; REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'claude'@'$host';"
done
mysql -N -e "SELECT CONCAT('GRANT SELECT, INSERT, UPDATE, DELETE ON \`pick\`.\`', table_name, '\` TO ''claude''@''localhost'', ''claude''@''127.0.0.1'';') FROM information_schema.tables WHERE table_schema = 'pick' AND table_type = 'BASE TABLE' AND table_name <> 'er_users'" | mysql
printf '[client]\nuser=claude\npassword=%s\nhost=127.0.0.1\n' "$CPW" > $CL
chown claude:claude $CL
chmod 600 $CL
unset CPW
if sudo -H -u claude mysql pick -e 'SELECT COUNT(*) FROM er_users' >/dev/null 2>&1; then
	echo "ERROR: claude can read er_users" >&2
	exit 1
fi
echo "claude: $(sudo -H -u claude mysql -N pick -e 'SELECT COUNT(*) FROM football_games') games readable; er_users denied"

echo "== row counts against the old box"
bad=0
while read -r name n_old; do
	db=${name%%.*}
	t=${name#*.}
	n_new=$(mysql -N -e "SELECT COUNT(*) FROM \`$db\`.\`$t\`")
	if [ "$n_new" != "$n_old" ]; then
		echo "  MISMATCH $name: old $n_old, new $n_new"
		bad=1
	fi
done < "$ST/counts.txt"
echo "$(wc -l < "$ST/counts.txt") tables compared; mismatches: $bad"

echo "== pick9 libraries"
if cmp -s "$H/pick9/composer.json" "$H/pick55/composer.json"; then
	cp "$H/pick55/composer.lock" "$H/pick9/composer.lock"
	chown $OWNER:$OWNER "$H/pick9/composer.lock"
	sudo -u $OWNER -H composer install --no-dev --no-interaction --prefer-dist --no-progress -d "$H/pick9" 2>&1 | tail -1
else
	echo "pick9's composer.json differs from Pick55's; vendor/ not installed" >&2
fi

if [ $FINAL = 1 ]; then
	echo "== beanstalk's crontab (the old box's five lines, as listed 2026-10-22)"
	crontab -u $OWNER - <<'EOF'
# pick55 and pick9 (pick55 scripts/migrate-unpack.sh --final). php is /usr/bin/php (8.5).
15 20 * * 1 /usr/bin/php /home/beanstalk/pick55/scrape/run.php >> /home/beanstalk/logs/scrape/run.log 2>&1
0 8 * * 2 /usr/bin/php /home/beanstalk/pick55/scrape/run.php >> /home/beanstalk/logs/scrape/run.log 2>&1
*/10 * * * * /usr/bin/php /home/beanstalk/pick55/scrape/live-scores.php >> /home/beanstalk/logs/scrape/live-scores.log 2>&1
*/5 * * * * cd ~/pick9/scrape && php get-raw.php >> ~/logs/pick9/get-raw.log 2>&1
*/5 * * * * cd ~/pick9/scrape && php parse-raw.php >> ~/logs/pick9/parse-raw.log 2>&1
EOF
	crontab -u $OWNER -l | grep -c -v '^#' | sed 's/$/ cron lines installed/'
fi

echo "== Apache with HTTPS"
bash "$H/pick55/scripts/provision-new-droplet.sh" 2>&1 | sed -n '/^== check/,$p'
rm -rf "$ST"
echo "done; the pack is still at $TAR"
