<?php

declare(strict_types=1);

namespace App\Domains\Assistant\DTOs;

/**
 * A request for ad copy (spec §46).
 */
final readonly class CopyRequest
{
    public function __construct(
        public string $product,
        public string $audience,
        public string $language = 'en',
        public ?string $tone = null,
        public int $variants = 3,
    ) {}

    public function digest(): string
    {
        return hash('sha256', implode('|', [
            trim($this->product),
            trim($this->audience),
            $this->language,
            $this->tone ?? '',
        ]));
    }
}
