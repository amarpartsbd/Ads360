<?php

declare(strict_types=1);

namespace App\Domains\Client\Services;

use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Client\DTOs\RiskAssessment;
use App\Domains\Client\DTOs\RiskContribution;
use App\Domains\Client\Enums\RiskFactor;
use App\Domains\Client\Enums\RiskLevel;
use App\Domains\Client\Models\OrganizationRiskProfile;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Models\Payment;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scores an organization's risk, and says why (spec §12).
 *
 * Three properties this service is built around, in order of importance.
 *
 * **Deterministic.** The same account with the same history produces the same
 * score every time. There is no model, no randomness and no learned weight
 * anywhere in it — §12 requires risk decisions to be explainable, and a number
 * nobody can reproduce cannot be explained, appealed or corrected.
 *
 * **Explainable.** Every point is attributed to a named factor with a sentence
 * a person can read. `assess()` returns the reasons, not just the total, and
 * the reasons are what gets stored.
 *
 * **Bounded.** Each factor has a ceiling and the ceilings sum to a hundred, so
 * the score is a percentage by construction rather than by clamping. No single
 * factor can carry an account to Critical on its own except a compliance flag
 * combined with something else, which is a person's judgement plus evidence.
 *
 * ## What it deliberately does not do
 *
 * It does not act. `assess()` computes and `record()` stores; neither suspends
 * an account, freezes a wallet or stops a campaign. The one automatic
 * consequence of a high score anywhere in this platform is that financial
 * actions on the organization need a second approver — which adds a person
 * rather than removing one. §12 is explicit: financial access is never
 * withdrawn without a deterministic rule *and* a human decision, and a scoring
 * mistake should cost someone a minute rather than costing a client their
 * advertising.
 */
final class RiskAssessor
{
    /**
     * How far back the behavioural factors look.
     *
     * Ninety days rather than all time: an account that had three failed
     * payments in its first month two years ago has since demonstrated the
     * opposite, and a score that never forgets is a score nobody can improve.
     */
    private const WINDOW_DAYS = 90;

    /**
     * Score an organization without writing anything down.
     *
     * @param  OrganizationRiskProfile|null  $existing  carries the manual flag,
     *                                                  which survives reassessment
     */
    public function assess(Organization $organization, ?OrganizationRiskProfile $existing = null): RiskAssessment
    {
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);

        $contributions = array_values(array_filter([
            $this->verification($organization),
            $this->complianceFlag($existing),
            $this->paymentFailures($organization, $since),
            $this->campaignRejections($organization, $since),
            $this->abnormalSpending($organization),
            $this->refunds($organization, $since),
            $this->suspiciousLogins($organization, $since),
            $this->accountAge($organization),
        ]));

        // Ordered heaviest first, because that is the order someone reading
        // the queue needs them in.
        usort(
            $contributions,
            static fn (RiskContribution $a, RiskContribution $b): int => $b->points <=> $a->points,
        );

        $score = array_sum(array_map(
            static fn (RiskContribution $contribution): int => $contribution->points,
            $contributions,
        ));

        /*
         * The ceilings sum to a hundred, so this cannot exceed it. Bounded
         * anyway: a future factor added without adjusting the others would
         * otherwise produce a score the database rejects, and failing a
         * scheduled sweep is a worse outcome than a capped number.
         */
        $score = max(0, min(100, $score));

        return new RiskAssessment(
            score: $score,
            level: RiskLevel::forScore($score),
            contributions: $contributions,
        );
    }

    /**
     * Assess and store, returning the profile.
     *
     * The manual flag and the review record are read from the existing profile
     * and carried forward: a compliance officer who flagged an account must not
     * find their flag gone an hour later because a scheduled job ran.
     */
    public function record(Organization $organization): OrganizationRiskProfile
    {
        return DB::transaction(function () use ($organization): OrganizationRiskProfile {
            /*
             * Found and locked rather than firstOrNew: nothing on this model
             * is fillable, so the organization is stamped below rather than
             * mass assigned. A concurrent first assessment is caught by the
             * unique index on organization_id — the loser rolls back and the
             * next sweep picks it up, which is cheaper than serialising every
             * assessment against a table lock.
             */
            $profile = OrganizationRiskProfile::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first() ?? new OrganizationRiskProfile;

            $assessment = $this->assess($organization, $profile->exists ? $profile : null);

            $previousLevel = $profile->exists ? $profile->level : null;

            $profile->tenant_id = $organization->tenant_id;
            $profile->organization_id = $organization->getKey();

            $profile->forceFill([
                'score' => $assessment->score,
                'level' => $assessment->level,
                'factors' => $assessment->toArray(),
                'assessed_at' => Carbon::now(),
            ]);

            /*
             * A review is an answer to a particular assessment. When the level
             * changes the answer no longer applies, so the account returns to
             * the queue rather than staying cleared on the strength of a
             * decision about a different set of facts.
             */
            if ($previousLevel !== null && $previousLevel !== $assessment->level) {
                $profile->forceFill(['reviewed_at' => null, 'reviewed_by' => null]);
            }

            $profile->save();

            return $profile;
        });
    }

    // ------------------------------------------------------------------
    // Factors
    // ------------------------------------------------------------------

    /**
     * The largest weight, because it is the one fact rather than an inference:
     * an unverified business is unverified.
     */
    private function verification(Organization $organization): ?RiskContribution
    {
        $ceiling = RiskFactor::VerificationIncomplete->ceiling();

        /** @var VerificationProfile|null $profile */
        $profile = VerificationProfile::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->first();

        $status = $profile?->status;

        [$points, $detail] = match ($status) {
            VerificationStatus::Verified => [0, ''],
            VerificationStatus::UnderReview, VerificationStatus::Pending => [
                intdiv($ceiling, 3),
                'Business verification is with our compliance team.',
            ],
            VerificationStatus::RequiresInformation => [
                intdiv($ceiling * 2, 3),
                'Business verification is waiting on more information from the client.',
            ],
            VerificationStatus::Suspended, VerificationStatus::Rejected => [
                $ceiling,
                'Business verification was refused or has been suspended.',
            ],
            default => [$ceiling, 'Business verification has not been submitted.'],
        };

        return $points === 0
            ? null
            : new RiskContribution(RiskFactor::VerificationIncomplete, $points, $detail);
    }

    /**
     * A person looked and said something is wrong. Weighted second only to
     * verification, and never computed away by the factors around it.
     */
    private function complianceFlag(?OrganizationRiskProfile $existing): ?RiskContribution
    {
        if ($existing === null || ! $existing->manual_flag) {
            return null;
        }

        $reason = trim((string) $existing->manual_flag_reason);

        return new RiskContribution(
            RiskFactor::ComplianceFlag,
            RiskFactor::ComplianceFlag->ceiling(),
            $reason === ''
                ? 'Flagged by compliance.'
                : 'Flagged by compliance: '.$reason,
        );
    }

    private function paymentFailures(Organization $organization, Carbon $since): ?RiskContribution
    {
        $failed = Payment::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->whereIn('status', [PaymentStatus::Failed->value, PaymentStatus::Rejected->value])
            ->where('created_at', '>=', $since)
            ->count();

        if ($failed === 0) {
            return null;
        }

        /*
         * Five points a failure. One is a wrong card number; three is a
         * pattern worth a second signature, and that is where the ceiling puts
         * it.
         */
        $points = min(RiskFactor::PaymentFailures->ceiling(), $failed * 5);

        return new RiskContribution(
            RiskFactor::PaymentFailures,
            $points,
            $failed === 1
                ? 'One payment failed or was rejected in the last '.self::WINDOW_DAYS.' days.'
                : "{$failed} payments failed or were rejected in the last ".self::WINDOW_DAYS.' days.',
        );
    }

    /**
     * Rejections are a provider's or a reviewer's judgement about the
     * client's advertising, which is exactly the sort of evidence §12 asks to
     * be weighed.
     */
    private function campaignRejections(Organization $organization, Carbon $since): ?RiskContribution
    {
        $rejected = Campaign::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('status', CampaignStatus::Rejected->value)
            ->where('updated_at', '>=', $since)
            ->count();

        if ($rejected === 0) {
            return null;
        }

        $points = min(RiskFactor::CampaignRejections->ceiling(), $rejected * 4);

        return new RiskContribution(
            RiskFactor::CampaignRejections,
            $points,
            $rejected === 1
                ? 'One campaign was rejected at review in the last '.self::WINDOW_DAYS.' days.'
                : "{$rejected} campaigns were rejected at review in the last ".self::WINDOW_DAYS.' days.',
        );
    }

    /**
     * Spending far above an account's own established pattern.
     *
     * Measured against itself rather than against other clients: a large
     * advertiser is not risky for being large, and comparing accounts would
     * score every big spender as suspicious for doing exactly what they signed
     * up to do.
     */
    private function abnormalSpending(Organization $organization): ?RiskContribution
    {
        $now = Carbon::now();

        $recent = $this->spendBetween($organization, $now->copy()->subDays(7), $now);

        if ($recent <= 0) {
            return null;
        }

        // The eleven weeks before the last one, as a weekly average.
        $baselineTotal = $this->spendBetween(
            $organization,
            $now->copy()->subDays(90),
            $now->copy()->subDays(7),
        );

        $baselineWeeks = 83 / 7;
        $baseline = (int) round($baselineTotal / $baselineWeeks);

        /*
         * No history at all is not abnormal — it is a new advertiser, which
         * the account-age factor already covers. Scoring it here would charge
         * the same fact twice.
         */
        if ($baseline <= 0) {
            return null;
        }

        if ($recent < $baseline * 4) {
            return null;
        }

        $multiple = (int) floor($recent / $baseline);

        return new RiskContribution(
            RiskFactor::AbnormalSpending,
            RiskFactor::AbnormalSpending->ceiling(),
            "Spending in the last week is about {$multiple} times this account's own weekly average.",
        );
    }

    private function refunds(Organization $organization, Carbon $since): ?RiskContribution
    {
        $refunds = LedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('type', LedgerEntryType::Refund->value)
            ->where('created_at', '>=', $since)
            ->count();

        if ($refunds === 0) {
            return null;
        }

        $points = min(RiskFactor::RefundFrequency->ceiling(), $refunds * 4);

        return new RiskContribution(
            RiskFactor::RefundFrequency,
            $points,
            $refunds === 1
                ? 'One refund was issued in the last '.self::WINDOW_DAYS.' days.'
                : "{$refunds} refunds were issued in the last ".self::WINDOW_DAYS.' days.',
        );
    }

    /**
     * Failed sign-ins against this organization's own people.
     *
     * The lightest behavioural factor on purpose. A forgotten password is the
     * commonest thing on any platform, and weighting it heavily would fill the
     * compliance queue with people who changed their laptop.
     */
    private function suspiciousLogins(Organization $organization, Carbon $since): ?RiskContribution
    {
        $failures = DB::table('login_histories')
            ->join('organization_user', 'organization_user.user_id', '=', 'login_histories.user_id')
            ->where('organization_user.organization_id', $organization->getKey())
            ->where('login_histories.successful', false)
            ->where('login_histories.created_at', '>=', $since)
            ->count();

        if ($failures < 5) {
            return null;
        }

        $points = min(RiskFactor::SuspiciousLogins->ceiling(), intdiv($failures, 5) * 2);

        if ($points === 0) {
            return null;
        }

        return new RiskContribution(
            RiskFactor::SuspiciousLogins,
            $points,
            "{$failures} sign-in attempts failed for this account's people in the last "
            .self::WINDOW_DAYS.' days.',
        );
    }

    /**
     * A new account has no history to be judged on, which is itself a reason
     * to look more closely. The smallest weight, and it expires on its own.
     */
    private function accountAge(Organization $organization): ?RiskContribution
    {
        $createdAt = $organization->created_at;

        if ($createdAt === null || $createdAt->diffInDays(Carbon::now()) >= 30) {
            return null;
        }

        $days = (int) $createdAt->diffInDays(Carbon::now());

        return new RiskContribution(
            RiskFactor::NewAccount,
            RiskFactor::NewAccount->ceiling(),
            $days === 0
                ? 'The account was opened today.'
                : "The account was opened {$days} days ago.",
        );
    }

    /**
     * Captured spend between two moments, in the wallet's minor units.
     *
     * The debit column: spend leaves the wallet, and the ledger keeps debits
     * and credits apart rather than signing one amount.
     */
    private function spendBetween(Organization $organization, Carbon $from, Carbon $to): int
    {
        return (int) LedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('type', LedgerEntryType::CampaignSpend->value)
            ->whereBetween('created_at', [$from, $to])
            ->sum('debit');
    }
}
