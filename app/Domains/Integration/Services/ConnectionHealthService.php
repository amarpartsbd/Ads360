<?php

declare(strict_types=1);

namespace App\Domains\Integration\Services;

use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Integration\Notifications\ConnectionNeedsAttention;
use Illuminate\Support\Carbon;

/**
 * Keeps connection health current (spec §16, §20).
 *
 * Run on a schedule. Three things are decided here: whether a token is close
 * enough to expiry to renew, whether the provider still honours the grant, and
 * what to tell the client when it does not.
 *
 * Tokens are refreshed *before* they expire. A grant that dies mid-publish
 * costs a failed campaign; refreshing a day early costs one request.
 */
final class ConnectionHealthService
{
    /** How far ahead of expiry a token is renewed. */
    private const REFRESH_WINDOW_HOURS = 24;

    /** How far ahead a client is warned when renewal is not possible. */
    private const WARN_WINDOW_HOURS = 72;

    public function __construct(
        private readonly ProviderManager $providers,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Check one connection and bring its status up to date.
     *
     * Returns the status it settled on, so a caller sweeping many connections
     * can report a summary.
     */
    public function check(ProviderConnection $connection): ConnectionStatus
    {
        if ($connection->revoked_at !== null) {
            return ConnectionStatus::Revoked;
        }

        if (! $this->providers->isAvailable($connection->provider)) {
            // The provider is switched off platform-wide. Not the client's
            // problem and not a health failure — leave the status alone.
            return $connection->status;
        }

        $adapter = $this->providers->for($connection->provider);
        $previous = $connection->status;

        try {
            if (! $adapter->verifyConnection($connection)) {
                return $this->settle($connection, ConnectionStatus::Revoked, $previous,
                    'The provider no longer honours this authorisation.');
            }

            if ($this->shouldRefresh($connection, $adapter->supports(ProviderCapability::TokenRefresh))) {
                return $this->refresh($connection, $previous);
            }

            if ($connection->hasExpired()) {
                return $this->settle($connection, ConnectionStatus::Expired, $previous,
                    'The access token has expired.');
            }

            if ($connection->isExpiringSoon(self::WARN_WINDOW_HOURS)) {
                return $this->settle($connection, ConnectionStatus::Expiring, $previous,
                    'The access token expires soon.');
            }

            $connection->forceFill([
                'status' => ConnectionStatus::Connected,
                'status_detail' => null,
                'last_verified_at' => Carbon::now(),
                'consecutive_failures' => 0,
                'last_error' => null,
            ])->save();

            return ConnectionStatus::Connected;
        } catch (ProviderUnavailable $exception) {
            return $this->handleFailure($connection, $previous, $exception);
        }
    }

    private function shouldRefresh(ProviderConnection $connection, bool $providerSupportsRefresh): bool
    {
        return $providerSupportsRefresh
            && $connection->hasRefreshToken()
            && ($connection->hasExpired() || $connection->isExpiringSoon(self::REFRESH_WINDOW_HOURS));
    }

    private function refresh(ProviderConnection $connection, ConnectionStatus $previous): ConnectionStatus
    {
        $adapter = $this->providers->for($connection->provider);

        try {
            $credentials = $adapter->refreshCredentials($connection);
        } catch (ProviderUnavailable $exception) {
            return $this->handleFailure($connection, $previous, $exception);
        }

        $connection->storeCredentials($credentials);

        $connection->forceFill([
            'scopes' => $credentials->scopes !== [] ? $credentials->scopes : $connection->scopes,
            'status' => ConnectionStatus::Connected,
            'status_detail' => null,
            'last_verified_at' => Carbon::now(),
            'consecutive_failures' => 0,
            'last_error' => null,
        ])->save();

        return ConnectionStatus::Connected;
    }

    /**
     * A transient failure is not a health verdict: the provider being briefly
     * unreachable says nothing about whether the grant still stands, so the
     * status is left alone and only the failure counter moves (spec §29).
     */
    private function handleFailure(
        ProviderConnection $connection,
        ConnectionStatus $previous,
        ProviderUnavailable $exception,
    ): ConnectionStatus {
        $failures = $connection->consecutive_failures + 1;

        $connection->forceFill([
            'consecutive_failures' => $failures,
            'last_error' => substr($exception->getMessage(), 0, 250),
        ])->save();

        if ($exception->retryable) {
            return $previous;
        }

        $status = str_contains($exception->getMessage(), 'authentication')
            ? ConnectionStatus::Expired
            : ConnectionStatus::Error;

        return $this->settle($connection, $status, $previous, $exception->clientMessage);
    }

    /**
     * Record a status change, audit it, and tell the client once — not on every
     * sweep while the problem persists.
     */
    private function settle(
        ProviderConnection $connection,
        ConnectionStatus $status,
        ConnectionStatus $previous,
        string $detail,
    ): ConnectionStatus {
        $connection->forceFill([
            'status' => $status,
            'status_detail' => $detail,
            'last_verified_at' => Carbon::now(),
        ])->save();

        if ($status === $previous) {
            return $status;
        }

        $this->audit->recordSystemEvent(
            action: $status === ConnectionStatus::Revoked
                ? AuditAction::ProviderConnectionRevoked
                : AuditAction::ProviderConnectionExpired,
            resource: $connection,
            context: [
                'provider' => $connection->provider->value,
                'from' => $previous->value,
                'to' => $status->value,
            ],
            label: 'ConnectionHealthService',
        );

        if ($status->needsAttention()) {
            $organization = $connection->organization()->withoutGlobalScopes()->first();

            foreach ($organization?->activeMembers()->get() ?? [] as $member) {
                $member->notify(new ConnectionNeedsAttention($connection, $status));
            }
        }

        return $status;
    }
}
