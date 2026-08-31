<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Campaign\Enums\AdSetStatus;
use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Values\Targeting;
use App\Support\Values\Money;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits the audiences inside a draft campaign (spec §21).
 *
 * Targeting always goes through the value object, so a request cannot store a
 * key the system does not understand — which would read later as a narrowing
 * the client asked for having quietly stopped applying.
 */
final class SaveAdSet
{
    public function create(
        Campaign $campaign,
        string $name,
        Targeting $targeting,
        BidStrategy $bidStrategy,
        ?string $bidAmount = null,
        ?string $optimizationGoal = null,
    ): AdSet {
        $this->assertEditable($campaign);

        return DB::transaction(function () use (
            $campaign, $name, $targeting, $bidStrategy, $bidAmount, $optimizationGoal
        ): AdSet {
            $adSet = new AdSet([
                'organization_id' => $campaign->organization_id,
                'campaign_id' => $campaign->getKey(),
                'name' => trim($name),
                'status' => AdSetStatus::Draft,
                'bid_strategy' => $bidStrategy,
                'optimization_goal' => $optimizationGoal,
            ]);

            $adSet->setTargeting($targeting);
            $adSet->bid_amount = $this->bidAmount($campaign, $bidStrategy, $bidAmount);
            $adSet->tenant_id = $campaign->tenant_id;
            $adSet->save();

            return $adSet;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(AdSet $adSet, array $changes): AdSet
    {
        $campaign = $adSet->campaign;

        $this->assertEditable($campaign);

        DB::transaction(function () use ($adSet, $campaign, $changes): void {
            $adSet->fill(array_intersect_key($changes, array_flip([
                'name',
                'optimization_goal',
                'bid_strategy',
                'starts_at',
                'ends_at',
            ])));

            if (isset($changes['targeting']) && $changes['targeting'] instanceof Targeting) {
                $adSet->setTargeting($changes['targeting']);
            }

            if (array_key_exists('bid_amount', $changes)) {
                $adSet->bid_amount = $this->bidAmount(
                    $campaign,
                    $adSet->bid_strategy,
                    $changes['bid_amount'] === null ? null : (string) $changes['bid_amount'],
                );
            }

            $adSet->save();
        });

        return $adSet;
    }

    public function delete(AdSet $adSet): void
    {
        $this->assertEditable($adSet->campaign);

        DB::transaction(function () use ($adSet): void {
            // Ads go with the audience they belong to; neither has been
            // published while the campaign is still editable.
            $adSet->ads()->delete();
            $adSet->delete();
        });
    }

    /**
     * A bid amount only means something when the strategy uses one, and it is
     * converted from a decimal here rather than accepted in minor units.
     */
    private function bidAmount(Campaign $campaign, BidStrategy $strategy, ?string $amount): ?int
    {
        if (! $strategy->requiresAmount() || $amount === null || trim($amount) === '') {
            return null;
        }

        return Money::of($amount, $campaign->currency())->minorUnits;
    }

    private function assertEditable(Campaign $campaign): void
    {
        if (! $campaign->isEditable()) {
            throw CampaignException::notEditable();
        }
    }
}
