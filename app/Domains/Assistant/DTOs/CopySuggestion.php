<?php

declare(strict_types=1);

namespace App\Domains\Assistant\DTOs;

/**
 * Proposed ad copy (spec §46).
 */
final readonly class CopySuggestion
{
    /**
     * @param  list<string>  $headlines
     * @param  list<string>  $descriptions
     */
    public function __construct(
        public array $headlines,
        public array $descriptions,
        public string $primaryText,
        public ?string $callToAction = null,
        public string $language = 'en',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'headlines' => $this->headlines,
            'descriptions' => $this->descriptions,
            'primary_text' => $this->primaryText,
            'call_to_action' => $this->callToAction,
            'language' => $this->language,
        ];
    }
}
