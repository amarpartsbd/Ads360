<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

/**
 * What a provider reports about a running campaign (spec §20, §78).
 *
 * Every field is nullable because §87 forbids assuming a provider exposes any
 * particular figure. A null means "not reported" and must never be rendered or
 * stored as zero — a campaign reported as having spent nothing is treated very
 * differently from one that did not answer.
 */
final readonly class CampaignInsights
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalCampaignId,
        public ?int $spendMinor = null,
        public ?string $currency = null,
        public ?int $impressions = null,
        public ?int $clicks = null,
        public ?int $conversions = null,
        public ?string $status = null,
        public array $raw = [],
    ) {}

    public function reportsSpend(): bool
    {
        return $this->spendMinor !== null;
    }
}
