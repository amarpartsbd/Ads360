<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Services;

use App\Domains\Advertising\DTOs\AdDraft;
use App\Domains\Advertising\DTOs\AdSetDraft;
use App\Domains\Advertising\DTOs\CampaignDraft;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Enums\AdSetStatus;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Enums\PublicationOperation;
use App\Domains\Campaign\Models\Ad;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Support\Carbon;

/**
 * Sends a campaign to its provider (spec §21, §26, Rule 17).
 *
 * Publishing is three nested creations — campaign, then each ad set, then each
 * ad — and every one of them goes through the same protocol: claim, call,
 * settle. Nothing here calls a provider without a claim in hand.
 *
 * The method is written so that running it twice is safe and running it after
 * a crash is safe. Both come from the same rule: anything already recorded as
 * created is skipped, and anything left in flight resumes with the key it
 * started with rather than a new one.
 *
 * Partial success is normal and is not undone. If three ad sets publish and
 * the fourth fails, the three stay published; the retry picks up at the fourth.
 * Deleting the three to "clean up" would mean deleting things a provider has
 * already begun charging for.
 */
final class CampaignPublisher
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly PublicationLedger $ledger,
        private readonly CreativeStorage $creatives,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws ProviderUnavailable when the provider could not be reached; the
     *                             campaign is left retryable, with its budget
     *                             still held
     */
    public function publish(Campaign $campaign): Campaign
    {
        $account = $campaign->adAccount()->withoutGlobalScopes()->first();

        if ($account === null) {
            throw ProviderUnavailable::refused(
                $campaign->provider,
                'the campaign has no allocated ad account',
            );
        }

        $adapter = $this->providers->for($campaign->provider);

        if (! $adapter->supports(ProviderCapability::CampaignCreation)) {
            throw ProviderUnavailable::notSupported(
                $campaign->provider,
                ProviderCapability::CampaignCreation,
            );
        }

        $campaign->loadMissing(['adSets.ads.creative', 'adSets.ads.identity']);

        $this->markPublishing($campaign);

        $externalCampaignId = $this->publishCampaign($campaign, $account);

        foreach ($campaign->adSets as $adSet) {
            $externalAdSetId = $this->publishAdSet($campaign, $account, $adSet, $externalCampaignId);

            foreach ($adSet->ads as $ad) {
                $this->publishAd($campaign, $account, $ad, $externalAdSetId);
            }
        }

        return $this->markActive($campaign);
    }

    private function publishCampaign(Campaign $campaign, AdAccount $account): string
    {
        $already = $this->ledger->succeeded($campaign, PublicationOperation::CreateCampaign);

        if ($already !== null) {
            // Already at the provider. Re-sending would be a second campaign
            // spending a second budget.
            return (string) $already->provider_reference;
        }

        $draft = new CampaignDraft(
            reference: $campaign->public_id,
            name: $campaign->name,
            objective: $campaign->objective,
            budgetType: $campaign->budget_type,
            budgetMinor: $campaign->budget_amount,
            currency: $campaign->currency,
            startsAt: $campaign->starts_at?->toDateTimeImmutable(),
            endsAt: $campaign->ends_at?->toDateTimeImmutable(),
        );

        $publication = $this->ledger->claimOrResume(
            $campaign,
            $campaign,
            PublicationOperation::CreateCampaign,
            ['name' => $draft->name, 'budget_minor' => $draft->budgetMinor],
        );

        if ($publication === null) {
            // Another worker won the claim. Read its result rather than
            // racing it.
            $winner = $this->ledger->succeeded($campaign, PublicationOperation::CreateCampaign);

            if ($winner !== null) {
                return (string) $winner->provider_reference;
            }

            throw ProviderUnavailable::transient(
                $campaign->provider,
                'another worker is publishing this campaign',
            );
        }

        try {
            $result = $this->providers->for($campaign->provider)->createCampaign(
                $account,
                $draft,
                $publication->idempotency_key,
            );
        } catch (ProviderUnavailable $exception) {
            $this->ledger->fail($publication, $exception->clientMessage);

            throw $exception;
        }

        $this->ledger->succeed($publication, $result->externalId);

        $campaign->provider_campaign_id = $result->externalId;
        $campaign->published_at = Carbon::now();
        $campaign->save();

        return $result->externalId;
    }

    private function publishAdSet(
        Campaign $campaign,
        AdAccount $account,
        AdSet $adSet,
        string $externalCampaignId,
    ): string {
        $already = $this->ledger->succeeded($adSet, PublicationOperation::CreateAdSet);

        if ($already !== null) {
            return (string) $already->provider_reference;
        }

        $draft = new AdSetDraft(
            reference: $adSet->public_id,
            externalCampaignId: $externalCampaignId,
            name: $adSet->name,
            targeting: $adSet->targeting(),
            bidStrategy: $adSet->bid_strategy,
            bidAmountMinor: $adSet->bid_amount,
            budgetMinor: $adSet->budget_amount,
            optimizationGoal: $adSet->optimization_goal,
            startsAt: $adSet->starts_at?->toDateTimeImmutable(),
            endsAt: $adSet->ends_at?->toDateTimeImmutable(),
        );

        $publication = $this->ledger->claimOrResume(
            $campaign,
            $adSet,
            PublicationOperation::CreateAdSet,
            ['name' => $draft->name],
        );

        if ($publication === null) {
            $winner = $this->ledger->succeeded($adSet, PublicationOperation::CreateAdSet);

            if ($winner !== null) {
                return (string) $winner->provider_reference;
            }

            throw ProviderUnavailable::transient(
                $campaign->provider,
                'another worker is publishing this audience',
            );
        }

        try {
            $result = $this->providers->for($campaign->provider)->createAdSet(
                $account,
                $draft,
                $publication->idempotency_key,
            );
        } catch (ProviderUnavailable $exception) {
            $this->ledger->fail($publication, $exception->clientMessage);
            $this->markFailed($adSet, $exception->clientMessage);

            throw $exception;
        }

        $this->ledger->succeed($publication, $result->externalId);

        $adSet->provider_ad_set_id = $result->externalId;
        $adSet->status = AdSetStatus::Active;
        $adSet->published_at = Carbon::now();
        $adSet->last_error = null;
        $adSet->save();

        return $result->externalId;
    }

    private function publishAd(Campaign $campaign, AdAccount $account, Ad $ad, string $externalAdSetId): void
    {
        if ($this->ledger->hasSucceeded($ad, PublicationOperation::CreateAd)) {
            return;
        }

        $creative = $ad->creative;
        $identity = $ad->identity;

        if ($creative === null || $identity === null) {
            // Readiness should have caught this before approval. Reaching here
            // means the asset changed underneath an approved campaign.
            $this->markFailed($ad, 'This ad is missing its image or its page.');

            return;
        }

        // A row can outlive its bytes — a misconfigured disk, a file removed
        // out of band. That is an ad that cannot publish, not a storage
        // exception to escape into the queue and be retried four times.
        if (! $this->creatives->exists($creative->storage_path)) {
            $this->markFailed($ad, 'We could not read the image for this ad. Please upload it again.');

            return;
        }

        $draft = new AdDraft(
            reference: $ad->public_id,
            externalAdSetId: $externalAdSetId,
            name: $ad->name,
            headline: $ad->headline,
            primaryText: $ad->primary_text,
            destinationUrl: $ad->destination_url,
            identityExternalId: $identity->external_id,
            creativeChecksum: $creative->checksum,
            // Opened lazily, and only by an adapter that needs the bytes.
            openCreative: fn () => $this->creatives->readStream($creative->storage_path),
            description: $ad->description,
            callToAction: $ad->call_to_action,
        );

        $publication = $this->ledger->claimOrResume(
            $campaign,
            $ad,
            PublicationOperation::CreateAd,
            ['name' => $draft->name, 'creative' => $creative->checksum],
        );

        if ($publication === null) {
            return;
        }

        try {
            $result = $this->providers->for($campaign->provider)->createAd(
                $account,
                $draft,
                $publication->idempotency_key,
            );
        } catch (ProviderUnavailable $exception) {
            $this->ledger->fail($publication, $exception->clientMessage);
            $this->markFailed($ad, $exception->clientMessage);

            throw $exception;
        }

        $this->ledger->succeed($publication, $result->externalId);

        $ad->provider_ad_id = $result->externalId;
        $ad->status = AdSetStatus::Active;
        $ad->published_at = Carbon::now();
        $ad->last_error = null;
        $ad->save();
    }

    private function markPublishing(Campaign $campaign): void
    {
        if ($campaign->status->canTransitionTo(CampaignStatus::Publishing)) {
            $campaign->status = CampaignStatus::Publishing;
            $campaign->save();
        }
    }

    private function markActive(Campaign $campaign): Campaign
    {
        if ($campaign->status->canTransitionTo(CampaignStatus::Active)) {
            $campaign->status = CampaignStatus::Active;
            $campaign->last_error = null;
            $campaign->save();

            $this->audit->recordSystemEvent(
                action: AuditAction::CampaignPublished,
                resource: $campaign,
                context: [
                    'provider' => $campaign->provider->value,
                    'provider_campaign_id' => $campaign->provider_campaign_id,
                ],
                label: 'CampaignPublisher',
            );
        }

        return $campaign;
    }

    private function markFailed(AdSet|Ad $entity, string $message): void
    {
        $entity->status = AdSetStatus::Failed;
        $entity->last_error = mb_substr($message, 0, 250);
        $entity->save();
    }
}
