<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

/**
 * What a provider gave back after creating something (Rule 17).
 *
 * `wasExisting` is the important field. A provider that honours an idempotency
 * key answers a repeated request by returning the entity it already created
 * rather than making a second one, and it says which it did. The publishing
 * pipeline records the distinction instead of flattening both into "success",
 * because a repeat that quietly created a duplicate is exactly the failure the
 * key is meant to prevent.
 */
final readonly class PublishedEntity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalId,
        public ?string $status = null,
        public bool $wasExisting = false,
        public array $raw = [],
    ) {}
}
