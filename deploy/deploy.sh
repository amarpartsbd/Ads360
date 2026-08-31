#!/usr/bin/env bash
#
# Release script. Run as the application user, from anywhere:
#
#   sudo -u ads360 bash /var/www/ads360/deploy/deploy.sh
#
# Fetches the branch, installs dependencies, builds assets, migrates, rebuilds
# the caches and restarts the workers. Maintenance mode is on for the window
# where the code and the database may disagree, and off again at the end
# whatever happens.
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/ads360}"
PHP_VERSION="${PHP_VERSION:-8.4}"
# The versioned binary, never the bare `php`. This server has 8.2, 8.3 and 8.4
# installed for three different applications, and `php` is whichever one the
# system default happens to point at — which is not ours, and which we must not
# change, because the other two applications are what it points at for.
PHP_BIN="${PHP_BIN:-/usr/bin/php${PHP_VERSION}}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"

cd "${APP_DIR}"

say() { printf '\n\033[1;35m==>\033[0m %s\n' "$*"; }

[[ -f artisan ]] || { echo "No artisan in ${APP_DIR}." >&2; exit 1; }
[[ -f .env ]] || { echo "No .env in ${APP_DIR}. Run provision.sh first." >&2; exit 1; }
[[ -x "${PHP_BIN}" ]] || { echo "${PHP_BIN} not found. Run provision.sh first." >&2; exit 1; }

# Read after the cd, and defaults to the branch this checkout is already on, so
# a deploy redeploys what is deployed. Naming a branch here would be a guess,
# and the wrong guess is a `git reset --hard` onto somebody else's work.
REPO_BRANCH="${REPO_BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"

# Maintenance mode before the code changes, not after — but artisan needs the
# framework to run at all, and a first deploy has no vendor/ yet. There is also
# nothing to protect on a first deploy, because nothing is serving.
if [[ -f vendor/autoload.php ]]; then
    # The secret makes the new release previewable through the maintenance page
    # while it is still down for everyone else.
    PREVIEW_SECRET="$(openssl rand -hex 16)"

    # `up` runs even if a step below fails: a failed deploy that leaves the site
    # dark is a worse outcome than a failed deploy on the previous release.
    cleanup() { "${PHP_BIN}" artisan up >/dev/null 2>&1 || true; }
    trap cleanup EXIT

    say "Entering maintenance mode"
    "${PHP_BIN}" artisan down --retry=15 --secret="${PREVIEW_SECRET}"
else
    say "First deploy — nothing is serving yet, so maintenance mode is skipped"
fi

say "Fetching ${REPO_BRANCH}"
git fetch --prune origin "${REPO_BRANCH}"
git switch "${REPO_BRANCH}" 2>/dev/null || git checkout "${REPO_BRANCH}"
git reset --hard "origin/${REPO_BRANCH}"

say "Installing PHP dependencies"
"${PHP_BIN}" "${COMPOSER_BIN}" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

say "Ensuring an application key exists"
# Generated on the box, once. The key decrypts every session, every encrypted
# column and every provider token in the database, so regenerating it on an
# existing installation makes all of them unreadable.
if ! grep -qE '^APP_KEY=.+$' .env; then
    "${PHP_BIN}" artisan key:generate --force
fi

say "Building assets"
npm ci
npm run build

say "Migrating"
# Migrations are written to be backward compatible with the running release, so
# this can be rolled back without restoring the database.
"${PHP_BIN}" artisan migrate --force

say "Seeding roles, permissions and pricing"
# Idempotent, and it is what picks up a permission added by this release.
"${PHP_BIN}" artisan db:seed --force

say "Rebuilding caches"
"${PHP_BIN}" artisan config:cache
"${PHP_BIN}" artisan route:cache
"${PHP_BIN}" artisan view:cache
"${PHP_BIN}" artisan event:cache
"${PHP_BIN}" artisan storage:link 2>/dev/null || true

say "Reloading PHP-FPM"
# OPcache runs with validate_timestamps=0, so without this the pool keeps
# serving the previous release's compiled code and the deploy changes nothing
# a visitor can see. The sudoers rule allows exactly this command.
sudo systemctl reload "php${PHP_VERSION}-fpm"

say "Starting the workers and the scheduler"
# `restart` rather than `horizon:terminate`, because this also has to *start*
# them: provision.sh enables both units but cannot start them, since neither can
# run until a release exists. systemd sends SIGTERM, which Horizon handles by
# finishing the job in hand before it exits, so nothing is interrupted midway.
sudo systemctl restart ads360-horizon.service
sudo systemctl restart ads360-scheduler.timer

say "Leaving maintenance mode"
"${PHP_BIN}" artisan up
trap - EXIT

say "Deployed $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
echo "    Preview secret for the next deploy window is generated fresh each run."
