# Deployment

Production topology, environments and operational tasks.

---

## Topology

```
Cloudflare
    │
Load balancer
    │
    ├── App node × N        nginx + php-fpm
    ├── Queue node × N      php artisan horizon
    └── Scheduler node      php artisan schedule:work
            │
    ┌───────┴────────┬──────────────┐
PostgreSQL 16     Redis 7      S3 / Cloudflare R2
(primary +        (cache,      (creatives, KYC
 read replica)    queue)        documents)
```

App nodes are stateless. Sessions are in PostgreSQL and cache and queues are in
Redis, so a node can be replaced at any time.

The scheduler may run on more than one node: scheduled jobs take distributed
locks, so a task cannot double-execute.

## Environments

Development, staging and production are fully separate. Never use production
credentials locally, and never copy raw production KYC data or secrets into a
lower environment — use sanitised fixtures.

## Secrets

Development reads `.env`. Production should use a dedicated secret store —
AWS Secrets Manager, HashiCorp Vault, or equivalent.

Never commit `.env`, API secrets, OAuth tokens, database passwords, payment
credentials or private keys. CI fails the build if a `.env` file is tracked.

## Release

```bash
php artisan down --render="errors::503"
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan horizon:terminate     # workers restart with the new code
php artisan up
```

Migrations must be backward compatible with the running release, so a
deployment can be rolled back without a database restore. Add a column before
writing to it; stop reading a column before dropping it.

## Production environment

```
APP_ENV=production
APP_DEBUG=false                   # never true in production
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
FORCE_HTTPS=true
CONTENT_SECURITY_POLICY=true
ADMIN_REQUIRE_TWO_FACTOR=true
LOG_LEVEL=info
```

## Backups

| Kind             | Frequency  | Retention |
| ---------------- | ---------- | --------- |
| WAL archiving    | Continuous | 30 days   |
| Full snapshot    | Daily      | 30 days   |
| Weekly archive   | Weekly     | 12 weeks  |
| Monthly archive  | Monthly    | 12 months |

Backups must be encrypted at rest and stored off-site. **Restore is tested on a
schedule, not assumed** — an untested backup is not a backup. Document the
restore procedure and the measured recovery time.

## Monitoring

- **Horizon** — queue throughput, failed jobs, wait times.
- **Error tracking** — Sentry or equivalent; alert on new issue types.
- **Uptime** — external checks against `/up`.
- **Metrics** — Prometheus and Grafana against application and queue metrics.

Alert on: failed jobs above threshold, queue wait time above threshold, payment
processing errors, campaign publish failures, provider API error rate, and
authentication failure spikes.

Never log secrets. `SecretRedactor` covers audit records; keep the same
discipline in application logging.

## Health

`/up` returns the framework health check. The system health page (§79) is
restricted to platform staff holding `system.manage` and must never be public.
