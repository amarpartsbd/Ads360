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

## 9. Money

`app/Support/Values/Money.php`. Integer minor units, currency travels with the
amount, and no floating point anywhere. Multiplication and division require an
explicit rounding mode because the caller owns that policy. `allocate()` and
`allocateEvenly()` distribute remainders so a split never loses or invents a
minor unit. Combining two currencies raises `CurrencyMismatch` — conversion is
never implicit, it goes through the exchange-rate engine so the rate used is
recorded with the transaction.

## 10. Conventions

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

## 11. Reserved domains

`Agency`, `Advertising`, `AdAccount`, `Creative`, `Wallet`, `Billing`,
`Payment`, `Analytics`, `Integration`, `Notification`, `Support` exist as empty
directories. The structure is declared up front so modules land
in the right place, but nothing is built until its phase.

The permission registry likewise already declares permissions for later modules.
The seeder writes them all and policies adopt them as each module lands, which
keeps the vocabulary stable instead of renaming permissions later.

## 12. Adding a module

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
