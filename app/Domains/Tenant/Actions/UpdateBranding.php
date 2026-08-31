<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Tenant;
use App\Domains\Tenant\Values\Branding;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes how a tenant's copy of the platform looks (spec §43).
 *
 * The validation lives in the Branding value object, not here, so the same
 * rules apply whether a change arrives from a form, a seeder or a console
 * command. This action's job is the two things a value object cannot do:
 * enforce that the tenant is allowed to brand at all, and write the change down
 * where an auditor can find it.
 */
final class UpdateBranding
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $branding
     *
     * @throws ValidationException
     */
    public function handle(
        Tenant $tenant,
        array $branding,
        ?string $customDomain,
        ?User $actor = null,
    ): Tenant {
        if (! $tenant->canWhiteLabel()) {
            throw ValidationException::withMessages([
                'branding' => 'White-label branding is not enabled on this platform.',
            ]);
        }

        try {
            $value = Branding::fromArray($branding);
        } catch (\InvalidArgumentException $exception) {
            // Surfaced against the field a person can actually fix rather than
            // escaping as a 500 (spec §80).
            throw ValidationException::withMessages([
                'primary_color' => $exception->getMessage(),
            ]);
        }

        $domain = $this->hostname($customDomain);

        $this->assertDomainIsFree($tenant, $domain);

        $before = [
            'branding' => $tenant->branding,
            'custom_domain' => $tenant->custom_domain,
        ];

        DB::transaction(function () use ($tenant, $value, $domain): void {
            $tenant->branding = $value->toArray();
            $tenant->custom_domain = $domain;
            $tenant->save();
        });

        $this->audit->record(
            action: AuditAction::TenantBrandingChanged,
            resource: $tenant,
            before: $before,
            after: ['branding' => $tenant->branding, 'custom_domain' => $tenant->custom_domain],
            actor: $actor,
        );

        return $tenant;
    }

    /**
     * A hostname, as it will be compared against an incoming Host header.
     *
     * People paste what is in their browser's address bar, so a scheme, a path
     * and a trailing slash all arrive here regularly. Stripping them is kinder
     * than refusing, and a stored `https://Example.com/` would never match
     * anything while looking perfectly correct on the settings screen.
     *
     * @throws ValidationException
     */
    private function hostname(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = strtolower(trim($value));
        $trimmed = preg_replace('#^[a-z]+://#', '', $trimmed) ?? $trimmed;
        $trimmed = explode('/', $trimmed)[0];
        $trimmed = explode(':', $trimmed)[0];

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $trimmed) !== 1) {
            throw ValidationException::withMessages([
                'custom_domain' => 'That is not a domain name. Use something like ads.example.com.',
            ]);
        }

        return $trimmed;
    }

    /**
     * @throws ValidationException
     */
    private function assertDomainIsFree(Tenant $tenant, ?string $domain): void
    {
        if ($domain === null) {
            return;
        }

        $taken = Tenant::query()
            ->where('custom_domain', $domain)
            ->whereKeyNot($tenant->getKey())
            ->exists();

        if ($taken) {
            /*
             * Checked here as well as by the unique index. The index is the
             * guarantee; this is the message — a constraint violation reaching
             * a customer as a 500 would tell them nothing about what to change.
             */
            throw ValidationException::withMessages([
                'custom_domain' => 'That domain is already in use.',
            ]);
        }
    }
}
