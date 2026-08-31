<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Client\Jobs\AssessOrganizationRisk;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Console\Command;

/**
 * Queues a risk reassessment for every live organization (spec §12).
 *
 * Closed organizations are skipped: there is nothing to decide about an
 * account nobody can spend from, and scoring them would fill the compliance
 * queue with accounts that ended months ago.
 */
final class AssessClientRiskCommand extends Command
{
    protected $signature = 'clients:assess-risk
                            {--all : Include closed organizations}';

    protected $description = 'Queue a risk reassessment for every organization';

    public function handle(): int
    {
        $query = Organization::query()->withoutGlobalScopes();

        if (! $this->option('all')) {
            $query->whereNot('status', OrganizationStatus::Closed->value);
        }

        $queued = 0;

        // Chunked by primary key so the sweep is stable while organizations
        // are being created underneath it.
        $query->orderBy('id')->chunkById(200, function ($organizations) use (&$queued): void {
            foreach ($organizations as $organization) {
                AssessOrganizationRisk::dispatch($organization->getKey());
                $queued++;
            }
        });

        $this->info("Queued {$queued} risk assessments.");

        return self::SUCCESS;
    }
}
