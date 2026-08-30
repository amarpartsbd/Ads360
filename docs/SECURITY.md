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

### Business verification and documents

- A client can prepare and submit their own verification but can never decide
  it. `clients.verify` is held by platform roles only, and the policy denies
  `review` to every client and agency role — verified by a test that walks each
  role in turn.
- Platform staff cannot edit a client's declaration, and cannot delete a
  client's evidence.
- KYC files are stored on a private disk with random object keys, identified by
  file signature rather than by the client's declared MIME type, and size- and
  dimension-checked. A rejected upload writes nothing.
- Documents are reachable only through a policy-checked route that streams them
  with `nosniff` and a restrictive CSP. **Every read is audited.**
- A document's storage location (`disk`, `path`, `checksum`) is hidden from
  serialisation, so it cannot leak through a response.
- Internal compliance notes are `$hidden` on the model and never included in a
  client-facing response — asserted by a test that greps the rendered client
  page for a planted internal note.
- A check constraint prevents an incomplete verification from entering the
  compliance queue, whatever application code attempts.

### Invitations

- Only a SHA-256 of the token is stored; the plaintext exists once, in transit.
- Tokens are single-use, expire after seven days, and are replaced on resend so
  a forwarded email stops working.
- An invitation cannot carry permissions the inviter does not hold, and a client
  cannot invite anyone into a platform role.
- Acceptance re-validates the address, the tenant and the account type: a
  platform account cannot join a client organization, and an account belonging
  to another tenant cannot redeem the invitation.
- The public invitation endpoints are rate limited.

### Money

- **The ledger is the source of truth**; wallet balance columns are a cache
  written only by the ledger writer, in the same transaction as the entries they
  summarise, and recomputable from them.
- Ledger entries are append-only. The model refuses updates and deletes, and a
  correction is a reversal that leaves both visible.
- Database constraints, not application code, guarantee the invariants: a wallet
  can never go negative, an entry moves money in exactly one direction, an entry
  can be reversed at most once, and a payment can produce at most one deposit
  entry.
- Every balance mutation takes a row lock and **re-reads the balance under the
  lock** before validating. Real forked-process tests prove two requests cannot
  spend the same balance, over-allocate a reservation, lose a concurrent
  deposit, or double-reverse an entry.
- Amounts are integer minor units throughout. No monetary value is a float, in
  the database, in PHP, in configuration, or in the browser — the interface
  renders server-formatted strings and performs no arithmetic.
- Currencies are never converted implicitly. A mismatch raises.

### Separation of duties

- A client submits a deposit; only platform staff holding `payments.verify`
  confirm it. No client or agency role holds that permission, verified by a test
  that walks each role.
- Wallet adjustments and refunds are platform-only, and above a configured
  threshold require a second approver who is not the requester. A unique index
  prevents one person satisfying a two-approval threshold twice over.
- Operations staff hold no financial permissions at all; finance staff hold no
  campaign permissions. Both directions are asserted by tests.
- Approving executes the payload recorded when the action was requested, so what
  runs is what was reviewed.

### Provider connections and credentials

- Access and refresh tokens are stored as `encrypted` casts. A dump of
  `provider_connections` yields ciphertext, and a test asserts the stored bytes
  differ from the token.
- Both token columns are `$hidden`, so no `toArray()`, `toJson()` or Inertia
  prop can carry one to a browser. A test loads the connected-assets page and
  asserts no token appears in the response body.
- Reads go through `accessToken()` / `refreshToken()` and writes through
  `storeCredentials()` / `clearCredentials()`. Nothing else touches the columns,
  which makes every credential access greppable.
- A check constraint (`provider_connections_live_rows_have_credentials`) forbids
  a row that claims to be usable while holding no credential, and disconnecting
  clears the tokens in the same transaction as the revocation.
- Queued provider work carries the connection's key, never the model, so
  encrypted columns never reach the queue store.
- OAuth state is stored as a SHA-256 hash, expires in 15 minutes, and is claimed
  by a conditional `UPDATE` so it can be redeemed exactly once. The callback
  re-derives the user and organization server-side and refuses a state issued
  for a different one. A rejected state is audited without recording the state
  value.
- Authorisation codes are never logged or audited. The audit record for a
  connection describes the grant — account name, scopes, expiry — and never the
  credential behind it.
- Platform staff cannot start an authorisation flow on a client's behalf; the
  grant is made by the client in their own provider session.

### Managed ad accounts

- `ad_accounts` is not tenant-scoped and is never exposed to a client. Its
  policy admits platform staff only, and feature tests assert a client user
  receives 403 on every inventory route.
- Provider error text is not stored or shown. Failures are recorded using the
  adapter's client-safe message; a test asserts a provider error code does not
  reach the stored `last_error`.
- Health alerts go to platform staff holding `ad_accounts.manage_health`, never
  to clients — an account may serve several clients at once.
- Pool allocation rules are held behind `ad_accounts.manage_pools`, which is
  marked sensitive in the permission registry: the rules decide whose money runs
  through which account.

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
- Signed temporary URLs for documents — implemented for S3-compatible disks;
  local development streams through the authorized controller instead.
- Client risk scoring (§12) — Phase 9.

- Webhook signature verification (§52) — Phase 5.
- Account allocation itself (§19) — Phase 4. Pools carry their rules and the
  eligibility evaluator is in place; the engine that picks and holds an account
  arrives with the campaign engine.

- Reconciliation against provider spend (§78) — Phase 6. Wallet-level
  reconciliation exists (`Wallet::isReconciled()`) and is surfaced in the admin
  wallet view; the scheduled job that compares provider spend against the ledger
  arrives with the analytics pipeline.
- Live payment gateways (§33) — the provider abstraction and a mock adapter
  exist; SSLCOMMERZ, bKash and Nagad adapters land when merchant accounts are
  approved. Only manually verified methods are offered in the interface today.
- Admin IP allowlisting — the configuration key exists
  (`ADMIN_IP_ALLOWLIST`); enforcement is not yet wired.

## Pre-deployment gate

From specification §98. Do not deploy to production until every line is true.

```
[x] Tenant isolation tests pass
[x] Authorization tests pass
[x] Wallet race-condition tests pass
[x] Payment idempotency tests pass
[ ] Campaign idempotency tests pass           Phase 4
[x] OAuth state validation exists
[x] Provider secrets encrypted
[x] Admin 2FA enabled
[x] KYC files private
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
