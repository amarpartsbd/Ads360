<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Enums\ActorType;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes audit records (spec §51).
 *
 * Actor, tenant, organization, address and request id are taken from the
 * current request rather than from the caller, so a call site cannot claim to
 * be someone else. Payloads pass through the redactor before they are stored.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly SecretRedactor $redactor,
        private readonly TenantContext $context,
        private readonly Guard $guard,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    public function record(
        AuditAction $action,
        ?Model $resource = null,
        ?array $before = null,
        ?array $after = null,
        array $context = [],
        ?Organization $organization = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->currentUser();
        $organization ??= $this->context->organization();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor !== null ? ActorType::User : ActorType::System,
            'actor_label' => $actor?->email,
            // Prefer the actor's own tenant so an action is always attributed to
            // the tenant it affected, even outside a bound request context.
            'tenant_id' => $organization?->tenant_id ?? $this->context->tenantId() ?? $actor?->tenant_id,
            'organization_id' => $organization?->getKey(),
            'action' => $action->value,
            'resource_type' => $resource !== null ? $resource::class : null,
            'resource_id' => $resource?->getKey() !== null ? (string) $resource->getKey() : null,
            'before_data' => $before !== null ? $this->redactor->redact($before) : null,
            'after_data' => $after !== null ? $this->redactor->redact($after) : null,
            'context' => $this->redactor->redact($context),
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 1024) ?: null,
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }

    /**
     * Capture a model's attributes so they can be compared after it is saved.
     *
     * This must be called *before* saving: Eloquent syncs a model's originals
     * on save, so once the write has happened the previous values are gone.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Model $resource): array
    {
        return $resource->getOriginal();
    }

    /**
     * Record a change to a model, diffing only the attributes that actually
     * moved so the record stays readable.
     *
     * Pass the array returned by snapshot() taken before the save.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $context
     */
    public function recordChange(
        AuditAction $action,
        Model $resource,
        array $before,
        array $context = [],
        ?Organization $organization = null,
    ): AuditLog {
        $changes = $resource->getChanges();
        $original = [];

        foreach (array_keys($changes) as $attribute) {
            $original[$attribute] = $before[$attribute] ?? null;
        }

        return $this->record(
            action: $action,
            resource: $resource,
            before: $original,
            after: $changes,
            context: $context,
            organization: $organization,
        );
    }

    /**
     * Record an event that has no authenticated actor — a scheduled job, a
     * queue worker or an inbound provider webhook.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordSystemEvent(
        AuditAction $action,
        ActorType $actorType = ActorType::System,
        ?Model $resource = null,
        array $context = [],
        ?string $label = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_id' => null,
            'actor_type' => $actorType,
            'actor_label' => $label,
            'tenant_id' => $this->context->tenantId(),
            'organization_id' => $this->context->organizationId(),
            'action' => $action->value,
            'resource_type' => $resource !== null ? $resource::class : null,
            'resource_id' => $resource?->getKey() !== null ? (string) $resource->getKey() : null,
            'context' => $this->redactor->redact($context),
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }

    private function currentUser(): ?User
    {
        $user = $this->guard->user();

        return $user instanceof User ? $user : null;
    }
}
