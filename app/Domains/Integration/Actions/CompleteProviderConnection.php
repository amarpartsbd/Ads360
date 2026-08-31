<?php

declare(strict_types=1);

namespace App\Domains\Integration\Actions;

use App\Domains\Advertising\DTOs\ProviderCredentials;
use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Tenant\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns an authorisation code into a stored connection (spec §16).
 *
 * The exchange happens here, server-side, and the resulting tokens go straight
 * into encrypted columns. Nothing about them is returned to the caller, logged,
 * or audited — the audit record describes the connection, never its
 * credentials (Rule 11, Rule 12).
 *
 * Reconnecting the same provider account updates the existing row rather than
 * adding a second grant, so there is never more than one answer to "which token
 * do we use".
 */
final class CompleteProviderConnection
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly SyncProviderAssets $syncAssets,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        Provider $provider,
        string $authorizationCode,
        Organization $organization,
        User $actor,
    ): ProviderConnection {
        $adapter = $this->providers->for($provider);

        // Outside the transaction: a provider round trip is slow, and holding
        // a database transaction open across it would keep locks for its
        // duration.
        $credentials = $adapter->exchangeCode($authorizationCode);

        $connection = DB::transaction(
            fn (): ProviderConnection => $this->store($provider, $credentials, $organization, $actor),
        );

        // Discovery is best-effort: a connection that stored fine but could not
        // be enumerated is still a connection, and the client can retry from
        // the interface (spec §87).
        if ($adapter->supports(ProviderCapability::AssetDiscovery)) {
            $this->syncAssets->handle($connection, $actor);
        }

        return $connection;
    }

    private function store(
        Provider $provider,
        ProviderCredentials $credentials,
        Organization $organization,
        User $actor,
    ): ProviderConnection {
        /** @var ProviderConnection|null $existing */
        $existing = ProviderConnection::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('provider', $provider)
            ->where('external_user_id', $credentials->externalUserId)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();

        $connection = $existing ?? new ProviderConnection([
            'organization_id' => $organization->getKey(),
            'provider' => $provider,
            'external_user_id' => $credentials->externalUserId,
        ]);

        $connection->tenant_id = $organization->tenant_id;
        $connection->external_user_id = $credentials->externalUserId;
        $connection->account_name = $credentials->accountName;
        $connection->scopes = $credentials->scopes;
        $connection->status = ConnectionStatus::Connected;
        $connection->status_detail = null;
        $connection->last_verified_at = CarbonImmutable::now();
        $connection->consecutive_failures = 0;
        $connection->last_error = null;
        $connection->connected_by = $actor->getKey();

        // Tokens and expiry go through the model's single write path, which
        // encrypts them on the way to the database.
        $connection->storeCredentials($credentials);
        $connection->revoked_at = null;

        $connection->save();

        $this->audit->record(
            action: $existing === null
                ? AuditAction::ProviderConnected
                : AuditAction::ProviderReconnected,
            resource: $connection,
            // describe() carries scopes, expiry and account name — everything
            // useful about the grant, and nothing that grants it.
            after: $credentials->describe(),
            context: ['provider' => $provider->value],
            organization: $organization,
            actor: $actor,
        );

        return $connection;
    }
}
