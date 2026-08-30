<?php

declare(strict_types=1);

namespace App\Domains\Integration\Enums;

/**
 * Whether the platform can currently use a connected asset (spec §15).
 *
 * Distinct from what the provider says about it: an asset can be perfectly
 * healthy at Meta and still be unusable here because the grant behind it
 * lapsed.
 */
enum AssetStatus: string
{
    case Available = 'AVAILABLE';
    case Unavailable = 'UNAVAILABLE';
    case PermissionLost = 'PERMISSION_LOST';
    case Disabled = 'DISABLED';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Unavailable => 'No longer listed',
            self::PermissionLost => 'Permission withdrawn',
            self::Disabled => 'Disabled by provider',
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Available;
    }

    public function clientMessage(): string
    {
        return match ($this) {
            self::Available => 'Ready to use.',
            self::Unavailable => 'This asset is no longer listed on the connected account.',
            self::PermissionLost => 'We no longer have permission to use this asset. Reconnect to restore it.',
            self::Disabled => 'The provider has disabled this asset.',
        };
    }
}
