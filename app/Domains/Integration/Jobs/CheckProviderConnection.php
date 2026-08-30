<?php

declare(strict_types=1);

namespace App\Domains\Integration\Jobs;

use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Integration\Services\ConnectionHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Verifies and, where possible, renews one client's provider grant
 * (spec §16, §20).
 *
 * Takes the connection's key rather than the model: a queued payload holding a
 * serialised connection would carry its encrypted token columns into the queue
 * store, which is exactly where credentials should not be sitting (Rule 11).
 */
final class CheckProviderConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(private readonly int $connectionId)
    {
        $this->onQueue('providers');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // A refresh writes a new token. Two of them at once would leave one of
        // the two tokens stored and the other live at the provider.
        return [(new WithoutOverlapping((string) $this->connectionId))->dontRelease()];
    }

    public function handle(ConnectionHealthService $health): void
    {
        $connection = ProviderConnection::query()
            ->withoutGlobalScopes()
            ->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        $health->check($connection);
    }
}
