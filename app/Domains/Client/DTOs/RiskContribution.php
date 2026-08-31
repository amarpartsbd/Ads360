<?php

declare(strict_types=1);

namespace App\Domains\Client\DTOs;

use App\Domains\Client\Enums\RiskFactor;

/**
 * One reason a score is what it is (spec §12).
 *
 * `detail` is the sentence a compliance officer reads — "3 payments failed in
 * the last 90 days", not "payment_failures: 3". A stored number nobody can
 * interpret is the thing §12's explainability requirement exists to prevent.
 */
final readonly class RiskContribution
{
    public function __construct(
        public RiskFactor $factor,
        public int $points,
        public string $detail,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $factor = RiskFactor::tryFrom((string) ($row['factor'] ?? ''));

        if ($factor === null) {
            // A factor this version does not recognise is dropped rather than
            // guessed at: an unknown reason cannot be explained to anyone.
            return null;
        }

        return new self(
            factor: $factor,
            points: (int) ($row['points'] ?? 0),
            detail: (string) ($row['detail'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'factor' => $this->factor->value,
            'points' => $this->points,
            'detail' => $this->detail,
        ];
    }
}
