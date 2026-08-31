<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Campaign\Enums\AdSetStatus;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Models\Ad;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Integration\Models\ProviderAsset;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and edits the ads inside an audience (spec §21, §23).
 *
 * The creative and the identity are checked to belong to the same organization
 * as the campaign. Route model binding already scopes them, but an ad that
 * pointed at another tenant's page would be advertising as someone else — so
 * it is checked here too rather than trusted to the layer above (spec §7).
 */
final class SaveAd
{
    public function create(
        AdSet $adSet,
        string $name,
        string $headline,
        string $primaryText,
        string $destinationUrl,
        ?Creative $creative = null,
        ?ProviderAsset $identity = null,
        ?string $description = null,
        ?string $callToAction = null,
    ): Ad {
        $campaign = $adSet->campaign;

        $this->assertEditable($campaign);
        $this->assertBelongs($campaign, $creative, $identity);

        return DB::transaction(function () use (
            $adSet, $campaign, $name, $headline, $primaryText, $destinationUrl,
            $creative, $identity, $description, $callToAction
        ): Ad {
            $ad = new Ad([
                'organization_id' => $campaign->organization_id,
                'campaign_id' => $campaign->getKey(),
                'ad_set_id' => $adSet->getKey(),
                'name' => trim($name),
                'status' => AdSetStatus::Draft,
                'creative_id' => $creative?->getKey(),
                'identity_asset_id' => $identity?->getKey(),
                'headline' => trim($headline),
                'primary_text' => trim($primaryText),
                'description' => $description,
                'call_to_action' => $callToAction,
                'destination_url' => trim($destinationUrl),
            ]);

            $ad->tenant_id = $campaign->tenant_id;
            $ad->save();

            return $ad;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(Ad $ad, array $changes, ?Creative $creative = null, ?ProviderAsset $identity = null): Ad
    {
        $campaign = $ad->campaign;

        $this->assertEditable($campaign);
        $this->assertBelongs($campaign, $creative, $identity);

        DB::transaction(function () use ($ad, $changes, $creative, $identity): void {
            $ad->fill(array_intersect_key($changes, array_flip([
                'name',
                'headline',
                'primary_text',
                'description',
                'call_to_action',
                'destination_url',
            ])));

            if ($creative !== null) {
                $ad->creative_id = $creative->getKey();
            }

            if ($identity !== null) {
                $ad->identity_asset_id = $identity->getKey();
            }

            $ad->save();
        });

        return $ad;
    }

    public function delete(Ad $ad): void
    {
        $this->assertEditable($ad->campaign);

        $ad->delete();
    }

    private function assertEditable(Campaign $campaign): void
    {
        if (! $campaign->isEditable()) {
            throw CampaignException::notEditable();
        }
    }

    private function assertBelongs(Campaign $campaign, ?Creative $creative, ?ProviderAsset $identity): void
    {
        if ($creative !== null && $creative->organization_id !== $campaign->organization_id) {
            throw new RuntimeException('That creative belongs to a different organization.');
        }

        if ($identity !== null && $identity->organization_id !== $campaign->organization_id) {
            throw new RuntimeException('That advertising asset belongs to a different organization.');
        }
    }
}
