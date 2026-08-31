<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

/**
 * The outcome of one publication attempt (Rule 17).
 *
 * `Pending` is the important one. It is written *before* the provider is
 * called, so a worker that dies mid-request leaves evidence that the request
 * may have been made. Nothing treats a pending row as "did not happen".
 */
enum PublicationStatus: string
{
    case Pending = 'PENDING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Abandoned = 'ABANDONED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In progress',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
        };
    }

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
