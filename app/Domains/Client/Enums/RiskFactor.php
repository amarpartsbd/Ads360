<?php

declare(strict_types=1);

namespace App\Domains\Client\Enums;

/**
 * The things that push an organization's risk score up (spec §12).
 *
 * The specification's requirement that risk decisions be *explainable* is what
 * shapes this enum. Each factor carries its own ceiling, so a score can always
 * be read back as a list of reasons with a number beside each — and the sum of
 * the ceilings is a hundred, which is why no factor can quietly dominate and
 * why the score never needs clamping after the fact.
 *
 * There is no factor for anything a client cannot influence or correct. A score
 * that goes up because of who someone is rather than what their account has
 * done would be neither explainable nor defensible.
 */
enum RiskFactor: string
{
    case VerificationIncomplete = 'VERIFICATION_INCOMPLETE';
    case PaymentFailures = 'PAYMENT_FAILURES';
    case CampaignRejections = 'CAMPAIGN_REJECTIONS';
    case RefundFrequency = 'REFUND_FREQUENCY';
    case AbnormalSpending = 'ABNORMAL_SPENDING';
    case SuspiciousLogins = 'SUSPICIOUS_LOGINS';
    case NewAccount = 'NEW_ACCOUNT';
    case ComplianceFlag = 'COMPLIANCE_FLAG';

    public function label(): string
    {
        return match ($this) {
            self::VerificationIncomplete => 'Business verification incomplete',
            self::PaymentFailures => 'Failed or rejected payments',
            self::CampaignRejections => 'Campaigns rejected at review',
            self::RefundFrequency => 'Refunds issued',
            self::AbnormalSpending => 'Spending well above its own pattern',
            self::SuspiciousLogins => 'Failed sign-in attempts',
            self::NewAccount => 'Account opened recently',
            self::ComplianceFlag => 'Flagged by compliance',
        };
    }

    /**
     * The most this factor can contribute.
     *
     * Verification carries the largest weight because it is the one factor
     * that is entirely within the client's control and entirely within the
     * platform's knowledge: an unverified business is not a suspicion, it is a
     * fact. A manual compliance flag is second, because a person looked.
     *
     * Weights sum to 100.
     */
    public function ceiling(): int
    {
        return match ($this) {
            self::VerificationIncomplete => 25,
            self::ComplianceFlag => 20,
            self::PaymentFailures => 15,
            self::CampaignRejections => 12,
            self::AbnormalSpending => 10,
            self::RefundFrequency => 8,
            self::SuspiciousLogins => 6,
            self::NewAccount => 4,
        };
    }

    /**
     * What a client can do about it, when there is something.
     *
     * Null means the factor is not something a client acts on — it is a
     * platform observation, and telling them to "log in less suspiciously"
     * would be worse than saying nothing.
     */
    public function remedy(): ?string
    {
        return match ($this) {
            self::VerificationIncomplete => 'Complete business verification.',
            self::PaymentFailures => 'Use a payment method that settles, or contact support.',
            self::CampaignRejections => 'Review the provider policies the rejections cited.',
            default => null,
        };
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $a, self $b): int => $b->ceiling() <=> $a->ceiling());

        return $cases;
    }
}
