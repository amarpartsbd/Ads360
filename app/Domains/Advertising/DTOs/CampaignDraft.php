<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use DateTimeImmutable;

/**
 * A campaign as the platform describes it, for an adapter to translate
 * (spec §21, §26).
 *
 * Deliberately not the Eloquent model: an adapter should not be able to change
 * a campaign, and building the request from a flat description keeps the
 * translation to a provider's dialect in one visible place.
 */
final readonly class CampaignDraft
{
    public function __construct(
        public string $reference,
        public string $name,
        public CampaignObjective $objective,
        public BudgetType $budgetType,
        public int $budgetMinor,
        public string $currency,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
    ) {}
}
