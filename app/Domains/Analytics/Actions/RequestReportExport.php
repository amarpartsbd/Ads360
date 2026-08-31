<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Actions;

use App\Domains\Analytics\Enums\ExportStatus;
use App\Domains\Analytics\Enums\ReportType;
use App\Domains\Analytics\Jobs\GenerateReportExport;
use App\Domains\Analytics\Models\ReportExport;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Queues a report for generation (spec §39).
 *
 * The window is bounded here rather than in the job. A request for a decade of
 * daily rows would be accepted, queued, and then occupy a worker for a long
 * time before failing — better to refuse it while somebody is still looking at
 * the screen.
 *
 * An identical request that is already queued or generating returns the
 * existing export rather than starting a second. Clients press buttons twice.
 */
final class RequestReportExport
{
    public function handle(
        Organization $organization,
        ReportType $type,
        Carbon $from,
        Carbon $to,
        User $actor,
    ): ReportExport {
        if ($from->greaterThan($to)) {
            throw new RuntimeException('The start date must come before the end date.');
        }

        $maximum = (int) config('platform.reporting.max_export_days', 400);

        if ($from->diffInDays($to) + 1 > $maximum) {
            throw new RuntimeException(
                "A report can cover at most {$maximum} days. Choose a shorter period."
            );
        }

        $existing = $this->inFlight($organization, $type, $from, $to);

        if ($existing !== null) {
            return $existing;
        }

        $export = DB::transaction(function () use ($organization, $type, $from, $to, $actor): ReportExport {
            $export = new ReportExport([
                'organization_id' => $organization->getKey(),
                'type' => $type,
                'status' => ExportStatus::Queued,
                'filters' => [],
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'requested_by' => $actor->getKey(),
            ]);

            $export->tenant_id = $organization->tenant_id;
            $export->save();

            return $export;
        });

        // Queued after commit, so a worker cannot pick up an id that is rolled
        // back a moment later (Rule 16).
        GenerateReportExport::dispatch($export->getKey())->afterCommit();

        return $export;
    }

    /**
     * The same report, for the same period, already on its way.
     */
    private function inFlight(
        Organization $organization,
        ReportType $type,
        Carbon $from,
        Carbon $to,
    ): ?ReportExport {
        return ReportExport::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('type', $type)
            ->where('period_start', $from->toDateString())
            ->where('period_end', $to->toDateString())
            ->whereIn('status', [ExportStatus::Queued, ExportStatus::Generating])
            ->first();
    }
}
