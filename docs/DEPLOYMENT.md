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
| `ads:ingest-metrics` | Hourly | Re-reads a trailing window of daily performance from each provider and upserts it, because providers restate past days |
| `ads:reconcile-spend` | Daily, 03:30 | Compares provider-reported spend against what the ledger captured, and raises differences past tolerance |
| `ads:prune-exports` | Daily, 04:00 | Removes expired report files from the private disk; the record of who exported what stays |

All of them are `withoutOverlapping()` and `onOneServer()`. The `providers`,
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
3. **The platform's own grant.** Create a **system user** in the platform's
   Business Manager, give it access to the managed ad accounts, and put its
   token in `META_SYSTEM_USER_TOKEN`. Managed ad accounts have no client
   connection behind them, so without this nothing publishes to one — the
   adapter refuses by name rather than letting Meta return an error that
   explains nothing.

   Use a system user, never a person. A token belonging to an employee stops
   working the day they leave, and the failure surfaces as campaigns that
   silently stop publishing.
4. **Webhook.** Point the subscription at `https://<host>/webhooks/meta` and set
   `META_WEBHOOK_VERIFY_TOKEN` to a long random string. The handshake fails
   closed, so a mismatch shows up immediately at subscription time.
5. **Shakedown.** Connect one internal account, publish one small campaign
   against a real ad account, and confirm three things by hand: the campaign
   appears in Ads Manager with an `[ads360:…]` suffix in its name, a second
   publish of the same campaign creates nothing new, and the spend the
   reconciler captures matches what Meta reports.

   Confirm one more thing while you are there: whether the app has **Require
   App Secret** enabled. If it does, Meta expects an `appsecret_proof`
   parameter alongside a user or system user token, and the adapter does not
   send one yet — `MetaConfig::appSecretProof()` exists and is unused. With the
   setting off, which is the default, nothing is needed.
6. **Watch the version.** `META_API_VERSION` is pinned. Meta deprecates a
   version roughly every two years; the upgrade is a deliberate change with its
   own shakedown, not something to leave to a default.

If a live adapter has to be backed out, set `ADVERTISING_DRIVER=mock` — but note
that mocks refuse to run in production, so this is a development escape hatch,
not a production rollback. A production rollback means the previous release.

## Going live with Google Ads

The same caveat as Meta, and one extra. The adapter is built against Google's
documented request shapes and covered by tests that fake the API. **Those tests
cannot prove Google agrees**, and two assumptions in particular have never met
the real API:

- that `LIKE` on `campaign.name` filters the way the idempotency lookup expects,
  which is what decides whether a second campaign gets created;
- that a `campaign` query with no date condition returns all-time metrics, which
  is what the ledger reconciles against.

Both are verified by hand in the shakedown below before any client campaign runs.

Before turning `FEATURE_GOOGLE_ADS` on and switching `ADVERTISING_DRIVER` to
`live`:

1. **Developer token.** Apply for one against the platform's Google Ads manager
   account and get it to Basic access at least. A test-account token reaches
   only test accounts, and a pending one reaches nothing — with an error that
   names neither. This is separate from OAuth and is the credential people
   forget.
2. **OAuth client.** Create a Web application client in Google Cloud, enable the
   Google Ads API, and register `GOOGLE_ADS_REDIRECT_URI` exactly, including
   scheme and trailing path. Add the `adwords`, `openid` and `email` scopes to
   the consent screen and publish it — an app left in testing only serves the
   accounts listed on it, and its refresh tokens expire after seven days.
3. **Manager account and the platform's own grant.** Set
   `GOOGLE_ADS_LOGIN_CUSTOMER_ID` to the manager the platform operates its
   inventory through — leaving it empty is right only when every account is
   owned directly by the authenticating user. Then authorise the platform's own
   Google account through the same consent flow clients use and put the refresh
   token it returns in `GOOGLE_ADS_REFRESH_TOKEN`. A managed ad account has no
   client grant behind it and Google authenticates every call, so without this
   nothing publishes.
4. **Shakedown.** Connect one internal account, publish one small search
   campaign against a real customer account, and confirm by hand:
   - the campaign appears in Google Ads with an `[ads360:…]` suffix in its name,
     and so does its budget;
   - a second publish of the same campaign creates nothing new — this is the
     `LIKE` assumption above;
   - `campaignInsights` returns a spend figure matching the account's own
     all-time total — this is the date-filter assumption above;
   - the spend the reconciler captures matches what Google reports, in minor
     units rather than a factor of ten thousand out.
5. **Watch the version.** `GOOGLE_ADS_API_VERSION` is pinned. Google publishes a
   new version roughly every four months and sunsets each about a year later;
   the upgrade is a deliberate change with its own shakedown.

### What the Google adapter does not do

Declared through `supports()` and through `CampaignObjective::for()`, so callers
degrade rather than fail:

| Not supported                                | Why                                                                                  |
| -------------------------------------------- | ------------------------------------------------------------------------------------ |
| Webhooks                                      | Google Ads has no push mechanism; state changes are found by polling                  |
| Account spend limits                          | Exposed only for monthly-invoiced accounts, which the adapter does not read           |
| Lead forms                                    | Needs its own handling of personal data                                               |
| Display, video, shopping and app campaigns    | Different ad formats and creative requirements; only search campaigns are published   |
| Awareness and app-promotion objectives        | Those are display and app campaigns, so they are absent from the Google objective list |
| Interest, gender, device and age targeting    | Google offers these on search only as bid adjustments, so an ad set asking for them is refused rather than published without them |

A responsive search ad needs at least three headlines and two descriptions.
The campaign builder collects them ("More headlines", "More descriptions" on the
ad form); an ad without enough of them is refused at publish time with a message
naming the minimum, rather than having copy invented to fill the gap.

## Turning on the agency module

`FEATURE_AGENCY_MODULE=true`. Off, existing agencies stay listed in the admin
area but their client screens are closed and none can be provisioned — the flag
gates the module, not the data.

An agency is provisioned by platform staff, never by registration: being an
agency is a commercial decision. Admin → Agencies → Provision creates the
tenant, its own workspace and its owner in one transaction. The owner verifies
their email like anyone else.

Two things to know before the first agency goes live:

- **An agency owner reaches every client of their agency**, including clients
  added later. That is the point of the tenant-scoped grant, and it makes the
  owner's password the widest client-side credential on the platform. Treat it
  accordingly.
- **Assign a fee schedule.** Until you do, the agency's clients are priced by
  the platform default, which is the direct-client rate. `FinanceSeeder` ships
  "Agency standard" and "Reseller preferred" as templates; assigning one copies
  it to a plan belonging to that agency alone, so editing the template later
  does not change what an existing agency pays.

Agency staff below owner level are assigned per client, from the agency's own
client detail screen. An agency cannot verify its own clients — that stays with
platform compliance.

## Turning on white label

`FEATURE_WHITE_LABEL=true`, then grant `branding.manage` — agency-owner has it
by default. A tenant sets its name, logo, primary colour, support address and
domain under Settings → Branding.

Two things need doing outside the application:

- **DNS.** The customer points a CNAME at the platform. Storing a domain does
  not serve it.
- **TLS.** A certificate has to exist for that hostname before it resolves here,
  or every visitor gets a browser warning with the customer's brand on it.

The primary colour is refused unless it reaches 4.5:1 against white. That is not
adjustable by configuration, and deliberately: a customer who talks you into a
lighter brand colour gets an interface their own staff cannot read, and the
support cost lands here.

The admin area is never white-labelled.

## Turning on the risk engine

Nothing to turn on — risk is assessed for every organization from the moment
this release is deployed, by `clients:assess-risk` every six hours. Add it to
the schedule check when reviewing cron health.

Two operational notes:

- **The queue is at Admin → Client risk**, and needs `risk.view`.
  compliance-admin has it; finance-admin can read but not flag.
- **A high or critical score adds an approver to financial actions on that
  client.** Nothing is blocked, suspended or frozen. If an operator expects risk
  to stop an account, that expectation is wrong and the queue's own guidance
  says so.

Backfilling a fresh deployment is `php artisan clients:assess-risk` — the sweep
is idempotent and can be run at any time.

## The assistant

`ASSISTANT_DRIVER` is `none` and `FEATURE_AI_ASSISTANT` is false. Leave both
that way in production for now: the only adapter that exists is a mock, and it
**refuses to instantiate in production** because stub copy would otherwise be
published to real audiences under a client's name.

When a live adapter is written, two rules are not negotiable and are enforced in
code rather than by convention:

- Output is a recommendation. `DecideRecommendation` has no wallet, campaign
  service or publisher injected, so acceptance cannot execute anything.
- Provenance is stored on every row — driver, model and version — and the brief
  itself never is, only a digest.

Performance insights (Admin and client analytics) need none of this: they are
arithmetic over the client's own figures and run whether or not an assistant is
configured.

## What Phase 9 does not include

Named here so nobody goes looking for them:

| Not built | Why it is not half-built |
|---|---|
| Advanced automation rules | Rules that pause campaigns or move budget touch money without a person present; that needs its own maker-checker story, not a rules table |
| Data warehouse integration | An export target, a schedule and a schema contract — a phase in itself |
| Public enterprise APIs | §54 and §85 set a bar (versioning, tenant scoping, rate limiting, output filtering) that a stub API would fail |
| SSO | The identity model supports it; no provider integration exists |

There is deliberately no partial implementation of any of them to mistake for a
finished one.

## The first administrator

A production deployment seeds permissions, roles and pricing, and no people.
`DemoDataSeeder` is the only seeder that creates users and is deliberately
development-only: a password shipped with the application is a password on
every installation of it. So a fresh deployment is a working platform nobody
can sign in to until someone with shell access runs:

```bash
php8.4 artisan ads:create-admin
```

It prompts for a name, an address and a password, and grants a platform role —
`super-admin` unless `--role` says otherwise. The password is prompted for
rather than accepted as an option, so it stays out of shell history and out of
the process list, and it is held to the same policy as everyone else's.

The account is created verified, because the person running it already had root
on the server and there is nobody left for an emailed link to prove anything
to. It is not created exempt from two-factor: with `ADMIN_REQUIRE_TWO_FACTOR`
on, the first sign-in stops at the enrolment page.

## Single-node deployment

`deploy/` holds a complete provisioning and release setup for one Ubuntu 24.04
server — nginx, a dedicated PHP-FPM pool, PostgreSQL, Redis, Horizon and the
scheduler under systemd, TLS from Let's Encrypt, and a firewall. See
[`deploy/README.md`](../deploy/README.md).

It is the topology at the top of this file collapsed onto one box: the same
services, none of the redundancy. What it gives up is stated there rather than
implied — there is no failover, and a snapshot of the disk is not a consistent
database backup.

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

On a single node this is `deploy/deploy.sh`, which does the following and puts
maintenance mode back off even when a step fails:

```bash
php artisan down --retry=15 --secret="$(openssl rand -hex 16)"
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --force       # permissions and roles this release adds
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl reload php8.4-fpm  # OPcache does not revalidate timestamps
php artisan horizon:terminate     # workers restart with the new code
php artisan up
```

The `--secret` makes the new release previewable at `https://<host>/<secret>`
while it is still down for everyone else.

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
