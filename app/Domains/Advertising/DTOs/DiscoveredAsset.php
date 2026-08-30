<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

use App\Domains\Advertising\Enums\AssetType;

/**
 * One asset a provider says the connected account is authorised to use
 * (spec §15, §16).
 */
final readonly class DiscoveredAsset
{
    /**
     * @param  array<string, mixed>  $metadata  provider-specific detail, kept
     *                                          out of the core columns (spec §22)
     */
    public function __construct(
        public AssetType $type,
        public string $externalId,
        public string $name,
        public ?string $currency = null,
        public ?string $timezone = null,
        public ?string $status = null,
        public array $metadata = [],
    ) {}
}
