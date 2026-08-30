<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Advertising\Contracts\AdvertisingProvider;
use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Integration\Actions\DisconnectProvider;
use App\Domains\Integration\Actions\SyncProviderAssets;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client's view of what they have connected (spec §15, §16).
 *
 * Nothing here ever puts a token in a prop. Connections are serialised through
 * `describe()`, which is credential-free by construction, so a mistake in this
 * controller cannot leak one (Rule 11).
 */
final class AssetController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ProviderManager $providers,
    ) {}

    public function index(): Response
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('viewAny', ProviderConnection::class);

        $connections = ProviderConnection::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('provider')
            ->get();

        $assets = ProviderAsset::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('provider')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return Inertia::render('Client/Assets/Index', [
            'connections' => $connections
                ->map(fn (ProviderConnection $connection): array => [
                    ...$connection->describe(),
                    'label' => $connection->provider->connectionLabel(),
                    'statusLabel' => $connection->status->label(),
                    'message' => $connection->status->clientMessage(),
                    'needsAttention' => $connection->status->needsAttention(),
                    'lastSyncedAt' => $connection->last_synced_at?->toIso8601String(),
                    'can' => [
                        'refresh' => Gate::allows('refresh', $connection),
                        'disconnect' => Gate::allows('disconnect', $connection),
                    ],
                ])
                ->values()
                ->all(),
            'assets' => $assets
                ->map(fn (ProviderAsset $asset): array => [
                    'id' => $asset->public_id,
                    'provider' => $asset->provider->value,
                    'providerLabel' => $asset->provider->label(),
                    'type' => $asset->type->value,
                    'typeLabel' => $asset->type->label(),
                    'name' => $asset->name,
                    'currency' => $asset->currency,
                    'status' => $asset->status->value,
                    'statusLabel' => $asset->status->label(),
                    'message' => $asset->status->clientMessage(),
                    'usable' => $asset->isUsable(),
                ])
                ->values()
                ->all(),
            // Only providers the platform can actually talk to are offered. A
            // button for an adapter that does not exist is a promise we cannot
            // keep (spec §87).
            'connectable' => array_map(
                static fn (AdvertisingProvider $adapter): array => [
                    'value' => $adapter->provider()->value,
                    'label' => $adapter->provider()->connectionLabel(),
                ],
                $this->providers->available(),
            ),
            'assetTypes' => array_map(
                static fn (AssetType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ],
                AssetType::cases(),
            ),
            'can' => [
                'connect' => Gate::allows('create', ProviderConnection::class),
            ],
        ]);
    }

    /**
     * Re-read the assets on an existing connection.
     *
     * Synchronous rather than queued: the client pressed a button and is
     * waiting for the answer, and discovery is one request. A failure is shown
     * as a message, not as a silent no-op.
     */
    public function sync(Request $request, ProviderConnection $connection, SyncProviderAssets $sync): RedirectResponse
    {
        Gate::authorize('refresh', $connection);

        if (! $this->providers->supports($connection->provider, ProviderCapability::AssetDiscovery)) {
            return back()->with('error', ProviderCapability::AssetDiscovery->unavailableMessage());
        }

        try {
            $sync->handle($connection, $request->user());
        } catch (ProviderUnavailable $exception) {
            return back()->with('error', $exception->clientMessage);
        }

        return back()->with('success', 'Your connected assets are up to date.');
    }

    public function disconnect(
        Request $request,
        ProviderConnection $connection,
        DisconnectProvider $disconnect,
    ): RedirectResponse {
        Gate::authorize('disconnect', $connection);

        $disconnect->handle($connection, $request->user(), 'Disconnected from the client interface');

        return back()->with(
            'success',
            'The connection has been removed. Campaigns already published are unaffected.',
        );
    }
}
