<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Campaign\Enums\CreativeType;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<Creative>
 */
class CreativeFactory extends Factory
{
    protected $model = Creative::class;

    /**
     * @return array<model-property<Creative>, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'tenant_id' => fn (array $attributes): int => Organization::query()
                ->withoutGlobalScopes()
                ->whereKey($attributes['organization_id'])
                ->value('tenant_id'),
            'name' => $this->faker->words(2, true).'.jpg',
            'type' => CreativeType::Image,
            'storage_path' => 'creatives/'.Str::ulid().'.jpg',
            'media_type' => 'image/jpeg',
            'byte_size' => 250_000,
            'width' => 1200,
            'height' => 1200,
            'checksum' => hash('sha256', (string) Str::ulid()),
            'status' => 'READY',
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
            'tenant_id' => $organization->tenant_id,
        ]);
    }

    /**
     * Writes real bytes to the creatives disk, so a test that publishes an ad
     * exercises the file being read rather than skipping over it.
     *
     * Use with `Storage::fake('creatives')`.
     */
    public function withStoredBytes(): static
    {
        return $this->afterCreating(function (Creative $creative): void {
            // A one-pixel PNG: genuinely a PNG, and small enough to be free.
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                true,
            );

            Storage::disk('creatives')->put($creative->storage_path, (string) $png);
        });
    }

    public function video(): static
    {
        return $this->state(fn (): array => [
            'type' => CreativeType::Video,
            'name' => $this->faker->words(2, true).'.mp4',
            'media_type' => 'video/mp4',
            'byte_size' => 12_000_000,
            'duration_seconds' => 30,
        ]);
    }
}
