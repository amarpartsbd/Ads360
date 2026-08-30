<?php

declare(strict_types=1);

namespace App\Domains\Integration\Actions;

use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Integration\Enums\AssetStatus;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Integration\Models\ProviderConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a connection (spec §15).
 *
 * The stored tokens are cleared immediately — a disconnected account must not
 * leave usable credentials sitting in the database, and holding them longer
 * than the grant is its own risk. The row itself stays, because a campaign
 * published through this connection still refers to it.
 *
 * Assets go unavailable rather than being deleted, for the same reason.
 */
final class DisconnectProvider
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ProviderConnection $connection, User $actor, string $reason = 'Disconnected by user'): void
    {
        $description = $connection->describe();

        DB::transaction(function () use ($connection, $reason): void {
            ProviderAsset::query()
                ->withoutGlobalScopes()
                ->where('provider_connection_id', $connection->getKey())
                ->where('status', AssetStatus::Available)
                ->update([
                    'status' => AssetStatus::PermissionLost,
                    'unavailable_since' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            // Cleared, not kept: the grant is over, and the check constraint
            // only permits a credential-free row once it is revoked.
            $connection->clearCredentials();

            $connection->forceFill([
                'status' => ConnectionStatus::Revoked,
                'status_detail' => $reason,
                'revoked_at' => Carbon::now(),
            ])->save();
        });

        $this->audit->record(
            action: AuditAction::ProviderDisconnected,
            resource: $connection,
            before: $description,
            context: ['reason' => $reason],
            organization: $connection->organization()->withoutGlobalScopes()->first(),
            actor: $actor,
        );
    }
}
