<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Integration\Jobs\CheckProviderConnection;
use App\Domains\Integration\Models\ProviderConnection;
use Illuminate\Console\Command;

/**
 * Queues a health check for every live provider connection (spec §16, §20).
 *
 * Revoked connections are skipped: there is nothing left to verify, and asking
 * the provider about a grant we gave up would only earn a rate limit.
 */
final class CheckProviderConnectionsCommand extends Command
{
    protected $signature = 'ads:check-connections {--provider= : Limit to one provider}';

    protected $description = 'Queue health checks for client provider connections';

    public function handle(): int
    {
        $query = ProviderConnection::query()
            ->withoutGlobalScopes()
            ->whereNull('revoked_at');

        if (is_string($provider = $this->option('provider')) && $provider !== '') {
            $query->where('provider', strtoupper($provider));
        }

        $queued = 0;

        $query->orderBy('id')->chunkById(200, function ($connections) use (&$queued): void {
            foreach ($connections as $connection) {
                CheckProviderConnection::dispatch($connection->getKey());
                $queued++;
            }
        });

        $this->info("Queued {$queued} connection health checks.");

        return self::SUCCESS;
    }
}
