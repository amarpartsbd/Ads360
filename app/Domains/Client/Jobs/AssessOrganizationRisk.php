<?php

declare(strict_types=1);

namespace App\Domains\Client\Jobs;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Client\Enums\RiskLevel;
use App\Domains\Client\Services\RiskAssessor;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reassess one organization's risk (spec §12).
 *
 * Queued rather than run in the sweep, for the usual reason: an assessment
 * reads across payments, campaigns, the ledger and sign-in history, and a
 * platform with thousands of organizations would otherwise have one schedule
 * tick doing all of it (Rule 16).
 *
 * A level *change* is audited; an unchanged reassessment is not. Writing an
 * audit entry every hour for every quiet account would bury the changes that
 * matter under the ones that do not.
 */
final class AssessOrganizationRisk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $organizationId)
    {
        $this->onQueue('analytics');
    }

    public function handle(RiskAssessor $assessor, AuditRecorder $audit): void
    {
        /** @var Organization|null $organization */
        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->find($this->organizationId);

        if ($organization === null) {
            // Closed between the sweep and this job. Nothing to assess.
            return;
        }

        $before = $assessor->assess($organization)->level;

        $profile = $assessor->record($organization);

        $this->announce($audit, $organization, $before, $profile->level, $profile->score);
    }

    private function announce(
        AuditRecorder $audit,
        Organization $organization,
        RiskLevel $before,
        RiskLevel $after,
        int $score,
    ): void {
        if ($before === $after) {
            return;
        }

        $audit->record(
            action: AuditAction::RiskLevelChanged,
            resource: $organization,
            before: ['level' => $before->value],
            after: ['level' => $after->value, 'score' => $score],
            organization: $organization,
        );
    }
}
