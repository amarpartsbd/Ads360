# Ads360

Multi-tenant advertising management, wallet, billing and analytics platform for
businesses, agencies and resellers.

Clients register, verify their business, connect their advertising assets, fund a
BDT wallet, and submit campaigns for review. Approved campaigns are published to
Meta and Google through the platform's managed advertising infrastructure, and
spend is reconciled back against the client's ledger.

> **Status: Phase 1 complete.** Authentication, tenancy, RBAC, audit logging,
> business verification (KYC), team management and organization settings are in
> place and covered by tests. The finance, advertising, campaign and analytics
> modules are not yet built — see [Roadmap](#roadmap).

---

## Requirements

| Component  | Version                      |
| ---------- | ---------------------------- |
| PHP        | 8.3+ (8.4 recommended)       |
| PostgreSQL | 16                           |
| Redis      | 7                            |
| Node.js    | 22                           |
| Composer   | 2.x                          |

Required PHP extensions: `pdo_pgsql`, `pgsql`, `redis`, `intl`, `zip`, `gd`,
`bcmath`, `sodium`.

## Getting started

### With Docker

```bash
cp .env.example .env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The application is served at <http://localhost:8080>, Vite at
<http://localhost:5173>, and Mailpit at <http://localhost:8025>.

### Without Docker

Requires PostgreSQL and Redis running locally.

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
createdb ads360 && createdb ads360_testing
php artisan migrate --seed
composer dev          # serve, queue worker, log tail and Vite together
```

### Demo accounts

`php artisan db:seed` creates development fixtures (never in production). Every
account uses the password `Ads360-Demo-Password!1`.

| Account                             | Role              | Area   |
| ----------------------------------- | ----------------- | ------ |
| `owner@ads360.test`                 | Super Admin       | Admin  |
| `finance@ads360.test`               | Finance Admin     | Admin  |
| `compliance@ads360.test`            | Compliance Admin  | Admin  |
| `support@ads360.test`               | Support Agent     | Admin  |
| `client.owner@demo-retail.test`     | Client Owner      | Client |
| `accountant@demo-retail.test`       | Client Accountant | Client |
| `viewer@demo-retail.test`           | Client Viewer     | Client |
| `agency.owner@demo-agency.test`     | Agency Owner      | Client |
| `owner@riverside-foods.test`        | Client Owner      | Client |

`Riverside Foods` is seeded with a verification submission sitting in the
compliance queue, so the review screens have something to show.

**Administrator accounts require two-factor authentication** (spec §9), so a
freshly seeded admin is held at the enrolment page until they enrol. To skip
that while working locally, set `ADMIN_REQUIRE_TWO_FACTOR=false` in `.env`.

## Commands

```bash
composer test        # PHPUnit against PostgreSQL
composer lint        # Pint, check only
composer fix         # Pint, apply
composer analyse     # PHPStan / Larastan
composer check       # lint + analyse + test

npm run dev          # Vite dev server
npm run build        # typecheck and production build
npm run types        # TypeScript only
npm run lint         # ESLint
npm run format       # Prettier
```

Tests run against **PostgreSQL**, not SQLite: the schema uses `jsonb` columns and
partial unique indexes, and the isolation tests are only meaningful against the
engine production uses. Create `ads360_testing` before running them.

## Architecture

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the domain layout, the
tenant isolation model, and the conventions every module follows.

The short version:

- **Domain-oriented**, not MVC-by-type. Business logic lives in
  `app/Domains/<Domain>/`; controllers only translate HTTP to a domain call.
- **Tenant isolation is enforced three independent ways** — a query scope,
  authorization policies, and explicit ownership checks. No single failure
  leaks data.
- **Tenant context is never taken from the request.** It is resolved
  server-side from the authenticated user's membership.
- **Money is never a float.** See `app/Support/Values/Money.php`.
- **Audit records are append-only.** The model refuses updates and deletes.
- **Uploads are identified by their bytes**, not by the extension or MIME type
  the client claims. KYC files live on a private disk behind an authorized,
  audited download route.

## Security

Security posture and the pre-deployment gate are documented in
[`docs/SECURITY.md`](docs/SECURITY.md). Highlights:

- Argon2id password hashing, TOTP two-factor with recovery codes,
  mandatory two-factor for administrators.
- KYC documents on private storage, validated by file signature, reachable
  only through a policy-checked route that audits every read.
- Invitation tokens stored only as hashes, single-use, and unable to grant
  more than the inviter holds.
- Per-account lockout plus a per-address-and-account rate limiter.
- Encrypted, `HttpOnly`, `SameSite` session cookies; database-backed sessions
  so users can review and revoke their own.
- Secrets are redacted before reaching audit records or logs.
- Content Security Policy, HSTS, and the standard hardening headers.

Never commit `.env`, API secrets, OAuth tokens or private keys. CI fails the
build if a `.env` file is tracked.

## Roadmap

Phases follow the platform specification.

| Phase | Scope                                                       | Status      |
| ----- | ----------------------------------------------------------- | ----------- |
| 0     | Auth, tenancy, RBAC, audit, design system                   | Complete    |
| 1     | Client onboarding, KYC, team management                     | Complete    |
| 2     | Wallet, ledger, deposits, pricing, exchange rates, invoices | Next        |
| 3     | Provider abstraction, connected assets, ad account pools    | Planned     |
| 4     | Campaign builder, approval workflow, allocation, publishing | Planned     |
| 5     | Meta integration                                            | Planned     |
| 6     | Analytics pipeline, reporting, reconciliation               | Planned     |
| 7     | Google Ads                                                  | Planned     |
| 8     | Agency and reseller module                                  | Planned     |
| 9     | White label, advanced risk, AI assistance, enterprise APIs  | Planned     |

Phase 2 does not start until the financial concurrency tests pass, and no phase
starts until the tenant isolation suite is green.

## Provider policy

The platform uses official provider APIs and authorized assets only. It does not
attempt to work around provider policies, account restrictions, verification
requirements, spend limits or review systems. Where a provider rejects or
restricts an operation, the platform records and surfaces the actual state.

## Licence

Proprietary. All rights reserved.
