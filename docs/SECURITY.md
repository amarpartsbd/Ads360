# Security

What is implemented today, and what must be true before production.

---

## Implemented

### Authentication

- **Argon2id** password hashing (`config/hashing.php`), 64 MiB and three
  passes by default. Raise `ARGON_MEMORY` and `ARGON_TIME` with the hardware.
- Password policy: 12 characters minimum, mixed case, a number, a symbol, and
  rejected if the password appears in a known breach corpus. The breach check
  is a k-anonymity range query — the password never leaves the server.
- **TOTP two-factor** with recovery codes, via Fortify.
- **Mandatory two-factor for administrators** (`EnforceAdminTwoFactor`). An
  administrator without a confirmed authenticator can reach only the enrolment
  pages and logout.
- **Account lockout** after `AUTH_MAX_LOGIN_ATTEMPTS` failures, held for
  `AUTH_LOCKOUT_MINUTES`.
- **Rate limiting** keyed on address *and* account together, so one attacker
  cannot lock every account from a single address, and a distributed attempt
  against one account is still counted.
- **Login history**, recorded whether or not the attempt succeeded and whether
  or not the address matches a real account, so credential stuffing is visible
  in the data. The attempted password is never stored in any form.
- Timing is equalised for unknown accounts, so a response cannot be used to
  enumerate registered addresses.
- **Session management** — sessions are database-backed so a user can list and
  revoke their own from security settings. Revocation is scoped by `user_id`,
  so another user's session identifier simply does not match.

### Authorization and tenancy

- Permission-based access control; no decision branches on a role name.
- Three independent isolation defences (query scope, policies, ownership
  checks) — see [ARCHITECTURE.md §3](ARCHITECTURE.md#3-tenant-isolation).
- Tenant context resolved server-side from membership, never from the request.
- `EnsureTenantContext` fails closed: a client request without context is
  refused rather than served unscoped.
- Platform roles are invisible and ungrantable to tenant users, so a client
  cannot discover — let alone grant themselves — administrative access.
- Nobody may change their own role assignments or suspend themselves.
- System roles are read-only to everyone, including super administrators.

### Auditing

- Append-only `audit_logs`; the model throws on update and delete, and the
  policy denies every mutating ability.
- Secrets redacted before storage, by broad case-insensitive key matching.
- Every request carries a correlation id, shared into the log context and
  returned as `X-Request-Id`. An inbound id is accepted only if it matches a
  strict pattern, so a caller cannot inject text into log lines.

### Transport and browser

- `SecurityHeaders`: `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Cross-Origin-Opener-Policy`, `Permissions-Policy`, and a
  Content Security Policy outside local development. HSTS over HTTPS only —
  sending it on plain HTTP is meaningless.
- Session cookies are encrypted, `HttpOnly`, `SameSite=Lax`, and `Secure`
  outside debug mode.
- CSRF protection on every state-changing route (Laravel default).
- Nginx serves PHP only through the front controller and denies dotfiles.

### Data handling

- Provider credentials are never sent to the browser. Nothing in the shared
  Inertia props carries a token, an internal risk note, or another tenant's
  identifier.
- `password`, `remember_token`, `two_factor_secret` and
  `two_factor_recovery_codes` are hidden from serialisation, and this is
  asserted by a test.
- Development runs entirely on mock provider adapters
  (`ADVERTISING_DRIVER=mock`), so no live credential is needed to work on the
  platform.

## Not yet implemented

These arrive with their phases and are listed so their absence is not mistaken
for an oversight:

- Step-up authentication on privileged actions (§9) — password confirmation is
  wired, the per-action gate arrives with the finance module.
- Maker-checker approvals (§25) — Phase 2.
- OAuth state validation and encrypted provider credentials (§16) — Phase 3.
- Webhook signature verification (§52) — Phase 5.
- Private object storage and signed URLs for KYC documents (§55) — Phase 1.
- Wallet race-condition and payment idempotency tests (§56, §30) — Phase 2,
  and Phase 2 does not start until they pass.
- Admin IP allowlisting — the configuration key exists
  (`ADMIN_IP_ALLOWLIST`); enforcement is not yet wired.

## Pre-deployment gate

From specification §98. Do not deploy to production until every line is true.

```
[x] Tenant isolation tests pass
[x] Authorization tests pass
[ ] Wallet race-condition tests pass          Phase 2
[ ] Payment idempotency tests pass            Phase 2
[ ] Campaign idempotency tests pass           Phase 4
[ ] OAuth state validation exists             Phase 3
[ ] Provider secrets encrypted                Phase 3
[x] Admin 2FA enabled
[ ] KYC files private                         Phase 1
[x] CSRF protections verified
[x] Rate limits configured
[ ] Webhook signatures verified               Phase 5
[x] Audit logging enabled
[x] Production debug disabled                 APP_DEBUG=false
[x] HTTPS enforced                            forced in production
[x] Secure cookies enabled
[ ] Backups configured                        deployment task
[ ] Restore process tested                    deployment task
[ ] Error monitoring configured               deployment task
[ ] Failed queue monitoring configured        Horizon is wired; alerting is not
[x] Secrets removed from repository           enforced by CI
[x] Dependency security scan completed        composer audit, npm audit in CI
```

## Reporting a vulnerability

Report privately to the address in `PLATFORM_SUPPORT_EMAIL`. Please do not open
a public issue.
