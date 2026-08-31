<?php

declare(strict_types=1);

namespace App\Domains\Assistant\DTOs;

use App\Support\Values\Money;

/**
 * What a client said they want (spec §45).
 *
 * The brief itself is never stored — only a digest of it. A client describing
 * their business to an assistant will mention unannounced products, margins and
 * competitors, and none of that belongs in a table that every screen listing
 * recommendations reads from (§53, §54).
 */
final readonly class CampaignBrief
{
    public function __construct(
        public string $description,
        public Money $budget,
        public string $language = 'en',
        public ?string $country = null,
        public ?string $destinationUrl = null,
    ) {}

    /**
     * A stable fingerprint of the brief, for the provenance record.
     *
     * Lets two identical briefs be recognised as the same question without the
     * question itself being kept.
     */
    public function digest(): string
    {
        return hash('sha256', implode('|', [
            trim($this->description),
            $this->budget->minorUnits,
            $this->budget->currency->code,
            $this->language,
            $this->country ?? '',
        ]));
    }
}
