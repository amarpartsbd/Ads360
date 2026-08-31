<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Enums\PublicationOperation;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Services\CampaignSpendReconciler;
use App\Domains\Campaign\Services\PublicationLedger;
use App\Domains\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pause, resume and stop a running campaign (spec §21).
 *
 * The provider is told first and the local status is written only if it
 * agreed. The other order would show a client a paused campaign that was still
 * spending — the worst possible lie for this screen to tell.
 *
 * Pausing does not release the budget. A paused campaign is expected to resume,
 * and giving the money back would mean it might not be there when it does.
 * Stopping is what releases.
 */
final class ControlCampaign
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly PublicationLedger $ledger,
        private readonly CampaignSpendReconciler $reconciler,
        private readonly AuditRecorder $audit,
    ) {}

    public function pause(Campaign $campaign, User $actor): Campaign
    {
        return $this->setActive($campaign, false, $actor);
    }

    public function resume(Campaign $campaign, User $actor): Campaign
    {
        return $this->setActive($campaign, true, $actor);
    }

    /**
     * Stop for good: the provider is told, spend is settled and whatever is
     * left of the hold goes back to the client's wallet.
     */
    public function stop(Campaign $campaign, User $actor, string $reason = 'Stopped by request'): Campaign
    {
        $this->assertLive($campaign);

        $account = $campaign->adAccount()->withoutGlobalScopes()->first();
        $adapter = $this->providers->for($campaign->provider);

        $publication = $this->ledger->claim(
            $campaign,
            $campaign,
            PublicationOperation::Stop,
            ['reason' => $reason],
        );

        try {
            if ($account !== null) {
                $adapter->stopCampaign(
                    $account,
                    (string) $campaign->provider_campaign_id,
                    $publication->idempotency_key ?? PublicationOperation::Stop->value,
                );
            }
        } catch (ProviderUnavailable $exception) {
            if ($publication !== null) {
                $this->ledger->fail($publication, $exception->clientMessage);
            }

            throw $exception;
        }

        if ($publication !== null) {
            $this->ledger->succeed($publication, (string) $campaign->provider_campaign_id);
        }

        // Settles the money: captures anything outstanding, releases the rest.
        $campaign = $this->reconciler->complete($campaign, $reason);

        $this->audit->record(
            action: AuditAction::CampaignCompleted,
            resource: $campaign,
            after: $campaign->describe(),
            context: ['reason' => $reason],
            actor: $actor,
        );

        return $campaign;
    }

    private function setActive(Campaign $campaign, bool $active, User $actor): Campaign
    {
        $this->assertLive($campaign);

        $target = $active ? CampaignStatus::Active : CampaignStatus::Paused;

        if ($campaign->status === $target) {
            return $campaign;
        }

        if (! $campaign->status->canTransitionTo($target)) {
            throw CampaignException::invalidTransition($campaign->status, $target);
        }

        $adapter = $this->providers->for($campaign->provider);

        if (! $adapter->supports(ProviderCapability::CampaignPause)) {
            throw ProviderUnavailable::notSupported(
                $campaign->provider,
                ProviderCapability::CampaignPause,
            );
        }

        $account = $campaign->adAccount()->withoutGlobalScopes()->first();

        if ($account === null) {
            throw CampaignException::notPublished();
        }

        $operation = $active ? PublicationOperation::Resume : PublicationOperation::Pause;

        // Repeatable by design: asking to pause something already paused
        // changes nothing, so a fresh claim on every call is correct here.
        $publication = $this->ledger->claim($campaign, $campaign, $operation);

        try {
            $adapter->setCampaignActive(
                $account,
                (string) $campaign->provider_campaign_id,
                $active,
                $publication->idempotency_key ?? $operation->value,
            );
        } catch (ProviderUnavailable $exception) {
            if ($publication !== null) {
                $this->ledger->fail($publication, $exception->clientMessage);
            }

            // The local status is untouched: the campaign is still whatever
            // the provider last confirmed it was.
            throw $exception;
        }

        if ($publication !== null) {
            $this->ledger->succeed($publication, (string) $campaign->provider_campaign_id);
        }

        $before = AuditRecorder::snapshot($campaign);

        DB::transaction(function () use ($campaign, $target, $active): void {
            $campaign->status = $target;
            $campaign->paused_at = $active ? null : CarbonImmutable::now();
            $campaign->save();
        });

        $this->audit->recordChange(
            action: $active ? AuditAction::CampaignResumed : AuditAction::CampaignPaused,
            resource: $campaign,
            before: $before,
            actor: $actor,
        );

        return $campaign;
    }

    private function assertLive(Campaign $campaign): void
    {
        if ($campaign->provider_campaign_id === null || ! $campaign->status->isLive()) {
            throw CampaignException::notPublished();
        }
    }
}
