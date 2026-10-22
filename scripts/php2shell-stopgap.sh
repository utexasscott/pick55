#!/usr/bin/env bash
# PHP2Shell stopgap (CVE-2026-109618 / CVE-2026-109619, disclosed 2026-10-21) for every
# site this Apache serves. Run as root:
#
#   ssh root@143.198.236.171 "bash /home/beanstalk/pick55/scripts/php2shell-stopgap.sh"
#
# Apache runs PHP 7.4.23 under mod_php and no patched 7.4 package exists for this Ubuntu
# 18.04 box, so until PHP is replaced this refuses every multipart/form-data request on
# every vhost and alias (pick9.golf and phpMyAdmin too) before PHP reads it, since every
# known trigger is one, and turns session.upload_progress off for the Apache SAPI. Then it
# restarts Apache and checks both from this box. Not a fix; see docs/deploy.md.
#
# --undo removes both and restarts Apache.
set -euo pipefail

CONF=/etc/apache2/conf-available/php2shell.conf
INI=/etc/php/7.4/apache2/conf.d/99-php2shell.ini

if [ "$(id -u)" != 0 ]; then
	echo "run as root" >&2
	exit 1
fi

if [ "${1:-}" = "--undo" ]; then
	a2disconf -q php2shell || true
	rm -f "$CONF" "$INI"
	apache2ctl configtest
	systemctl restart apache2
	echo "stopgap removed"
	exit 0
fi

cat > "$CONF" <<'EOF'
# PHP2Shell stopgap from pick55 scripts/php2shell-stopgap.sh; remove once PHP is patched.
<If "%{HTTP:Content-Type} =~ /multipart\/form-data/i">
	Require all denied
</If>
EOF
cat > "$INI" <<'EOF'
; PHP2Shell stopgap from pick55 scripts/php2shell-stopgap.sh; remove once PHP is patched.
session.upload_progress.enabled = 0
EOF
a2enconf -q php2shell
if ! apache2ctl configtest; then
	a2disconf -q php2shell || true
	rm -f "$CONF" "$INI"
	echo "config test failed; removed the stopgap, Apache untouched" >&2
	exit 1
fi
systemctl restart apache2

progress=$(PHP_INI_SCAN_DIR=/etc/php/7.4/apache2/conf.d php7.4 -c /etc/php/7.4/apache2/php.ini \
	-r 'echo var_export(ini_get("session.upload_progress.enabled"), true);' 2>/dev/null)
echo "session.upload_progress.enabled for Apache: $progress (want '0')"
for url in https://pick55.com/ https://pick55.com/r/ https://pick55.com/phpmyadmin/ https://pick9.golf/; do
	host=${url#https://}
	host=${host%%/*}
	code=$(curl -s -o /dev/null -w '%{http_code}' --resolve "$host:443:127.0.0.1" -F probe=1 "$url")
	echo "multipart POST $url: $code (want 403)"
done
code=$(curl -s -o /dev/null -w '%{http_code}' --resolve pick55.com:443:127.0.0.1 -d probe=1 https://pick55.com/)
echo "url-encoded POST https://pick55.com/: $code (want anything but 403)"
