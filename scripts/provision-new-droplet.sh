#!/usr/bin/env bash
# Set up the 2026 droplet (Ubuntu 26.04, NYC1, 137.184.150.9) for pick55.com and
# pick9.golf. Run as root (Claude runs it with its temporary setup sudo). The first run was
# from a copy in /home/claude; after that, from the clone (migrate-unpack.sh does this):
#
#   sudo bash /home/beanstalk/pick55/scripts/provision-new-droplet.sh
#
# Safe to re-run. Installs Apache with PHP-FPM (Ubuntu's PHP, 8.5) and MySQL 8.4, certbot,
# ufw and a 2 GB swapfile; creates beanstalk, its logs and both web roots (same paths as
# the old box); writes both vhosts, HTTP only until /etc/letsencrypt arrives from the old
# box; clones pick55 and installs its vendor/. Databases, configs, pick9's files, TLS and
# cron are later steps: see docs/droplet-migration.md.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

OWNER=beanstalk
HOME_DIR=/home/$OWNER
REPO=https://github.com/utexasscott/pick55.git

if [ "$(id -u)" != 0 ]; then
	echo "run as root" >&2
	exit 1
fi

echo "== packages"
apt-get update -q
apt-get -y -q upgrade
apt-get -y -q install apache2 php-fpm php-cli php-mysql php-curl php-mbstring php-xml \
	mysql-server certbot python3-certbot-apache git composer unzip rsync ufw unattended-upgrades
PHPV=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

echo "== swap"
if ! swapon --show | grep -q '^/swapfile '; then
	[ -f /swapfile ] || fallocate -l 2G /swapfile
	chmod 600 /swapfile
	mkswap /swapfile >/dev/null
	swapon /swapfile
fi
grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab

echo "== automatic security updates"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

echo "== PHP $PHPV"
for sapi in fpm cli; do
	cat > /etc/php/$PHPV/$sapi/conf.d/99-pick55.ini <<'EOF'
; pick55 production settings (scripts/provision-new-droplet.sh). The app narrows
; error_reporting itself from config('error_reporting'); see docs/droplet-migration.md.
display_errors = Off
display_startup_errors = Off
log_errors = On
html_errors = Off
expose_php = Off
zend.assertions = -1
date.timezone = America/Chicago
EOF
done
# The CLI logs to stderr, which each cron line redirects into its own log
echo 'error_log = /var/log/php/fpm-error.log' >> /etc/php/$PHPV/fpm/conf.d/99-pick55.ini
install -d -m 750 -o www-data -g adm /var/log/php
cat > /etc/logrotate.d/php-pick55 <<'EOF'
/var/log/php/*.log {
	weekly
	rotate 8
	compress
	missingok
	notifempty
	copytruncate
}
EOF
sed -i -E 's/^pm\.max_children = .*/pm.max_children = 10/' /etc/php/$PHPV/fpm/pool.d/www.conf

echo "== MySQL"
cat > /etc/mysql/mysql.conf.d/99-pick55.cnf <<'EOF'
# pick55 (scripts/provision-new-droplet.sh): a 2 GB box, localhost only, ~20 MB of data
[mysqld]
bind-address = 127.0.0.1
mysqlx = OFF
innodb_buffer_pool_size = 256M
key_buffer_size = 32M
max_connections = 60
performance_schema = OFF
EOF

echo "== Apache"
a2dismod -q php$PHPV 2>/dev/null || true
a2dismod -q mpm_prefork 2>/dev/null || true
a2enmod -q mpm_event proxy_fcgi setenvif rewrite headers ssl
a2enconf -q php$PHPV-fpm
cat > /etc/apache2/conf-available/pick55-server.conf <<'EOF'
# pick55 (scripts/provision-new-droplet.sh)
ServerName localhost
ServerTokens Prod
ServerSignature Off
TraceEnable Off
# The web roots are git clones (pick9 an SVN export): never serve their metadata
RedirectMatch 404 /\.(git|svn)(/|$)
EOF
a2enconf -q pick55-server
a2dissite -q 000-default 2>/dev/null || true
# One Let's Encrypt certificate covers both sites. Until migrate-unpack.sh copies
# /etc/letsencrypt from the old box, the sites are HTTP only.
CERT=/etc/letsencrypt/live/pick55.com
vhost_body() {
	cat <<EOF
	ServerName $1
	ServerAlias www.$1
	DocumentRoot /var/www/$1
	ErrorLog \${APACHE_LOG_DIR}/$1-error.log
	CustomLog \${APACHE_LOG_DIR}/$1-access.log combined
	<Directory /var/www/$1>
		Options -Indexes +FollowSymLinks
		AllowOverride All
		Require all granted
	</Directory>
EOF
}
for site in pick55.com pick9.golf; do
	{
		echo "# $site over HTTP (scripts/provision-new-droplet.sh)"
		echo "<VirtualHost *:80>"
		vhost_body $site
		if [ -f $CERT/fullchain.pem ]; then
			printf '\tRewriteEngine on\n\tRewriteRule ^ https://%%{SERVER_NAME}%%{REQUEST_URI} [END,NE,R=permanent]\n'
		fi
		echo "</VirtualHost>"
	} > /etc/apache2/sites-available/$site.conf
	a2ensite -q $site
	if [ -f $CERT/fullchain.pem ]; then
		{
			echo "# $site over HTTPS (scripts/provision-new-droplet.sh)"
			echo "<VirtualHost *:443>"
			vhost_body $site
			printf '\tInclude /etc/letsencrypt/options-ssl-apache.conf\n\tSSLCertificateFile %s/fullchain.pem\n\tSSLCertificateKeyFile %s/privkey.pem\n' $CERT $CERT
			echo "</VirtualHost>"
		} > /etc/apache2/sites-available/$site-le-ssl.conf
		a2ensite -q $site-le-ssl
	fi
done

echo "== $OWNER and the web roots"
id $OWNER >/dev/null 2>&1 || useradd -m -s /bin/bash $OWNER
chmod 755 $HOME_DIR
install -d -m 775 -o $OWNER -g $OWNER $HOME_DIR/logs $HOME_DIR/logs/scrape $HOME_DIR/logs/pick9
install -d -m 775 -o $OWNER -g $OWNER $HOME_DIR/pick9
if [ ! -d $HOME_DIR/pick55/.git ]; then
	sudo -u $OWNER -H git clone -q $REPO $HOME_DIR/pick55
fi
sudo -u $OWNER -H composer install --no-dev --no-interaction --prefer-dist --no-progress -d $HOME_DIR/pick55 2>&1 | tail -2
install -d -m 775 -o www-data -g $OWNER $HOME_DIR/pick55/cache
install -d -m 2775 -o $OWNER -g www-data $HOME_DIR/pick55/scrape/raw
ln -sfn $HOME_DIR/pick55 /var/www/pick55.com
ln -sfn $HOME_DIR/pick9 /var/www/pick9.golf

echo "== firewall"
ufw allow OpenSSH >/dev/null
ufw allow 'Apache Full' >/dev/null
ufw --force enable >/dev/null

echo "== restart"
apache2ctl configtest
systemctl restart php$PHPV-fpm mysql apache2

echo "== check"
php -v | head -1
mysql --version
apache2 -v | head -1
echo "PHP2Shell fix in php$PHPV changelog: $(zgrep -c -E 'CVE-2026-10961[89]' /usr/share/doc/php$PHPV-common/changelog.Debian.gz || true) lines (want 1 or more)"
systemctl is-active php$PHPV-fpm mysql apache2 | tr '\n' ' '
echo
ufw status | head -6
free -m | head -3
scheme=http
port=80
if [ -f $CERT/fullchain.pem ]; then
	scheme=https
	port=443
fi
for u in static/css/global.css .git/HEAD; do
	echo "GET $scheme://pick55.com/$u: $(curl -s -o /dev/null -w '%{http_code}' --resolve pick55.com:$port:127.0.0.1 "$scheme://pick55.com/$u")"
done
