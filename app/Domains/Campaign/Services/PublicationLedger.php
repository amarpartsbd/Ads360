<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Services;

use App\Domains\Campaign\Enums\PublicationOperation;
use App\Domains\Campaign\Enums\PublicationStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Models\CampaignPublication;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The bookkeeping that makes publishing safe to retry (Rule 17, spec §98).
 *
 * The protocol is deliberately small and always the same:
 *
 *   1. **Claim.** Write a PENDING row before the provider is called. If a
 *      succeeded row for this entity and operation already exists, the work is
 *      already done and the caller is told so rather than calling again.
 *   2. **Call.** The caller talks to the provider, holding the key the claim
 *      minted.
 *   3. **Settle.** The row is marked succeeded with the provider's identifier,
 *      or failed with a client-safe message.
 *
 * The reason the claim is written *first* is the case that actually loses
 * money: a worker that dies after the provider acted but before anything was
 * recorded. A row written beforehand leaves evidence that the request may have
 * been made, so a later attempt reuses the same key instead of asking for a
 * second campaign.
 *
 * Both guarantees are indexes, not code: `campaign_publications_unique_key`
 * makes a key single-use, and
 * `campaign_publications_one_success_per_operation` allows one success per
 * entity per operation. Two workers racing both reach the database, and one of
 * them loses.
 */
final class PublicationLedger
{
    /**
     * Claim the right to perform an operation.
     *
     * Returns null when it has already succeeded — the caller must then do
     * nothing rather than treat it as a fresh attempt.
     *
     * @param  array<string, mixed>  $snapshot  the request, minus anything sensitive
     */
    public function claim(
        Campaign $campaign,
        Model $publishable,
        PublicationOperation $operation,
        array $snapshot = [],
    ): ?CampaignPublication {
        $existing = $this->succeeded($publishable, $operation);

        if ($existing !== null && $operation->isCreation()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($campaign, $publishable, $operation, $snapshot): CampaignPublication {
                $publication = new CampaignPublication([
                    'campaign_id' => $campaign->getKey(),
                    'publishable_type' => $publishable::class,
                    'publishable_id' => $publishable->getKey(),
                    'provider' => $campaign->provider,
                    'operation' => $operation,
                    'idempotency_key' => CampaignPublication::newKey(),
                    'status' => PublicationStatus::Pending,
                    'attempts' => 1,
                    'request_snapshot' => $snapshot,
                    'started_at' => Carbon::now(),
                ]);

                $publication->tenant_id = $campaign->tenant_id;
                $publication->save();

                return $publication;
            });
        } catch (UniqueConstraintViolationException) {
            // Another worker claimed the same creation between the check above
            // and this insert. It won; this attempt does nothing.
            return null;
        }
    }

    /**
     * Reuse the key from an attempt that never settled.
     *
     * This is what a retry after a crash uses: the same key goes back to the
     * provider, which recognises it and returns the entity it already made
     * instead of creating another.
     */
    public function unsettled(Model $publishable, PublicationOperation $operation): ?CampaignPublication
    {
        return CampaignPublication::query()
            ->withoutGlobalScopes()
            ->where('publishable_type', $publishable::class)
            ->where('publishable_id', $publishable->getKey())
            ->where('operation', $operation)
            ->where('status', PublicationStatus::Pending)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Claim, or pick up an attempt that was left in flight.
     *
     * The order matters: an unsettled attempt is resumed in preference to
     * starting a new one, because starting fresh would send a new key for work
     * the provider may already have done.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function claimOrResume(
        Campaign $campaign,
        Model $publishable,
        PublicationOperation $operation,
        array $snapshot = [],
    ): ?CampaignPublication {
        $inFlight = $this->unsettled($publishable, $operation);

        if ($inFlight !== null) {
            $inFlight->attempts++;
            $inFlight->started_at = CarbonImmutable::now();
            $inFlight->save();

            return $inFlight;
        }

        return $this->claim($campaign, $publishable, $operation, $snapshot);
    }

    public function succeed(CampaignPublication $publication, string $providerReference): CampaignPublication
    {
        $publication->status = PublicationStatus::Succeeded;
        $publication->provider_reference = $providerReference;
        $publication->last_error = null;
        $publication->completed_at = CarbonImmutable::now();
        $publication->save();

        return $publication;
    }

    /**
     * Record a failure with text safe to show a client. A provider's own error
     * string stays in the logs (spec §80).
     */
    public function fail(CampaignPublication $publication, string $clientSafeMessage): CampaignPublication
    {
        $publication->status = PublicationStatus::Failed;
        $publication->last_error = mb_substr($clientSafeMessage, 0, 250);
        $publication->completed_at = CarbonImmutable::now();
        $publication->save();

        return $publication;
    }

    /** The successful attempt for an entity and operation, if there is one. */
    public function succeeded(Model $publishable, PublicationOperation $operation): ?CampaignPublication
    {
        return CampaignPublication::query()
            ->withoutGlobalScopes()
            ->where('publishable_type', $publishable::class)
            ->where('publishable_id', $publishable->getKey())
            ->where('operation', $operation)
            ->where('status', PublicationStatus::Succeeded)
            ->first();
    }

    public function hasSucceeded(Model $publishable, PublicationOperation $operation): bool
    {
        return $this->succeeded($publishable, $operation) !== null;
    }
}
