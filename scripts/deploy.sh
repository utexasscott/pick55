#!/usr/bin/env bash
# Deploy the current GitHub main to the droplet. Run as beanstalk (or as root, which
# re-executes it as beanstalk):
#
#   ssh root@143.198.236.171 "bash /home/beanstalk/pick55/scripts/deploy.sh"
#
# Fast-forward only: if main was force-pushed or the tree was edited in place, this stops
# and says so rather than guessing. composer runs only when composer.lock changed.
set -euo pipefail

OWNER=beanstalk
ROOT=/home/$OWNER/pick55

if [ "$(id -un)" != "$OWNER" ]; then
	exec sudo -H -u "$OWNER" bash "$0" "$@"
fi
cd "$ROOT"

before=$(git rev-parse HEAD)
git fetch -q origin main
git merge --ff-only origin/main
after=$(git rev-parse HEAD)

if [ "$before" = "$after" ]; then
	echo "already at $(git log -1 --format='%h %s')"
	exit 0
fi
if ! git diff --quiet "$before" "$after" -- composer.lock; then
	echo "composer.lock changed; installing"
	composer install --no-dev --no-interaction --prefer-dist --no-progress 2>&1 | tail -2
fi
echo "deployed $(git log -1 --format='%h %s')"
git log --oneline "$before..$after"
