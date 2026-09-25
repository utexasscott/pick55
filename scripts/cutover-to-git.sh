#!/usr/bin/env bash
# One-time cutover of the pick55 web root from the Beanstalk SVN export to a git clone.
# Run ONCE as root on the droplet, AFTER the owner has pushed main to GitHub:
#
#   bash /root/cutover-to-git.sh
#
# What it does:
#   1. moves /home/beanstalk/pick55 (the SVN r149 export) to /home/beanstalk/pick55.svn-149
#   2. clones https://github.com/utexasscott/pick55.git into /home/beanstalk/pick55 as beanstalk
#   3. copies inc/_config.php and scrape/raw/ across from the old tree
#   4. runs composer install --no-dev as beanstalk (CLI php is 8.0; the lock allows it)
#   5. creates cache/ owned by www-data so the results-page cache lands under the web root
#   6. curls https://pick55.com/ and https://pick55.com/season/week/raw.php and prints the codes
#
# Rollback (also as root):
#   mv /home/beanstalk/pick55 /home/beanstalk/pick55.git-failed && mv /home/beanstalk/pick55.svn-149 /home/beanstalk/pick55
set -euo pipefail

OWNER=beanstalk
ROOT=/home/$OWNER/pick55
OLD=/home/$OWNER/pick55.svn-149
REPO=https://github.com/utexasscott/pick55.git

[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
[ -d "$ROOT" ] || { echo "$ROOT missing" >&2; exit 1; }
if [ -d "$ROOT/.git" ]; then
	echo "$ROOT is already a git clone; nothing to do. Use scripts/deploy.sh for updates."
	exit 0
fi
[ -e "$OLD" ] && { echo "$OLD already exists; refusing to overwrite a previous rollback copy" >&2; exit 1; }
[ -f "$ROOT/inc/_config.php" ] || { echo "$ROOT/inc/_config.php missing; refusing" >&2; exit 1; }

echo "== 1. park the SVN export"
mv "$ROOT" "$OLD"

echo "== 2. clone"
if ! sudo -H -u "$OWNER" git clone -q "$REPO" "$ROOT"; then
	echo "clone failed; rolling back" >&2
	rm -rf "$ROOT"
	mv "$OLD" "$ROOT"
	exit 1
fi

echo "== 3. carry over config and scrape output"
install -m 640 -o "$OWNER" -g www-data "$OLD/inc/_config.php" "$ROOT/inc/_config.php"
if [ -d "$OLD/scrape/raw" ]; then
	cp -a "$OLD/scrape/raw" "$ROOT/scrape/raw"
fi

echo "== 4. composer"
sudo -H -u "$OWNER" composer install --no-dev --no-interaction --prefer-dist --no-progress --working-dir="$ROOT" 2>&1 | tail -3

echo "== 5. cache dir"
install -d -m 775 -o www-data -g "$OWNER" "$ROOT/cache"

echo "== 6. verify"
sleep 1
for path in / /season/week/raw.php /rules.php; do
	printf '%-24s %s\n' "$path" "$(curl -s -o /dev/null -w '%{http_code}' "https://pick55.com$path")"
done
echo "expected: / 200, raw.php 302 (login redirect), rules.php 200"
echo "deployed: $(sudo -H -u "$OWNER" git -C "$ROOT" log -1 --format='%h %s')"
echo "done. If anything above is wrong, run the rollback line in this script's header."
