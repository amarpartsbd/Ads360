<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * The health of a provider connection (spec §16).
 */
enum ConnectionStatus: string
{
    case Connected = 'CONNECTED';
    case Expiring = 'EXPIRING';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
    case Error = 'ERROR';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Expiring => 'Expiring soon',
            self::Expired => 'Expired',
            self::Revoked => 'Access revoked',
            self::Error => 'Connection error',
        };
    }

    /** Whether the connection can still be used to call the provider. */
    public function isUsable(): bool
    {
        return in_array($this, [self::Connected, self::Expiring], true);
    }

    /** Whether the client has to do something about it. */
    public function needsAttention(): bool
    {
        return $this !== self::Connected;
    }

    /**
     * What to tell the client. Never an provider error code — spec §80 is
     * explicit that "OAuthException #190" is the wrong thing to show someone.
     */
    public function clientMessage(): string
    {
        return match ($this) {
            self::Connected => 'This connection is working normally.',
            self::Expiring => 'This connection expires soon. Reconnect to avoid interruption.',
            self::Expired => 'This connection has expired. Please reconnect your account.',
            self::Revoked => 'Access was withdrawn at the provider. Please reconnect your account.',
            self::Error => 'We could not reach this connection. Please reconnect your account.',
        };
    }
}
