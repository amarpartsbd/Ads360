<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Jobs;

use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Services\AdAccountHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Checks one managed account's health against its provider (spec §20).
 *
 * One account per job. A single job sweeping the whole inventory would take a
 * provider outage personally and fail every account with it; this way a bad
 * account retries alone and the rest are already done (spec §29).
 */
final class CheckAdAccountHealth implements ShouldQueue
{
    use Queueable;

    /** Provider calls are slow and rate-limited, so this waits its turn. */
    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $adAccountId)
    {
        $this->onQueue('providers');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Two checks racing on one account would write two verdicts from two
        // moments in time, and the later write might carry the older figures.
        return [(new WithoutOverlapping((string) $this->adAccountId))->dontRelease()];
    }

    public function handle(AdAccountHealthService $health): void
    {
        $account = AdAccount::query()->find($this->adAccountId);

        if ($account === null) {
            // Retired and hard-deleted between scheduling and running. Nothing
            // to check, and nothing worth failing the job over.
            return;
        }

        $health->check($account);
    }
}
