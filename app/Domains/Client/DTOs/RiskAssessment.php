<?php

declare(strict_types=1);

namespace App\Domains\Client\DTOs;

use App\Domains\Client\Enums\RiskLevel;

/**
 * What an assessment concluded, before anything is written down (spec §12).
 *
 * Separate from the model so the scoring can be exercised — and argued with —
 * without touching the database, and so a caller can show someone what a
 * reassessment *would* say before it is saved.
 */
final readonly class RiskAssessment
{
    /**
     * @param  list<RiskContribution>  $contributions
     */
    public function __construct(
        public int $score,
        public RiskLevel $level,
        public array $contributions,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (RiskContribution $contribution): array => $contribution->toArray(),
            $this->contributions,
        );
    }

    /**
     * The score read back as a sentence. Used in audit entries and
     * notifications, where a bare number would tell the reader nothing.
     */
    public function explain(): string
    {
        if ($this->contributions === []) {
            return 'Nothing on this account raises its risk.';
        }

        return implode(' ', array_map(
            static fn (RiskContribution $contribution): string => $contribution->detail,
            $this->contributions,
        ));
    }
}
