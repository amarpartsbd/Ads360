<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProviderConnection>
 */
class ProviderConnectionFactory extends Factory
{
    protected $model = ProviderConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The tenant comes from the organization rather than from ambient
            // context: a factory used outside a request has no context to read.
            'organization_id' => Organization::factory(),
            'tenant_id' => fn (array $attributes): int => Organization::query()
                ->withoutGlobalScopes()
                ->whereKey($attributes['organization_id'])
                ->value('tenant_id'),
            'provider' => Provider::Meta,
            'external_user_id' => (string) $this->faker->numerify('##############'),
            'account_name' => $this->faker->company(),
            // A fixture token, and obviously one: nothing here resembles a
            // real credential, so a leak in a test dump reveals nothing.
            'access_token_encrypted' => 'test-access-'.Str::random(24),
            'refresh_token_encrypted' => 'test-refresh-'.Str::random(24),
            'expires_at' => Carbon::now()->addDays(45),
            'scopes' => ['ads_management', 'ads_read'],
            'status' => ConnectionStatus::Connected,
            'last_verified_at' => Carbon::now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
            'tenant_id' => $organization->tenant_id,
        ]);
    }

    public function provider(Provider $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (): array => [
            'status' => ConnectionStatus::Expiring,
            'expires_at' => Carbon::now()->addHours(6),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => ConnectionStatus::Expired,
            'expires_at' => Carbon::now()->subDay(),
        ]);
    }

    /** A revoked connection holds no credentials, exactly as in production. */
    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => ConnectionStatus::Revoked,
            'status_detail' => 'Disconnected in test fixture.',
            'revoked_at' => Carbon::now(),
            'access_token_encrypted' => null,
            'refresh_token_encrypted' => null,
        ]);
    }

    public function withoutRefreshToken(): static
    {
        return $this->state(fn (): array => ['refresh_token_encrypted' => null]);
    }
}
