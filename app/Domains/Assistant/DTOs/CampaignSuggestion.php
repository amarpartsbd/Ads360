<?php

declare(strict_types=1);

namespace App\Domains\Assistant\DTOs;

use App\Domains\Campaign\Enums\CampaignObjective;

/**
 * A proposed campaign (spec §45).
 *
 * Deliberately not a CampaignDraft. A draft is what the publishing pipeline
 * sends to a provider; this is a proposal a person has not yet agreed to, and
 * giving the two the same type would make it one mistaken call away from being
 * published.
 */
final readonly class CampaignSuggestion
{
    /**
     * @param  list<string>  $countries
     * @param  list<string>  $headlines
     * @param  list<string>  $descriptions
     * @param  list<string>  $rationale  why it suggested this, in plain words
     */
    public function __construct(
        public string $name,
        public CampaignObjective $objective,
        public array $countries,
        public int $minimumAge,
        public int $maximumAge,
        public array $headlines,
        public array $descriptions,
        public array $rationale = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'objective' => $this->objective->value,
            'countries' => $this->countries,
            'minimum_age' => $this->minimumAge,
            'maximum_age' => $this->maximumAge,
            'headlines' => $this->headlines,
            'descriptions' => $this->descriptions,
            'rationale' => $this->rationale,
        ];
    }
}
