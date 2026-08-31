<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Jobs;

use App\Domains\Analytics\Enums\ExportStatus;
use App\Domains\Analytics\Models\ReportExport;
use App\Domains\Analytics\Services\ReportWriter;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Generates one report file (spec §39, Rule 16).
 *
 * On the reports queue with a long timeout, because a year of a busy client's
 * data genuinely takes a while and §28 keeps that away from anything a person
 * is waiting on.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Large exports are slow by nature (spec §39). */
    public int $timeout = 900;

    public function __construct(private readonly int $exportId)
    {
        $this->onQueue('reports');
    }

    public function handle(ReportWriter $writer, AuditRecorder $audit): void
    {
        $export = ReportExport::query()->withoutGlobalScopes()->find($this->exportId);

        if ($export === null || $export->status !== ExportStatus::Queued) {
            return;
        }

        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->find($export->organization_id);

        if ($organization === null) {
            $export->forceFill([
                'status' => ExportStatus::Failed,
                'last_error' => 'The organization this report belongs to no longer exists.',
            ])->save();

            return;
        }

        $export->forceFill(['status' => ExportStatus::Generating])->save();

        try {
            $result = $writer->write($export, $organization);
        } catch (Throwable $exception) {
            $export->forceFill([
                'status' => ExportStatus::Failed,
                // Plain language: the client sees this, and a stack trace
                // would tell them nothing they can act on (spec §80).
                'last_error' => 'We could not generate this report. Please try again.',
            ])->save();

            throw $exception;
        }

        $export->forceFill([
            'status' => ExportStatus::Ready,
            'storage_path' => $result['path'],
            'row_count' => $result['rows'],
            'byte_size' => $result['bytes'],
            'completed_at' => Carbon::now(),
            // A snapshot of a client's data should not live on a disk
            // indefinitely because somebody once clicked a button.
            'expires_at' => Carbon::now()->addDays(
                (int) config('platform.reporting.export_lifetime_days', 7),
            ),
        ])->save();

        $audit->recordSystemEvent(
            action: AuditAction::ReportExported,
            resource: $export,
            context: $export->describe(),
            label: 'GenerateReportExport',
        );
    }
}
