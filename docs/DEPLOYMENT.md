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

### Scheduled work

`routes/console.php` holds the schedule. Both current entries only *queue*
work — the provider calls happen on the `providers` queue, so a slow provider
delays its own checks and nothing else.

| Command | Cadence | What it does |
| --- | --- | --- |
| `ads:check-connections` | Hourly | Verifies each live client grant, refreshing tokens a day ahead of expiry and warning the client when it cannot |
| `ads:check-ad-accounts` | Hourly | Asks each provider about the managed accounts in service, updating spend mirrors, billing standing and health |
| `ads:sync-campaign-spend` | Every 15 minutes | Reconciles what each running campaign has spent against its wallet hold, and finishes campaigns whose run is over |

All three are `withoutOverlapping()` and `onOneServer()`. The `providers`,
`campaign_publish` and `campaign_sync` queues are served by the `campaigns`
Horizon supervisor; a deployment that adds queue nodes must include it.

Spend reconciliation runs four times an hour rather than hourly because its
figures decide what a client is charged, and a campaign spending quickly should
not run far ahead of what has been drawn from its hold.

## Going live with Meta

The adapter is built against Meta's documented request shapes and is covered by
tests that fake the Graph API. **Those tests cannot prove Meta agrees.** Only a
real app with real credentials can, so the first live connection needs a
deliberate shakedown rather than a silent cutover.

Before switching `ADVERTISING_DRIVER` to `live`:

1. **Meta app.** Create one, add the Marketing API, and complete App Review for
   `ads_management`, `ads_read`, `business_management`, `pages_show_list`,
   `pages_read_engagement` and `instagram_basic`. Nothing works in production
   without review; a development-mode app only serves its own admins.
2. **Redirect URI.** `META_REDIRECT_URI` must match the value registered in the
   app exactly, including scheme and trailing path. Meta compares it literally.
3. **Webhook.** Point the subscription at `https://<host>/webhooks/meta` and set
   `META_WEBHOOK_VERIFY_TOKEN` to a long random string. The handshake fails
   closed, so a mismatch shows up immediately at subscription time.
4. **Shakedown.** Connect one internal account, publish one small campaign
   against a real ad account, and confirm three things by hand: the campaign
   appears in Ads Manager with an `[ads360:…]` suffix in its name, a second
   publish of the same campaign creates nothing new, and the spend the
   reconciler captures matches what Meta reports.
5. **Watch the version.** `META_API_VERSION` is pinned. Meta deprecates a
   version roughly every two years; the upgrade is a deliberate change with its
   own shakedown, not something to leave to a default.

If a live adapter has to be backed out, set `ADVERTISING_DRIVER=mock` — but note
that mocks refuse to run in production, so this is a development escape hatch,
not a production rollback. A production rollback means the previous release.

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
