#!/usr/bin/env bash
#
# Seeds, builds, serves and smoke-tests the application in a real browser.
#
#   bash tests/Browser/run.sh
#
# Rebuilds the database, so point it at a development machine and nothing else.
# The server it starts is stopped again on the way out, whether the test passed
# or not.
#
set -euo pipefail

PORT="${PORT:-8123}"
PHP_BIN="${PHP_BIN:-php}"

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

say() { printf '\n\033[1;35m==>\033[0m %s\n' "$*"; }

say "Seeding the demo data"
# The fixtures the smoke test signs in as. Refused in production by the seeder.
"${PHP_BIN}" artisan migrate:fresh --seed --force

say "Building assets"
# Against the built manifest rather than the dev server, because the build is
# what production serves and the two have differed before.
npm run build

say "Starting the application on port ${PORT}"
# The policy is off wherever APP_DEBUG is true, which is every development
# machine — so it is switched on here, since blocking the page's own scripts is
# one of the faults this test exists to catch.
CONTENT_SECURITY_POLICY=true "${PHP_BIN}" artisan serve --port="${PORT}" >/tmp/ads360-smoke-serve.log 2>&1 &
SERVER_PID=$!
trap 'kill "${SERVER_PID}" 2>/dev/null || true' EXIT

for _ in $(seq 1 30); do
    curl -sf "http://127.0.0.1:${PORT}/up" >/dev/null 2>&1 && break
    sleep 1
done

say "Running the browser smoke test"
# PLAYWRIGHT_CHROMIUM_PATH, if set, points at a Chromium the machine already
# has. Otherwise Playwright uses its own — `npx playwright install chromium`.
node tests/Browser/smoke.mjs --url="http://127.0.0.1:${PORT}"
