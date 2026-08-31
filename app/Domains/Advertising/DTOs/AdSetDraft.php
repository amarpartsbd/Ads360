<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Values\Targeting;
use DateTimeImmutable;

/**
 * An ad set as the platform describes it (spec §21).
 */
final readonly class AdSetDraft
{
    public function __construct(
        public string $reference,
        public string $externalCampaignId,
        public string $name,
        public Targeting $targeting,
        public BidStrategy $bidStrategy,
        public ?int $bidAmountMinor = null,
        public ?int $budgetMinor = null,
        public ?string $optimizationGoal = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
    ) {}
}
