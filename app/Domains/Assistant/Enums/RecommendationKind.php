<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Enums;

/**
 * The kinds of recommendation the platform can produce (spec §45, §46, §47).
 *
 * Two of these come from an assistant and one is arithmetic. That distinction
 * is deliberate and is recorded on every row: a client reading "shift budget
 * towards campaign B" is entitled to know whether that came from their own
 * numbers or from a language model, and the answer changes how much weight it
 * deserves.
 */
enum RecommendationKind: string
{
    /** A whole campaign proposed from a brief (spec §45). */
    case Campaign = 'CAMPAIGN';

    /** Headlines, body copy and a call to action (spec §46). */
    case Copy = 'COPY';

    /** An observation about money already spent (spec §47). */
    case Insight = 'INSIGHT';

    public function label(): string
    {
        return match ($this) {
            self::Campaign => 'Campaign suggestion',
            self::Copy => 'Ad copy',
            self::Insight => 'Performance insight',
        };
    }

    /**
     * Whether accepting this could lead to money being spent.
     *
     * Everything that can is gated: §45 is explicit that AI output is a
     * recommendation and that a person approves before financial execution.
     * Accepting one of these never spends anything by itself — it produces a
     * draft, which then goes through exactly the same review and approval as
     * one a person typed.
     */
    public function reachesMoney(): bool
    {
        return $this !== self::Insight;
    }
}
