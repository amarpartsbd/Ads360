<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shared;

use App\Domains\Analytics\Models\ReportExport;
use App\Domains\Analytics\Services\ReportWriter;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only route to a generated report (spec §39, §55).
 *
 * The disk is private, so there is no URL to guess. Every download is
 * authorised and audited: a report is a file of one client's spend and
 * conversions, and who took a copy is worth knowing.
 */
final class ReportDownloadController
{
    public function __construct(
        private readonly ReportWriter $writer,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Request $request, ReportExport $export): StreamedResponse
    {
        Gate::authorize('download', $export);

        // Checks the expiry as well as the status: the sweep runs on a
        // schedule, and a file can be past its date before it next runs.
        abort_unless($export->isDownloadable(), 404);
        abort_unless($this->writer->exists((string) $export->storage_path), 404);

        $this->audit->record(
            action: AuditAction::ReportDownloaded,
            resource: $export,
            context: $export->describe(),
            actor: $request->user(),
        );

        return response()->stream(
            function () use ($export): void {
                $stream = $this->writer->readStream((string) $export->storage_path);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => 'text/csv',
                // Never inline: a browser rendering a CSV in our origin is how
                // a generated file becomes a stored script.
                'Content-Disposition' => 'attachment; filename="'.$export->downloadName().'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
