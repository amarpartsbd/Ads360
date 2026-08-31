<?php

declare(strict_types=1);

namespace App\Domains\Integration\Enums;

/**
 * What became of an inbound webhook (spec §52).
 */
enum WebhookStatus: string
{
    case Received = 'RECEIVED';
    case Processed = 'PROCESSED';
    case Failed = 'FAILED';
    case Ignored = 'IGNORED';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processed => 'Processed',
            self::Failed => 'Failed',
            self::Ignored => 'Ignored',
        };
    }

    public function isSettled(): bool
    {
        return $this !== self::Received;
    }
}
