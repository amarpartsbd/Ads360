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
    /**
     * @param  list<string>  $extraHeadlines  further headlines for providers
     *                                        that rotate several in one ad
     * @param  list<string>  $extraDescriptions
     */
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
        array $extraHeadlines = [],
        array $extraDescriptions = [],
    ): Ad {
        $campaign = $adSet->campaign;

        $this->assertEditable($campaign);
        $this->assertBelongs($campaign, $creative, $identity);

        $extraHeadlines = $this->cleanCopy($extraHeadlines);
        $extraDescriptions = $this->cleanCopy($extraDescriptions);

        return DB::transaction(function () use (
            $adSet, $campaign, $name, $headline, $primaryText, $destinationUrl,
            $creative, $identity, $description, $callToAction,
            $extraHeadlines, $extraDescriptions
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
                'extra_headlines' => $extraHeadlines,
                'extra_descriptions' => $extraDescriptions,
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

            foreach (['extra_headlines', 'extra_descriptions'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $ad->{$field} = $this->cleanCopy((array) $changes[$field]);
                }
            }

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

    /**
     * Trimmed, de-duplicated, and empties dropped.
     *
     * This copy is sent to a provider verbatim, and a blank headline among
     * three real ones is an ad Google refuses for a reason nobody reading the
     * form would guess at.
     *
     * @param  array<int|string, mixed>  $texts
     * @return list<string>
     */
    private function cleanCopy(array $texts): array
    {
        $kept = [];

        foreach ($texts as $text) {
            if (! is_string($text)) {
                continue;
            }

            $trimmed = trim($text);

            if ($trimmed !== '' && ! in_array($trimmed, $kept, true)) {
                $kept[] = $trimmed;
            }
        }

        return $kept;
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
