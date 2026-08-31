<?php

declare(strict_types=1);

namespace App\Domains\Client\Enums;

/**
 * How risky an organization looks right now (spec §12).
 *
 * The bands are the specification's own. They are deliberately coarse: a score
 * of 44 and a score of 47 mean the same thing to whoever reads the queue, and
 * pretending otherwise would invite decisions the arithmetic cannot support.
 */
enum RiskLevel: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Critical = 'CRITICAL';

    public static function forScore(int $score): self
    {
        return match (true) {
            $score <= 30 => self::Low,
            $score <= 60 => self::Medium,
            $score <= 80 => self::High,
            default => self::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    /**
     * What a person should do about it. Written for the compliance queue, not
     * for a log.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Low => 'Nothing to do. Normal monitoring.',
            self::Medium => 'Worth a look when convenient. No action required.',
            self::High => 'Review this account. Financial actions on it now need a second approver.',
            self::Critical => 'Review this account now. Financial actions need a second approver, '
                .'and suspension is a decision for a person to make.',
        };
    }

    /**
     * Whether an organization at this level should have its financial actions
     * put through maker-checker (spec §12, §25).
     *
     * This is the *only* automatic consequence of a risk score anywhere in the
     * platform, and it deliberately adds a person rather than removing one.
     * Nothing here suspends an account, freezes a wallet or stops a campaign:
     * §12 is explicit that financial access is never withdrawn without a human
     * decision, and a scoring mistake that merely asks for a second signature
     * costs someone a minute rather than costing a client their advertising.
     */
    public function requiresSecondApprover(): bool
    {
        return in_array($this, [self::High, self::Critical], true);
    }

    /** Whether this level belongs in the compliance review queue. */
    public function needsReview(): bool
    {
        return in_array($this, [self::High, self::Critical], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
