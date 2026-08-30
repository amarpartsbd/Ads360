<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Integration\Enums\AssetStatus;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Integration\Models\ProviderConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ProviderAsset>
 */
class ProviderAssetFactory extends Factory
{
    protected $model = ProviderAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_connection_id' => ProviderConnection::factory(),
            'provider' => Provider::Meta,
            'type' => AssetType::MetaAdAccount,
            'external_id' => (string) $this->faker->numerify('act_############'),
            'name' => $this->faker->company().' Ad Account',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'provider_status' => 'ACTIVE',
            'status' => AssetStatus::Available,
            'last_seen_at' => Carbon::now(),
        ];
    }

    /**
     * Ties the asset to a connection, inheriting the tenant, organization and
     * provider from it rather than letting the three drift apart.
     */
    public function forConnection(ProviderConnection $connection): static
    {
        return $this->state(fn (): array => [
            'provider_connection_id' => $connection->getKey(),
            'organization_id' => $connection->organization_id,
            'tenant_id' => $connection->tenant_id,
            'provider' => $connection->provider,
        ]);
    }

    public function ofType(AssetType $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'provider' => $type->provider(),
        ]);
    }

    public function permissionLost(): static
    {
        return $this->state(fn (): array => [
            'status' => AssetStatus::PermissionLost,
            'unavailable_since' => Carbon::now(),
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (): array => [
            'status' => AssetStatus::Unavailable,
            'unavailable_since' => Carbon::now(),
        ]);
    }
}
