<?php

declare(strict_types=1);

namespace App\Domains\Integration\Actions;

use App\Domains\Advertising\DTOs\DiscoveredAsset;
use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Integration\Enums\AssetStatus;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Integration\Models\ProviderConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles what the provider says a connection may use against what the
 * platform has stored (spec §15, §16).
 *
 * Assets the provider stops listing are marked unavailable rather than deleted.
 * A campaign published last month still points at the page it ran on, and
 * deleting the row would leave that history dangling — which is the same reason
 * ledger entries are never removed (spec §62).
 */
final class SyncProviderAssets
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{discovered: int, added: int, updated: int, unavailable: int}
     */
    public function handle(ProviderConnection $connection, ?User $actor = null): array
    {
        $adapter = $this->providers->for($connection->provider);

        try {
            $discovered = $adapter->discoverAssets($connection);
        } catch (ProviderUnavailable $exception) {
            $this->recordFailure($connection, $exception);

            throw $exception;
        }

        $result = DB::transaction(
            fn (): array => $this->reconcile($connection, $discovered),
        );

        $connection->forceFill([
            'last_synced_at' => Carbon::now(),
            'consecutive_failures' => 0,
            'last_error' => null,
        ])->save();

        $this->audit->record(
            action: AuditAction::ProviderAssetsSynced,
            resource: $connection,
            after: $result,
            context: ['provider' => $connection->provider->value],
            organization: $connection->organization()->withoutGlobalScopes()->first(),
            actor: $actor,
        );

        return $result;
    }

    /**
     * @param  list<DiscoveredAsset>  $discovered
     * @return array{discovered: int, added: int, updated: int, unavailable: int}
     */
    private function reconcile(ProviderConnection $connection, array $discovered): array
    {
        $seenIds = [];
        $added = 0;
        $updated = 0;

        foreach ($discovered as $asset) {
            /** @var ProviderAsset|null $existing */
            $existing = ProviderAsset::query()
                ->withoutGlobalScopes()
                ->where('provider_connection_id', $connection->getKey())
                ->where('type', $asset->type)
                ->where('external_id', $asset->externalId)
                ->first();

            $model = $existing ?? new ProviderAsset([
                'organization_id' => $connection->organization_id,
                'provider_connection_id' => $connection->getKey(),
                'provider' => $connection->provider,
                'type' => $asset->type,
                'external_id' => $asset->externalId,
            ]);

            $model->tenant_id = $connection->tenant_id;
            $model->name = $asset->name;
            $model->currency = $asset->currency;
            $model->timezone = $asset->timezone;
            $model->provider_status = $asset->status;
            $model->metadata = $asset->metadata;
            $model->status = AssetStatus::Available;
            $model->last_seen_at = Carbon::now();
            $model->unavailable_since = null;
            $model->save();

            $seenIds[] = $model->getKey();
            $existing === null ? $added++ : $updated++;
        }

        // Anything the provider no longer lists has had its permission
        // withdrawn or been deleted at their end. Either way the platform must
        // stop offering it.
        $unavailable = ProviderAsset::query()
            ->withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('status', AssetStatus::Available)
            ->when($seenIds !== [], fn ($query) => $query->whereNotIn('id', $seenIds))
            ->update([
                'status' => AssetStatus::PermissionLost,
                'unavailable_since' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return [
            'discovered' => count($discovered),
            'added' => $added,
            'updated' => $updated,
            'unavailable' => $unavailable,
        ];
    }

    /**
     * Record a sync failure on the connection.
     *
     * An authentication failure means the grant is gone, which is a state to
     * store rather than an error to retry (spec §27, §29).
     */
    private function recordFailure(ProviderConnection $connection, ProviderUnavailable $exception): void
    {
        $connection->forceFill([
            'consecutive_failures' => $connection->consecutive_failures + 1,
            'last_error' => substr($exception->getMessage(), 0, 250),
            'status' => $exception->retryable
                ? $connection->status
                : ConnectionStatus::Error,
        ])->save();
    }
}
