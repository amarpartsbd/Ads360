<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Jobs\CheckAdAccountHealth;
use App\Domains\Advertising\Models\AdAccount;
use Illuminate\Console\Command;

/**
 * Queues a health check for every managed account that is in service
 * (spec §20).
 *
 * The command does no provider work itself: it selects accounts and hands each
 * one to the queue (Rule 16). Running the checks inline would leave a schedule
 * tick blocked behind whichever provider is slowest that hour.
 */
final class CheckAdAccountsCommand extends Command
{
    protected $signature = 'ads:check-ad-accounts
                            {--provider= : Limit to one provider}
                            {--all : Include paused accounts, which are normally skipped}';

    protected $description = 'Queue provider health checks for managed ad accounts';

    public function handle(): int
    {
        $query = AdAccount::query()
            ->whereIn('status', $this->option('all')
                ? [AdAccountStatus::Active, AdAccountStatus::Paused, AdAccountStatus::PendingSetup]
                : [AdAccountStatus::Active]);

        if (is_string($provider = $this->option('provider')) && $provider !== '') {
            $query->where('provider', strtoupper($provider));
        }

        $queued = 0;

        // Chunked by primary key so the sweep is stable while accounts are
        // being registered or retired underneath it.
        $query->orderBy('id')->chunkById(200, function ($accounts) use (&$queued): void {
            foreach ($accounts as $account) {
                CheckAdAccountHealth::dispatch($account->getKey());
                $queued++;
            }
        });

        $this->info("Queued {$queued} ad account health checks.");

        return self::SUCCESS;
    }
}
