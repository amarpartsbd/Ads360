<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Services;

use App\Domains\Campaign\Models\Ad;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Integration\Enums\AssetStatus;
use App\Domains\Wallet\Models\Wallet;
use App\Support\Values\Money;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Everything that has to be true before a campaign may be submitted
 * (spec §21, §26).
 *
 * Every reason is collected rather than thrown at the first failure. Someone
 * fixing a campaign should see the whole list at once instead of discovering a
 * new problem on each attempt — and the reasons are written in plain language,
 * because they go straight to the client (spec §72, §80).
 *
 * This runs before review, not at publish time. By publish time the budget is
 * already held, and telling a client their campaign cannot run *after* taking
 * the money is the failure this class exists to prevent.
 */
final class CampaignReadiness
{
    public function __construct(private readonly CampaignCosting $costing) {}

    /**
     * @return list<string> Empty when the campaign is ready.
     */
    public function reasonsNotReady(Campaign $campaign): array
    {
        $reasons = [];

        $campaign->loadMissing(['adSets.ads.creative', 'adSets.ads.identity']);

        $reasons = [
            ...$reasons,
            ...$this->scheduleReasons($campaign),
            ...$this->structureReasons($campaign),
            ...$this->complianceReasons($campaign),
            ...$this->fundingReasons($campaign),
        ];

        return array_values(array_unique($reasons));
    }

    public function isReady(Campaign $campaign): bool
    {
        return $this->reasonsNotReady($campaign) === [];
    }

    /**
     * @return list<string>
     */
    private function scheduleReasons(Campaign $campaign): array
    {
        $reasons = [];

        if ($campaign->starts_at === null) {
            $reasons[] = 'Choose a start date.';
        } elseif ($campaign->starts_at->isPast() && $campaign->status->isEditable()) {
            // A start date in the past would have the provider begin
            // immediately, which is rarely what someone who set a date meant.
            $reasons[] = 'The start date has already passed. Choose a new one.';
        }

        if ($campaign->budget_type->requiresEndDate() && $campaign->ends_at === null) {
            $reasons[] = 'A daily budget needs an end date, so the total commitment is known.';
        }

        if ($campaign->ends_at !== null
            && $campaign->starts_at !== null
            && $campaign->ends_at->lessThanOrEqualTo($campaign->starts_at)) {
            $reasons[] = 'The end date must be after the start date.';
        }

        if (! $campaign->objective->isSupportedBy($campaign->provider)) {
            $reasons[] = 'That objective is not available on this advertising platform.';
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function structureReasons(Campaign $campaign): array
    {
        $reasons = [];

        if ($campaign->adSets->isEmpty()) {
            return ['Add at least one audience.'];
        }

        foreach ($campaign->adSets as $adSet) {
            $reasons = [...$reasons, ...$this->adSetReasons($campaign, $adSet)];
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function adSetReasons(Campaign $campaign, AdSet $adSet): array
    {
        $reasons = [];

        try {
            $targeting = $adSet->targeting();
        } catch (InvalidArgumentException $exception) {
            // A stored audience nobody can parse must stop the campaign, not
            // be quietly published as "everyone".
            return ["The audience “{$adSet->name}” has settings we cannot read: {$exception->getMessage()}"];
        }

        if (! $targeting->hasGeography()) {
            $reasons[] = "Choose at least one location for the audience “{$adSet->name}”.";
        }

        if ($adSet->bid_strategy->requiresAmount() && $adSet->bid_amount === null) {
            $reasons[] = "The audience “{$adSet->name}” uses a bid cap, so it needs an amount.";
        }

        if ($adSet->ads->isEmpty()) {
            $reasons[] = "Add at least one ad to the audience “{$adSet->name}”.";

            return $reasons;
        }

        foreach ($adSet->ads as $ad) {
            $reasons = [...$reasons, ...$this->adReasons($campaign, $ad)];
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function adReasons(Campaign $campaign, Ad $ad): array
    {
        $reasons = [];

        if ($ad->creative_id === null) {
            $reasons[] = "The ad “{$ad->name}” needs an image or video.";
        }

        if ($ad->identity_asset_id === null) {
            $reasons[] = "Choose the page or account the ad “{$ad->name}” should appear as.";
        } elseif ($ad->identity !== null) {
            // The grant behind the identity can lapse between building a
            // campaign and submitting it.
            if ($ad->identity->status !== AssetStatus::Available) {
                $reasons[] = "The page or account for “{$ad->name}” is no longer available. "
                    .'Reconnect it from your advertising assets.';
            }

            if (! $ad->identity->canBeAdIdentity()) {
                $reasons[] = "The asset chosen for “{$ad->name}” cannot be used as an ad identity.";
            }
        }

        if ($campaign->objective->requiresDestination() && trim((string) $ad->destination_url) === '') {
            $reasons[] = "The ad “{$ad->name}” needs a destination link for this objective.";
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function complianceReasons(Campaign $campaign): array
    {
        $profile = VerificationProfile::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if ($profile?->status !== VerificationStatus::Verified) {
            return ['Your business verification must be approved before campaigns can run.'];
        }

        return [];
    }

    /**
     * The client must be able to cover the whole commitment now, not by the
     * time it runs. Approving a campaign the wallet cannot fund would put the
     * platform in the position of advancing credit it never agreed to.
     *
     * @return list<string>
     */
    private function fundingReasons(Campaign $campaign): array
    {
        $wallet = Wallet::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $campaign->organization_id)
            ->where('currency', $campaign->currency)
            ->first();

        if ($wallet === null) {
            return ['You do not have a wallet in this currency yet.'];
        }

        $required = $this->costing->total($campaign);
        $available = Money::ofMinor($wallet->available_balance_cached, $campaign->currency());

        if ($available->lessThan($required)) {
            $shortfall = $required->minus($available);

            return [
                "Your balance is {$shortfall->format()} short of this campaign's total of "
                .$required->format().'. Add funds and submit again.',
            ];
        }

        return [];
    }

    /** Convenience for the interface: when the campaign could start running. */
    public function earliestStart(): Carbon
    {
        return Carbon::now()->addHour();
    }
}
