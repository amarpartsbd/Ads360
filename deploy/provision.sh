#!/usr/bin/env bash
#
# One-shot provisioning for a single Ubuntu 24.04 node.
#
# Brings a bare VPS to the point where deploy.sh can run: PHP-FPM, PostgreSQL,
# Redis, nginx, Node, a TLS certificate, a firewall, and an unprivileged user
# that owns the application. Run once, as root.
#
#   bash deploy/provision.sh
#
# Safe to re-run: every step checks for what it is about to create. Nothing here
# writes a secret to disk outside /var/www/ads360/.env, which is created 0640
# and owned by the application user.
#
set -euo pipefail

APP_USER="${APP_USER:-ads360}"
APP_DIR="${APP_DIR:-/var/www/ads360}"
APP_DOMAIN="${APP_DOMAIN:-ads.banik360.com}"
DB_NAME="${DB_NAME:-ads360}"
DB_USER="${DB_USER:-ads360}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_MAJOR="${NODE_MAJOR:-22}"
REPO_URL="${REPO_URL:-git@github.com:amarpartsbd/Ads360.git}"
# Empty means "whatever the repository's default branch is". Naming a branch
# here would be a guess about the repository, and cloning a branch that does not
# exist fails at the one step that is hardest to retry.
REPO_BRANCH="${REPO_BRANCH:-}"
# Where Let's Encrypt should send expiry warnings. Required by certbot.
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say() { printf '\n\033[1;35m==>\033[0m %s\n' "$*"; }
die() { printf '\n\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || die "Run this as root."

# ---------------------------------------------------------------------------
# What is already here
# ---------------------------------------------------------------------------
#
# This script is written to share a server rather than to own one: it adds an
# nginx vhost, a PHP-FPM pool and two systemd units, and removes nothing it did
# not create. The summary is printed because a provisioning script that silently
# rearranges a box someone is already serving from is how outages happen.

say "Looking at what is already running"
printf '    nginx vhosts:  %s\n' "$(ls /etc/nginx/sites-enabled 2>/dev/null | tr '\n' ' ' || echo none)"
printf '    PHP-FPM:       %s\n' "$(systemctl list-units --type=service --state=running --no-legend 'php*-fpm*' 2>/dev/null | awk '{print $1}' | tr '\n' ' ' || echo none)"
printf '    Other apps:    %s\n' "$(systemctl list-units --type=service --state=running --no-legend 2>/dev/null | awk '{print $1}' | grep -Ev '^(systemd|dbus|cron|ssh|rsyslog|polkit|networkd|resolved|user@|getty|unattended|snapd|multipathd|udisks|ModemManager|irqbalance|chrony|qemu|serial-getty)' | tr '\n' ' ')"
echo

# nginx is installed below, and two processes cannot hold port 80. If another
# web server already has it, installing nginx leaves a server that will not
# start and a site that may not come back — so this is checked before any
# package is touched rather than discovered afterwards.
#
# Asked of systemd by service name rather than by parsing `ss` output: the
# question is whether a known web server is running, and a name either matches
# or it does not. Output parsing would be one more thing that can be wrong in a
# check whose whole job is to be right before anything is changed.
for other_server in apache2 httpd caddy lighttpd lshttpd lsws openlitespeed; do
    if systemctl is-active --quiet "${other_server}" 2>/dev/null; then
        die "${other_server} is running, and it is almost certainly holding port 80.

    This script serves the application through nginx, and installing nginx while
    another web server holds port 80 leaves you with one that will not start.
    Serve ${APP_DOMAIN} through ${other_server} instead, or stop it first.

    Nothing has been changed."
    fi
done

# ---------------------------------------------------------------------------
# Packages
# ---------------------------------------------------------------------------

say "Installing base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    software-properties-common ca-certificates curl gnupg git unzip acl ufw

say "Adding the PHP ${PHP_VERSION} repository"
# Ubuntu 24.04 ships PHP 8.3. The application is developed and tested against
# 8.4, and matching that here means production runs the version CI proved.
if [[ ! -f /etc/apt/sources.list.d/ondrej-ubuntu-php-noble.sources ]] \
   && [[ ! -f /etc/apt/sources.list.d/ondrej-ubuntu-php-noble.list ]]; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
fi

say "Installing PHP ${PHP_VERSION}, PostgreSQL, Redis and nginx"
apt-get install -y -qq \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" \
    "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" \
    "php${PHP_VERSION}-gd" \
    postgresql postgresql-contrib \
    redis-server \
    nginx certbot python3-certbot-nginx

say "Installing Composer"
if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

say "Installing Node ${NODE_MAJOR}"
if ! command -v node >/dev/null 2>&1 || [[ "$(node -v)" != v${NODE_MAJOR}.* ]]; then
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
    apt-get install -y -qq nodejs
fi

# ---------------------------------------------------------------------------
# Application user
# ---------------------------------------------------------------------------

say "Creating the ${APP_USER} user"
# The application runs as itself, not as www-data: nginx needs to read public/
# and nothing else, so a compromised web server cannot write application code.
if ! id -u "${APP_USER}" >/dev/null 2>&1; then
    adduser --system --group --shell /bin/bash --home "${APP_DIR}" "${APP_USER}"
fi
mkdir -p "${APP_DIR}"
chown "${APP_USER}:${APP_USER}" "${APP_DIR}"
chmod 755 "${APP_DIR}"

say "Preparing a deploy key for GitHub"
SSH_DIR="${APP_DIR}/.ssh"
mkdir -p "${SSH_DIR}"

# Everything here is written as root and handed over at the end. Writing it as
# the application user instead would need the directory to be theirs first, and
# getting that order wrong fails at exactly this step.
if [[ ! -f "${SSH_DIR}/id_ed25519" ]]; then
    ssh-keygen -t ed25519 -N '' -C "${APP_USER}@${APP_DOMAIN}" \
        -f "${SSH_DIR}/id_ed25519" >/dev/null
fi
ssh-keyscan -t ed25519 github.com >> "${SSH_DIR}/known_hosts" 2>/dev/null
sort -u "${SSH_DIR}/known_hosts" -o "${SSH_DIR}/known_hosts"

chown -R "${APP_USER}:${APP_USER}" "${SSH_DIR}"
chmod 700 "${SSH_DIR}"
chmod 600 "${SSH_DIR}/id_ed25519" "${SSH_DIR}/known_hosts"
chmod 644 "${SSH_DIR}/id_ed25519.pub"

# ---------------------------------------------------------------------------
# PostgreSQL
# ---------------------------------------------------------------------------

say "Creating the PostgreSQL role and database"
systemctl enable --now postgresql

DB_PASSWORD_FILE="/root/.ads360-db-password"
if [[ -f "${DB_PASSWORD_FILE}" ]]; then
    DB_PASSWORD="$(cat "${DB_PASSWORD_FILE}")"
else
    # Generated here rather than written into the repository: a password shipped
    # with the source is a password on every installation of it.
    DB_PASSWORD="$(openssl rand -base64 33 | tr -d '/+=' | head -c 40)"
    printf '%s' "${DB_PASSWORD}" > "${DB_PASSWORD_FILE}"
    chmod 600 "${DB_PASSWORD_FILE}"
fi

role_exists=$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'")
if [[ "${role_exists}" != "1" ]]; then
    sudo -u postgres psql -q -c "CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASSWORD}';"
else
    sudo -u postgres psql -q -c "ALTER ROLE ${DB_USER} WITH PASSWORD '${DB_PASSWORD}';"
fi

db_exists=$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'")
if [[ "${db_exists}" != "1" ]]; then
    sudo -u postgres createdb -O "${DB_USER}" "${DB_NAME}"
fi
# The application creates no schemas of its own, but migrations create every
# table in `public`, which PostgreSQL 15+ no longer grants to non-owners.
sudo -u postgres psql -q -d "${DB_NAME}" -c "GRANT ALL ON SCHEMA public TO ${DB_USER};"

# ---------------------------------------------------------------------------
# Redis
# ---------------------------------------------------------------------------

say "Configuring Redis"
# No maxmemory and no eviction policy on purpose: this instance holds the queue
# as well as the cache, and an evicted queue key is a job that silently never
# runs. Cache pressure is bounded by the box, and the box has room.
systemctl enable --now redis-server
if grep -qE '^# *supervised ' /etc/redis/redis.conf; then
    sed -i 's/^# *supervised .*/supervised systemd/' /etc/redis/redis.conf
    # Restarted only when the file actually changed: anything else on this box
    # using Redis loses its connections for the moment this takes.
    systemctl restart redis-server
fi

# ---------------------------------------------------------------------------
# PHP-FPM
# ---------------------------------------------------------------------------

say "Configuring the PHP-FPM pool"
install -m 644 "${HERE}/php/ads360.pool.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/ads360.conf"
install -m 644 "${HERE}/php/ads360.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-ads360.ini"
install -m 644 "${HERE}/php/ads360.ini" "/etc/php/${PHP_VERSION}/cli/conf.d/99-ads360.ini"
# The stock `www` pool is left in place. It listens on its own socket and no
# vhost here routes to it, so it costs a few megabytes; removing it would break
# anything else on this box that happens to use PHP-FPM.
systemctl enable "php${PHP_VERSION}-fpm"
systemctl restart "php${PHP_VERSION}-fpm"

# ---------------------------------------------------------------------------
# Source
# ---------------------------------------------------------------------------

if [[ ! -d "${APP_DIR}/.git" ]]; then
    say "The repository has not been cloned yet"
    cat <<EOF

Add this public key to GitHub as a read-only deploy key for amarpartsbd/Ads360
(Settings -> Deploy keys -> Add deploy key), then re-run this script:

$(cat "${SSH_DIR}/id_ed25519.pub")

EOF
    read -rp "Press Enter once the key is added, or Ctrl-C to stop here. " _
    if [[ -n "${REPO_BRANCH}" ]]; then
        sudo -u "${APP_USER}" -H git clone --branch "${REPO_BRANCH}" "${REPO_URL}" "${APP_DIR}/src"
    else
        sudo -u "${APP_USER}" -H git clone "${REPO_URL}" "${APP_DIR}/src"
    fi
    # Cloned into a subdirectory because the home directory already exists;
    # move its contents up so the application root is the repository root.
    shopt -s dotglob
    mv "${APP_DIR}/src"/* "${APP_DIR}/"
    shopt -u dotglob
    rmdir "${APP_DIR}/src"
    chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}"
fi

say "Writing .env"
ENV_FILE="${APP_DIR}/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
    sed \
        -e "s|__APP_DOMAIN__|${APP_DOMAIN}|g" \
        -e "s|__DB_DATABASE__|${DB_NAME}|g" \
        -e "s|__DB_USERNAME__|${DB_USER}|g" \
        -e "s|__DB_PASSWORD__|${DB_PASSWORD}|g" \
        "${HERE}/env.production.example" > "${ENV_FILE}"
    chown "${APP_USER}:${APP_USER}" "${ENV_FILE}"
    # Readable only by the application. nginx never reads it, and neither
    # should anyone else with a shell on the box.
    chmod 640 "${ENV_FILE}"
    # APP_KEY is left empty here and generated by deploy.sh: artisan cannot run
    # until composer has installed the framework it lives in.
else
    echo "    .env already exists — left untouched."
fi

# ---------------------------------------------------------------------------
# nginx and TLS
# ---------------------------------------------------------------------------

say "Configuring nginx"
mkdir -p /var/www/letsencrypt
chown -R www-data:www-data /var/www/letsencrypt

# The default site is deliberately left alone. This server may already be
# serving something, and nginx routes by server_name — a vhost for one hostname
# does not need another hostname's vhost removed to work.

render_vhost() {
    sed \
        -e "s|__APP_DOMAIN__|${APP_DOMAIN}|g" \
        -e "s|__APP_DIR__|${APP_DIR}|g" \
        -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
        "$1" > /etc/nginx/sites-available/"${APP_DOMAIN}".conf
    ln -sfn /etc/nginx/sites-available/"${APP_DOMAIN}".conf \
            /etc/nginx/sites-enabled/"${APP_DOMAIN}".conf
}

CERT_DIR="/etc/letsencrypt/live/${APP_DOMAIN}"

if [[ ! -d "${CERT_DIR}" ]]; then
    # nginx will not start against certificate paths that do not exist, so the
    # HTTP-only vhost goes up first, serves the ACME challenge, and is replaced
    # by the real one below. The committed file stays authoritative either way —
    # nothing here edits a vhost in place.
    say "Bringing up the HTTP-only vhost so ACME can reach this host"
    render_vhost "${HERE}/nginx/bootstrap.conf"
    nginx -t && systemctl reload-or-restart nginx

    [[ -n "${CERTBOT_EMAIL}" ]] || die "Set CERTBOT_EMAIL=you@example.com and re-run: certbot needs an address for expiry warnings."

    say "Requesting a certificate for ${APP_DOMAIN}"
    echo "    This fails unless ${APP_DOMAIN} already resolves to this server."
    certbot certonly --webroot -w /var/www/letsencrypt \
        -d "${APP_DOMAIN}" \
        --email "${CERTBOT_EMAIL}" --agree-tos --no-eff-email --non-interactive
fi

say "Installing the TLS vhost"
render_vhost "${HERE}/nginx/app.conf"
nginx -t && systemctl reload-or-restart nginx
systemctl enable nginx

# ---------------------------------------------------------------------------
# Services
# ---------------------------------------------------------------------------

say "Installing the Horizon and scheduler units"
for unit in ads360-horizon.service ads360-scheduler.service ads360-scheduler.timer; do
    sed \
        -e "s|__APP_DIR__|${APP_DIR}|g" \
        -e "s|__APP_USER__|${APP_USER}|g" \
        "${HERE}/systemd/${unit}" > "/etc/systemd/system/${unit}"
done
systemctl daemon-reload
systemctl enable ads360-horizon.service ads360-scheduler.timer

say "Granting the deploy user the two commands a release needs"
sed \
    -e "s|__APP_USER__|${APP_USER}|g" \
    -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
    "${HERE}/sudoers/ads360-deploy" > /etc/sudoers.d/ads360-deploy
chmod 440 /etc/sudoers.d/ads360-deploy
# A malformed sudoers file locks everyone out of sudo, so it is checked before
# it is left in place.
visudo -c -f /etc/sudoers.d/ads360-deploy >/dev/null || die "Refusing to leave a malformed sudoers file in place."

# ---------------------------------------------------------------------------
# Firewall
# ---------------------------------------------------------------------------

say "Configuring the firewall"
# The SSH port is read from the running configuration rather than assumed to be
# 22: a firewall that does not allow the port you are connected on locks you out
# of the server.
SSH_PORT="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')"
SSH_PORT="${SSH_PORT:-22}"

# PostgreSQL and Redis are reached over the loopback only and are deliberately
# absent from these rules.
ufw allow "${SSH_PORT}/tcp" >/dev/null
ufw allow 'Nginx Full' >/dev/null

if ufw status | grep -q '^Status: active'; then
    echo "    Already active; allowed SSH on ${SSH_PORT} and HTTP/HTTPS."
    ufw status verbose
elif [[ "${MANAGE_FIREWALL:-}" == "yes" ]]; then
    ufw --force enable >/dev/null
    ufw status verbose
else
    # Not enabled on our own initiative. Turning a firewall on for the first
    # time cuts off every port not named above, and this box may be serving
    # something on one of them — which is not a thing to discover by doing it.
    cat <<EOF

    The firewall is off and has been LEFT off. The rules for SSH (${SSH_PORT})
    and HTTP/HTTPS are staged but not enforced.

    Anything else on this server currently listening on a public port:

$(ss -tlnp 2>/dev/null | awk 'NR>1 && $4 !~ /127\.0\.0\.1|\[::1\]/ {print "      " $4 "  " $6}' | sort -u)

    If nothing there needs to stay reachable, enable it with:

      ufw allow <any other port you need>/tcp
      ufw enable

EOF
fi

say "Provisioned."
cat <<EOF

Next:

  1. Deploy the application:

       sudo -u ${APP_USER} bash ${APP_DIR}/deploy/deploy.sh

  2. Create the first administrator (there is no default account):

       cd ${APP_DIR} && sudo -u ${APP_USER} php artisan ads:create-admin

  3. Sign in at https://${APP_DOMAIN} and enrol two-factor authentication.

The database password is at ${DB_PASSWORD_FILE} and in ${APP_DIR}/.env.
Neither is in the repository, and neither should be copied anywhere else.
EOF
