# Single-node deployment

Everything needed to run Ads360 on one Ubuntu 24.04 server. Written for
`ads.banik360.com` on a Hostinger KVM 4, and parameterised so nothing about
that host is baked in.

`docs/DEPLOYMENT.md` describes the multi-node topology this grows into, and the
provider shakedowns that gate going live. This directory is the smaller thing:
one box, from bare to serving.

## What it builds

```
                     ads.banik360.com
                            │
                        nginx :443            TLS, static assets, ACME renewal
                            │ unix socket
                     php-fpm pool `ads360`    the application, as its own user
                            │
      ┌─────────────────────┼─────────────────────┐
 PostgreSQL 16          Redis 7            storage/ (private)
 data, sessions      cache db 1,           KYC documents, creatives,
                     queues db 0           report exports

 systemd: ads360-horizon.service     all queues, restarted on deploy
          ads360-scheduler.timer     schedule:run, every minute
```

The application runs as the `ads360` user, not `www-data`. nginx reads `public/`
and connects to a socket; it cannot read `.env` or write application code.

## Sharing a server

`provision.sh` is written to add to a machine rather than to own one, because
the machine it was first run on was already serving something else. It adds an
nginx vhost, a PHP-FPM pool, a database, a user and two systemd units, and
removes nothing it did not create:

- the nginx **default site is left in place**, and neither vhost here claims
  `default_server` — nginx routes by `server_name`, and two default servers is
  a configuration nginx refuses to start;
- the stock **`www` PHP-FPM pool is left in place**;
- **Redis is restarted only if its config actually changed**, and this
  application uses databases 3 and 4 so it shares the server without sharing a
  keyspace;
- the **firewall is not enabled** unless it already was, or `MANAGE_FIREWALL=yes`
  is passed. Turning one on for the first time cuts off every port not named in
  the rules, and the script prints what is currently listening so that decision
  is made with the list in front of you.

It prints what it found running before it changes anything, and refuses to
start at all if Apache, LiteSpeed, Caddy or lighttpd is running — installing
nginx while another web server holds port 80 leaves one that cannot start.

One thing it does change that is worth knowing about: PHP comes from the
`ondrej/php` PPA, and adding that repository replaces any distribution PHP
already installed with the same version from that repository. It is the same
PHP version, built by the maintainer Ubuntu's own packages come from, and
normally invisible — but it touches the neighbouring application, so check that
application still works after the first provisioning run.

## Files

| File | What it is |
| --- | --- |
| `provision.sh` | One-shot, idempotent, run as root. Packages, database, Redis, FPM pool, user, clone, TLS, firewall, services. |
| `deploy.sh` | Every release after the first. Run as `ads360`. |
| `env.production.example` | Rendered to `.env` by `provision.sh`. Secrets are left blank. |
| `nginx/app.conf` | The production vhost. TLS, redirect, asset caching. |
| `nginx/bootstrap.conf` | HTTP-only stand-in, used until certbot has issued a certificate. |
| `php/ads360.pool.conf` | The FPM pool: own user, own socket, sized for a shared 4 vCPU box. |
| `php/ads360.ini` | OPcache and limits, shared by FPM and the CLI. |
| `systemd/*` | Horizon, and the scheduler timer. |
| `sudoers/ads360-deploy` | The two commands a release needs root for, and nothing else. |

Every file with `__PLACEHOLDERS__` is rendered by `provision.sh`; none of them
are edited in place afterwards, so what is in git is what is on the box.

## First deployment

**Point DNS first.** `certbot` proves control of the name over HTTP, so
`ads.banik360.com` has to resolve to this server before provisioning reaches
that step.

```
A    ads    156.67.221.228    TTL 300
```

Wait for it to propagate — `dig +short ads.banik360.com` from anywhere should
return the server's address — then, as root on the server:

```bash
apt-get update && apt-get install -y git
git clone https://github.com/amarpartsbd/Ads360.git /tmp/ads360-bootstrap
cd /tmp/ads360-bootstrap

CERTBOT_EMAIL=you@banik360.com bash deploy/provision.sh
```

Both scripts follow the repository's default branch unless `REPO_BRANCH` says
otherwise, so nothing here needs editing when the working branch changes. To
provision from a branch that is not the default:

```bash
git checkout <branch>
CERTBOT_EMAIL=you@banik360.com REPO_BRANCH=<branch> bash deploy/provision.sh
```

It pauses once, to print a public key. Add it to GitHub as a **read-only deploy
key** (repository → Settings → Deploy keys → Add deploy key), press Enter, and
it clones into `/var/www/ads360` and finishes.

Then the release, and the first administrator:

```bash
sudo -u ads360 -H bash /var/www/ads360/deploy/deploy.sh

cd /var/www/ads360
sudo -u ads360 -H php8.4 artisan ads:create-admin
```

**`php8.4`, not `php`.** A server can have several PHP versions installed for
several applications, and the bare `php` is whichever one the system default
points at — which is not necessarily this one, and is not something to change,
because the other applications are what it points at for. Everything the deploy
runs uses the versioned binary for the same reason.

`ads:create-admin` prompts for a name, an address and a password. There is no
default account and no seeded password — a credential shipped with the source
is a credential on every installation of it. The password is prompted for
rather than passed as an option so it stays out of shell history and out of the
process list, and it is checked against the same policy the platform applies to
everyone else.

Sign in at `https://ads.banik360.com`. The first thing it will ask for is
two-factor enrolment, because `ADMIN_REQUIRE_TWO_FACTOR` is on.

## Every release after that

```bash
sudo -u ads360 -H bash /var/www/ads360/deploy/deploy.sh
```

## Re-running provisioning

`provision.sh` is idempotent and is worth re-running after a change to anything
in this directory — a vhost, the FPM pool, a systemd unit. Run it from the
checkout it already installed, not from the `/tmp` clone the first run used:
`/tmp` does not survive a reboot, and the deployed checkout is the copy that
matches what is running.

```bash
cd /var/www/ads360
sudo -u ads360 -H git fetch origin && sudo -u ads360 -H git reset --hard "origin/$(git rev-parse --abbrev-ref HEAD)"
bash deploy/provision.sh
```

It skips the clone, the `.env` and the certificate when it finds them, so a
re-run only reinstalls the configuration files and the services.

Maintenance mode is on for the window where the code and the schema may
disagree, and comes back off even if a step fails — a failed deploy should
leave the previous release serving, not leave the site dark.

## Checking it

```bash
curl -sI https://ads.banik360.com/up          # 200, and the TLS chain resolves
systemctl status ads360-horizon               # active (running)
systemctl list-timers ads360-scheduler        # next run under a minute away
sudo -u ads360 -H php8.4 /var/www/ads360/artisan horizon:status
journalctl -u ads360-horizon -n 50 --no-pager
tail -n 50 /var/www/ads360/storage/logs/laravel-$(date +%F).log
```

## What is deliberately not on yet

A first deployment comes up with `ADVERTISING_DRIVER=mock` and every feature
flag false. That is not an oversight:

- **Nothing publishes.** A mock adapter refuses to instantiate in production,
  so a campaign approved for publishing waits instead of being sent somewhere
  unverified. Everything else — sign-up, verification, wallets, the campaign
  builder, approvals, analytics — works. Turn the driver to `live` only after
  the Meta and Google shakedowns in `docs/DEPLOYMENT.md`.
- **`MAIL_MAILER=log`.** Invitations, password resets and verification links go
  to `storage/logs`, not to anybody. Set a real transport before inviting your
  first client, or they will never receive the email.
- **Uploads are on local disk.** Fine on one node. It is not a backup, and it
  does not survive rebuilding the server — set the S3/R2 variables when that
  matters.

## Things worth doing next

Not required to serve traffic, and each one is a real gap while it is missing:

- **Backups.** Hostinger's snapshots cover the disk, not a consistent database.
  `pg_dump` on a schedule, off the box, with a restore you have actually
  tested — §62 keeps ledger entries and audit logs forever, and this is what
  makes that true.
- **Mail.** As above.
- **Error tracking and uptime checks** against `/up`.
- **`ADMIN_IP_ALLOWLIST`** once the office addresses are known.
- **`fail2ban`** for SSH.
- **Cloudflare**, if it goes in front: the application would then be behind a
  proxy, and `bootstrap/app.php` would need `trustProxies` before
  `$request->secure()` and the client IP in the audit log could be believed.
