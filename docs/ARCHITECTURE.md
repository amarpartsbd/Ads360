# Architecture

How the platform is put together, and the conventions every module follows.

---

## 1. Layout

```
app/
├── Domains/            Business logic, one directory per domain
│   ├── Identity/       Users, roles, permissions, authentication, invitations
│   ├── Tenant/         Tenants, organizations, membership, tenant context
│   ├── Compliance/     Business verification, documents, review decisions
│   ├── Client/         Private document storage and upload validation
│   ├── Wallet/         Ledger, balances, reservations, adjustments, refunds
│   ├── Payment/        Deposits, gateways, verification
│   ├── Billing/        Pricing, exchange rates, invoices
│   ├── Advertising/    Provider abstraction, managed ad accounts, pools
│   ├── Campaign/       Campaigns, ad sets, ads, creatives, publishing
│   ├── Integration/    Provider connections, OAuth state, connected assets
│   ├── Audit/          Immutable audit trail and secret redaction
│   ├── System/         Platform settings and feature flags
│   └── …               Reserved for later phases (see §8)
├── Http/
│   ├── Controllers/    Admin/, Client/, Auth/, Shared/ — thin
│   ├── Middleware/     Request id, security headers, tenancy, access control
│   └── Requests/
├── Providers/
└── Support/            Money, shared model concerns
```

A domain may contain `Actions/`, `DTOs/`, `Enums/`, `Events/`, `Listeners/`,
`Models/`, `Policies/`, `Services/` and, where useful, `Concerns/`, `Rules/`,
`Scopes/`. Only what a domain actually needs is created — an empty
`Repositories/` directory is noise, not architecture.

**Controllers stay thin.** A controller validates, delegates, and renders. If
you find business rules in one, they belong in an Action or Service.

**Actions vs. Services.** An Action performs one operation end to end
(`RegisterClient`). A Service holds behaviour used by many callers
(`AuditRecorder`, `TenantContext`). Prefer an Action; reach for a Service when
two or more Actions need the same behaviour.

## 2. Tenancy

```
Platform
   └── Tenant            DIRECT_CLIENT | AGENCY | RESELLER | ENTERPRISE
        └── Organization the advertiser account users work inside
             └── Users   through organization_user
```

A direct client tenant holds one organization. An agency tenant holds one per
client it manages, so cross-agency isolation is exactly tenant isolation and
needs no separate mechanism.

A user belongs to **at most one tenant**. Platform staff have `tenant_id = null`
and `is_platform_user = true`. Keeping a user inside a single tenant lets login
stay a plain email lookup, and makes cross-tenant membership impossible at the
database level rather than only in application code. The trade-off is that one
email address cannot hold accounts in two tenants.

## 3. Tenant isolation

This is the part to understand before changing anything.

**Context is never taken from the request.** A tenant or organization
identifier arriving in a URL, body or header is an untrusted *claim*. It is
looked up strictly within the authenticated user's own active memberships, and
if it does not match, nothing is found. `ResolveTenantContext` derives context
from `organization_user`; the session may remember the last selected
organization, but that value is re-verified on every request.

**Three independent defences.** Any one of them failing must not be enough to
leak data, so all three exist and each is tested separately:

1. **`TenantScope`** — a global scope on every model using `BelongsToTenant`,
   constraining queries to the bound tenant. It also stamps `tenant_id` on
   create, so application code never sets it and cannot set it wrongly.
2. **Policies** — `OrganizationPolicy`, `UserPolicy`, `RolePolicy` and
   `AuditLogPolicy` establish membership *before* looking at permissions.
3. **Explicit ownership checks** — route model binding resolves on ULID
   `public_id`, and every lookup is constrained by the owning relation.

**The scope only filters when a tenant is bound.** That is deliberate: platform
staff and console commands query across tenants on purpose. To stop that
becoming a hole, `EnsureTenantContext` **fails closed** — a client-application
request without context is refused, never served unscoped.

To query across tenants deliberately, call `Model::acrossTenants()`. It is
spelled out precisely so every call site is somewhere a reviewer looks closely.

### The isolation test suite is a gate

`tests/Feature/Security/TenantIsolationTest.php` and `AuthorizationTest.php` are
not ordinary tests. No later module ships while they are red.

## 4. Authorization

Permissions, not role names. Every decision resolves to a case of
`Domains\Identity\Enums\Permission`, and each becomes a gate of the same name,
so `$user->can('campaigns.approve')` works anywhere.

Roles are named bundles of permissions:

- **Scope** — `PLATFORM` (staff), `TENANT` (across a tenant's organizations),
  `ORGANIZATION` (one organization).
- **System roles** (`tenant_id = null`, `is_system = true`) are shared by every
  tenant and read-only to everyone, including platform administrators. A tenant
  needing something different creates its own role.
- **Grants** are scoped: `role_user.organization_id` names the organization, or
  is null for a platform- or tenant-wide grant. Postgres treats nulls as
  distinct in a unique index, so two partial unique indexes cover both shapes.

Permissions shared with the front end drive *what is shown*, never what is
allowed. The server authorizes every request independently.

## 5. Auditing

`audit_logs` is append-only. `AuditLog` throws on update and delete, the policy
denies every mutating ability, and there is no `updated_at` column. Corrections
are made by writing a new record.

`AuditRecorder` takes actor, tenant, address and request id from the current
request rather than from the caller, so a call site cannot claim to be someone
else. Payloads pass through `SecretRedactor` first — key matching is broad and
case-insensitive, because a false positive costs one redacted field while a
false negative writes a credential to durable storage.

Diffing a model change requires capturing state **before** the save:

```php
$before = AuditRecorder::snapshot($organization);
$organization->update([...]);
$audit->recordChange(AuditAction::OrganizationUpdated, $organization, $before);
```

Eloquent syncs a model's originals on save, so an after-the-fact diff would
report the new value as the old one.

## 6. Business verification

A `VerificationProfile` is the organization's single verification record; a
resubmission updates it rather than creating a second one. `VerificationStatus`
owns the transition table, and `canTransitionTo()` is the only definition of
what may follow what — the review action and the tests both read it.

Two separations matter:

- **Declaring and deciding.** A client fills in and submits the declaration;
  only platform staff holding `clients.verify` decide it. `VerificationProfilePolicy`
  denies `update` to platform staff and `review` to everyone else, so no
  combination of client permissions adds up to self-verification.
- **Internal notes and client messages.** A `VerificationReview` carries both.
  `internal_note` is `$hidden` on the model and selected explicitly only by the
  admin controller; `client_message` is what the client sees. A review row is
  append-only for the same reason an audit row is.

A profile is a draft until submitted, so its declaration columns are nullable —
but a check constraint (`verification_profiles_complete_when_submitted`) makes
it impossible for a row to leave `NOT_SUBMITTED` without them. That is a
database guarantee, not an application convention: no seeder, import or future
code path can put an incomplete submission into the compliance queue.

## 7. Documents and uploads

`DocumentStorage` (`app/Domains/Client/Services/`) is the only way a file enters
the platform.

- **Identified by content.** The leading bytes decide the type, and the
  extension must agree. A PHP script named `licence.pdf` is rejected. RIFF is
  treated as the container it is, so an AVI cannot pass as a WebP.
- **Random object paths.** Nothing from the upload contributes to the path, and
  a crafted filename cannot traverse out of the directory. Nothing relies on
  paths being unguessable — reads are authorized regardless.
- **Never public.** Files live on the `documents` disk with private visibility.
  The single download route authorizes through `VerificationDocumentPolicy` and
  writes an audit record on every read: who looked at whose identity documents
  is exactly what an audit trail is for.
- **Nothing written on rejection.** A failed upload leaves neither a row nor an
  orphaned object; if the row fails after the bytes land, the bytes are removed.

## 8. Invitations

Only a SHA-256 of the invitation token is stored, so a leaked row cannot be
redeemed. A token is spent on acceptance and replaced on resend, which
invalidates a forwarded copy of the earlier email.

`InviteTeamMember` refuses to issue an invitation carrying permissions the
inviter does not themselves hold — without that, a client admin could invite an
owner and then sign in as them. `AcceptInvitation` re-validates everything at
redemption: the state when the email was sent is not evidence of the state now.

`ManageTeamMember` guards the other direction: an organization can never be left
with nobody able to administer it, and nobody may act on their own membership.

## 9. The ledger

The financial rule the whole module protects: **the ledger is the truth, and the
wallet's balance columns are a cache of it.** They are named `_cached` for that
reason, they are written only by `LedgerWriter`, and `Wallet::isReconciled()`
recomputes them from the entries so drift is detectable rather than invisible.

`ledger_entries` is append-only. A mistake is corrected by a reversal pointing
back at the entry it undoes, so both remain visible. Database constraints carry
the invariants that must never depend on application code:

- an entry moves money in exactly one direction (`debit` xor `credit`);
- balances and snapshots can never go negative;
- an entry may be reversed at most once;
- at most one `DEPOSIT` entry may reference a given payment.

**Available and reserved.** `debit`/`credit` move the available balance;
`reserved_delta` moves the reserved one. A reservation debits available and adds
to reserved; a release does the reverse. Spending against a hold is therefore
*two* entries in one transaction group — a `RELEASE` back to available, then a
`CAMPAIGN_SPEND` debit out of the wallet. That keeps every entry single-sided
and makes a statement read the way the money actually moved.

### Concurrency

`LedgerWriter::post()` opens a transaction, takes `SELECT … FOR UPDATE` on the
wallet, and **re-reads the balances after the lock is held**. That last step is
the one that matters: a balance read before the lock is a balance another
request may already have spent.

**Lock ordering is wallet, then reservation, everywhere.** `reserve()` locks the
wallet before inserting the reservation row, because that insert takes a share
lock on the same wallet through its foreign key — acquiring the exclusive lock
afterwards deadlocked two concurrent reservations. The concurrency suite caught
it, which is what it is for.

`tests/Feature/Wallet/WalletConcurrencyTest.php` forks real processes that
compete for one wallet. It is a gate: nothing in finance ships while it is red.

## 10. Pricing, rates and invoices

**Pricing** resolves most-specific-first — client override, then tenant plan,
then platform default — and takes the first active plan whole. Plans are not
merged, so an override is easy to reason about and impossible to half-apply.
Tax is computed last, over the fee subtotal, because it applies to what the
platform charges rather than to the client's ad budget.

**Exchange rates** are effective-dated and never edited; publishing closes the
previous row. A conversion returns the rate alongside the amount so the caller
stores the snapshot with the transaction — history is never recalculated with
today's rate. A missing rate raises rather than guessing.

Every priced amount and every conversion carries a snapshot onto the ledger
entry, so an invoice from six months ago explains itself even after the plan and
the rate have changed.

**Invoices** freeze on issue: the model refuses to change a financial field once
finalised, and a correction is a void plus a credit note.

## 11. Maker-checker

Actions above a configured threshold do not execute on request. They become an
`approval_request` carrying the payload needed to run them later, and only
execute once enough *other* people have approved.

Two rules do the work, and neither depends on the interface: the requester can
never approve their own request, and a unique index on
`(approval_request_id, approver_id)` means one person cannot satisfy a
two-approval threshold by clicking twice.

Approving executes the recorded payload — not anything resubmitted alongside the
approval — so what runs is what was approved.

## 12. Advertising providers

`app/Domains/Advertising/`, with the client-facing half in
`app/Domains/Integration/`.

**One contract, many platforms.** `AdvertisingProvider` is what the rest of the
system talks to; `ProviderManager` resolves an adapter for a `Provider`. No
caller branches on which platform it is dealing with.

**Capabilities are asked, not assumed.** `supports(ProviderCapability)` answers
whether an adapter can do a thing at all. A provider that cannot refresh tokens,
enumerate assets or report spend says so, and the caller takes the documented
fallback rather than discovering the gap in production.

**Mock adapters are the development default.** `ADVERTISING_DRIVER=mock` gives
the whole round trip — authorise, exchange, discover, verify, report health —
with no live credentials and no app review. The mocks refuse to instantiate in
production, and the simulated consent screen 404s there.

### Connections and credentials

`ProviderConnection` holds a client's grant. Three things guard the tokens:

- both token columns are `encrypted` casts, so plaintext never reaches the
  database;
- both are `$hidden`, so no `toArray()`, `toJson()` or Inertia prop can carry
  one to a browser;
- reading goes through `accessToken()` / `refreshToken()` and writing through
  `storeCredentials()` / `clearCredentials()`, so every credential access is one
  grep away.

A check constraint enforces the other half: a row that has not been revoked must
still hold a credential, and a revoked one holds none.

**OAuth state.** `OAuthStateGuard` stores only `hash('sha256', $state)`, so the
stored row cannot be replayed as a callback. Redeeming checks unknown, consumed,
expired, wrong user, wrong organization and provider mismatch, then claims the
row with a conditional `UPDATE` so two simultaneous callbacks cannot both
succeed. A rejected state is audited; the state value itself is never stored.

**Assets are never deleted.** An asset missing from a discovery run becomes
`PERMISSION_LOST` or `UNAVAILABLE`. Campaigns and reports point at it, and §62
keeps records other records depend on.

## 13. Managed ad accounts and pools

`ad_accounts` is deliberately **not** tenant-scoped: the inventory is shared,
one account may serve different clients over its life, and no client ever sees
the table. `AdAccountPolicy` admits platform staff only, and the client-facing
routes never touch it.

Three statuses are tracked separately because they fail differently:

| Column | Answers |
| --- | --- |
| `status` | Where the account sits in *our* lifecycle |
| `health_status` | What observation says about it |
| `billing_status` | Whether the provider will accept spend |

`isAllocatable()` requires all three, and `scopeAllocatable()` expresses the same
predicate in SQL — a test asserts the two agree.

**Health monitoring.** `AdAccountHealthService` applies two rules that matter.
A provider's silence is not a verdict: a transient failure moves a counter, and
only a run of them changes health. And null is not zero: a figure the provider
did not report leaves the stored value alone, because writing zero would report
a busy account as idle and hand it straight back out.

**Pools** group accounts of one provider and one currency — mixing either would
make the pool's own comparisons meaningless. `AllocationRules` is the only way
in or out of the stored rule document, so a malformed rule fails loudly instead
of quietly ceasing to apply. `PoolEligibilityService` returns *reasons*, not a
boolean: an operator looking at an empty pool needs to know which rule emptied
it. The engine that picks one account from the eligible set arrives with the
campaign work.

## 14. Campaigns

`app/Domains/Campaign/`. A campaign is built by a client, reviewed by the
platform, published to a provider, and reconciled against the ledger as it
spends. Four decisions carry most of the weight.

### Money is frozen at submission

The client chooses a budget as a decimal string; everything else — fees, tax,
the total — is derived server-side by the pricing engine and written onto the
campaign at submission, along with a snapshot of the plan it came from. A plan
that changes overnight does not change what the client agreed to, and the
reviewer approving a figure is approving one that cannot move underneath them.

Fees are added *on top of* the budget rather than taken out of it, so the amount
that reaches the provider is the amount the client asked to spend.

### Approval is where obligations are taken on

`ApproveCampaign` reserves the budget against the wallet **and** allocates an ad
account, in one transaction. Either both happen or neither: a campaign with
money held and no account would sit unpublishable with a client's balance
frozen, and one with an account but no hold would spend money nobody reserved.

**Lock order is wallet, then ad account**, everywhere. Phase 2 was already
bitten by a deadlock from inconsistent ordering; the rule is written down here
because the next path that touches both will have to follow it too.

### Allocation reads, then re-reads under a lock

`AllocateAdAccount` treats the eligibility query as a *shortlist*, never as a
decision. Each candidate is locked with `SELECT … FOR UPDATE`, **re-read under
that lock**, and re-checked before the commitment is written. Locking and then
trusting the earlier read would be the same race with extra ceremony.

Each campaign records its own `account_commitment` — its share of the account's
headroom. Without that column, releasing headroom as a campaign spends would
subtract the same amount again on every sync.

### Publishing is claim, call, settle

Every provider request is claimed in `campaign_publications` **before** the call
is made. That ordering is the whole point: a worker that dies after the provider
acted but before anything was recorded leaves evidence that the request may have
happened, so a retry reuses the same idempotency key instead of asking for a
second campaign.

Two indexes carry the guarantee, and neither depends on the application being
careful:

| Index | Prevents |
| --- | --- |
| `campaign_publications_unique_key` | One key, one attempt |
| `campaign_publications_one_success_per_operation` | One success per entity per operation |

Partial success is normal and is not undone. Three ad sets published and a
fourth failed means three stay published; the retry starts at the fourth.
Deleting them to "clean up" would delete things a provider has begun charging
for.

### Spend reconciliation

Providers report spend-to-date; the platform stores capture-to-date; each run
captures the difference. Adding up reported deltas would double-charge the first
time a sync ran twice and lose money the first time one was missed.

Fees follow spend — a client who uses half their budget owes fees on half — and
the fee due is recomputed from the cumulative figure each time rather than
accumulated, so rounding cannot drift over a month of hourly syncs. A provider
that does not report spend leaves the stored figure alone; null is not zero.

## 15. The Meta adapter

`app/Domains/Advertising/Providers/Meta/`. The first live provider. Everything
Meta-specific is here and nowhere else — the vocabulary translation, the field
names, the three-call ad creation, the fact that Meta measures money in an
account's own minor units.

### Idempotency without an idempotency key

**Meta's Marketing API has no idempotency-key header.** Sending the same
campaign creation twice creates two campaigns, each with its own budget, each
spending the client's money. The key the contract passes therefore cannot
simply be forwarded, and the adapter earns the guarantee a different way:

1. Every object it creates carries the platform's own reference in its name, as
   an `[ads360:<reference>]` suffix.
2. Before creating anything, it lists the parent's recent objects and looks for
   that reference. A match means a previous attempt succeeded, and the existing
   object comes back with `wasExisting: true` instead of a second one being made.

That is a real extra round trip on every creation, and a slightly noisier name
in Meta's own interface. Both are cheaper than charging someone twice. The
platform's publication ledger stops most duplicates; this check covers the one
it cannot see — a worker killed between Meta acting and the ledger being written.

### The transport

One client class carries three concerns that would otherwise be scattered:

- **Tokens as headers, never query strings.** Meta accepts `?access_token=` and
  documents it that way; query strings end up in access logs, proxy logs and
  exception traces.
- **One error vocabulary.** Meta's envelope is decoded and mapped once, so
  callers deal in `ProviderUnavailable` and never in raw codes.
- **Retries that know what they are retrying.** Only transport failures and
  rate limits are retried. A refusal returns immediately — retrying a policy
  decision is what §27 forbids, and it earns a rate limit besides.

The API version is pinned. Meta deprecates a version roughly every two years
and changes field shapes between them; an unpinned client starts failing on a
date nobody chose.

### What is deliberately not claimed

`supports(LeadForms)` returns false. Retrieving lead data needs its own
permission and its own handling of personal data; claiming the capability
before that exists would have callers offer clients something that does not
work (§87).

### Webhooks

`routes/webhooks.php`, registered outside the `web` group so these requests
never pick up session handling or CSRF — a provider has no session, and the
signature on the body is what authenticates it.

The endpoint verifies, records, queues and acknowledges, and does nothing else.
Meta retries anything it does not get a prompt 200 for, so real work in the
request would turn one slow update into a stream of duplicate deliveries.

A webhook is an assertion by an outside party arriving on a public URL. The
signature proves it came from Meta, not that it is the whole truth — so a
webhook never moves money. It makes the platform *look sooner*; the reconciler
asks Meta directly and compares against what it has already captured.

## 16. Money

`app/Support/Values/Money.php`. Integer minor units, currency travels with the
amount, and no floating point anywhere. Multiplication and division require an
explicit rounding mode because the caller owns that policy. `allocate()` and
`allocateEvenly()` distribute remainders so a split never loses or invents a
minor unit. Combining two currencies raises `CurrencyMismatch` — conversion is
never implicit, it goes through the exchange-rate engine so the rate used is
recorded with the transaction.

## 17. Conventions

**Database.** snake_case, plural tables, `_id` foreign keys. A ULID `public_id`
on every externally exposed entity; the auto-increment key stays internal.
Nothing relies on the identifier being unguessable — authorization is enforced
on every lookup regardless. Money is `numeric` or integer minor units, never a
float. Indexes follow real query patterns, typically
`(tenant_id, organization_id, status)`.

**Enums** for every controlled status, with `label()` for display.

**Timestamps** stored in UTC. Organizations carry a display timezone; provider
ad account timezones are stored separately, because aggregating metrics across
differing provider timezones is its own problem.

**Caching.** Every key holding tenant-specific data is namespaced by tenant:
`tenant:{id}:dashboard:{date}`. A bare `dashboard` key is a cross-tenant leak.

**Queues.** Named per spec §28 and grouped into four Horizon supervisors, so
critical and payment work never queues behind analytics or report generation.

**Soft deletes** on tenants, organizations, users. Never on ledger entries,
payments, audit logs or finalised invoices — those use reversal semantics.

## 18. Reserved domains

`Agency`, `Analytics`, `Notification`, `Support` exist as empty directories.
`Creative` holds the lean library the campaign engine needs; approval workflows
and versioning arrive with the creative module proper. The structure is declared up front so modules land
in the right place, but nothing is built until its phase.

The permission registry likewise already declares permissions for later modules.
The seeder writes them all and policies adopt them as each module lands, which
keeps the vocabulary stable instead of renaming permissions later.

## 19. Adding a module

1. Read the existing schema and identify dependencies.
2. Write the migration. Foreign keys, check constraints and appropriate
   precision — database constraints, not application validation alone.
3. Model, enums, policy, action.
4. Controller (thin), form request, Inertia page.
5. Tests: unit for calculations, feature for the workflow, and **tenant
   isolation for every new tenant-owned table**.
6. `composer check` and `npm run build`.
7. Confirm audit events exist for anything security- or money-related.

A module is not done because the interface works. It is done when the migration,
model, authorization, validation, business logic, error and loading states, audit
events, isolation tests and documentation are all in place.
