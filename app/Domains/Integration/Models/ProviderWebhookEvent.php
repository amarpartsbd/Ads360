<?php

declare(strict_types=1);

namespace App\Domains\Integration\Models;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Integration\Enums\WebhookStatus;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One delivery from a provider (spec §52).
 *
 * Not tenant-scoped: a webhook arrives before anyone knows which client it
 * concerns, and deciding that is the processing step's job. Nothing serves
 * these rows to a client.
 *
 * @property Provider $provider
 * @property WebhookStatus $status
 */
class ProviderWebhookEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'provider',
        'object_type',
        'payload_digest',
        'status',
        'payload',
        'attempts',
        'last_error',
        'received_at',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => WebhookStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'Webhook deliveries cannot be deleted. They are the record of what an '
                .'outside party asserted about a client\'s account.'
            );
        });
    }

    /** A digest of the exact bytes received, which is what dedupes a redelivery. */
    public static function digest(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'provider' => $this->provider->value,
            'object_type' => $this->object_type,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'received_at' => $this->received_at?->toIso8601String(),
        ];
    }
}
