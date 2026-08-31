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
REPO_BRANCH="${REPO_BRANCH:-main}"
PHP_VERSION="${PHP_VERSION:-8.4}"

cd "${APP_DIR}"

say() { printf '\n\033[1;35m==>\033[0m %s\n' "$*"; }

[[ -f artisan ]] || { echo "No artisan in ${APP_DIR}." >&2; exit 1; }
[[ -f .env ]] || { echo "No .env in ${APP_DIR}. Run provision.sh first." >&2; exit 1; }

say "Fetching ${REPO_BRANCH}"
git fetch --prune origin "${REPO_BRANCH}"
git switch "${REPO_BRANCH}" 2>/dev/null || git checkout "${REPO_BRANCH}"
git reset --hard "origin/${REPO_BRANCH}"

# The secret makes the new release previewable through the maintenance page
# while it is still down for everyone else.
PREVIEW_SECRET="$(openssl rand -hex 16)"

# `up` runs even if a step below fails: a failed deploy that leaves the site
# dark is a worse outcome than a failed deploy on the previous release.
cleanup() { php artisan up >/dev/null 2>&1 || true; }
trap cleanup EXIT

say "Entering maintenance mode"
php artisan down --retry=15 --secret="${PREVIEW_SECRET}"

say "Installing PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

say "Ensuring an application key exists"
# Generated on the box, once. The key decrypts every session, every encrypted
# column and every provider token in the database, so regenerating it on an
# existing installation makes all of them unreadable.
if ! grep -qE '^APP_KEY=.+$' .env; then
    php artisan key:generate --force
fi

say "Building assets"
npm ci
npm run build

say "Migrating"
# Migrations are written to be backward compatible with the running release, so
# this can be rolled back without restoring the database.
php artisan migrate --force

say "Seeding roles, permissions and pricing"
# Idempotent, and it is what picks up a permission added by this release.
php artisan db:seed --force

say "Rebuilding caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link 2>/dev/null || true

say "Reloading PHP-FPM"
# OPcache runs with validate_timestamps=0, so without this the pool keeps
# serving the previous release's compiled code and the deploy changes nothing
# a visitor can see. The sudoers rule allows exactly this command.
sudo systemctl reload "php${PHP_VERSION}-fpm"

say "Restarting workers"
# Horizon finishes the job in hand before exiting, so nothing is interrupted
# mid-flight; systemd restarts it on the new code.
php artisan horizon:terminate

say "Leaving maintenance mode"
php artisan up
trap - EXIT

say "Deployed $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
echo "    Preview secret for the next deploy window is generated fresh each run."
