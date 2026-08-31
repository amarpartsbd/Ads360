<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Analytics\Enums\ExportStatus;
use App\Domains\Analytics\Models\ReportExport;
use App\Domains\Analytics\Services\ReportWriter;
use Illuminate\Console\Command;

/**
 * Removes report files past their expiry (spec §39, §55).
 *
 * The row stays and is marked expired; only the file goes. Who exported what,
 * and when, is a record worth keeping — the client data inside it is not.
 */
final class PruneReportExportsCommand extends Command
{
    protected $signature = 'ads:prune-exports';

    protected $description = 'Delete expired report files from the private disk';

    public function handle(ReportWriter $writer): int
    {
        $removed = 0;

        ReportExport::query()
            ->withoutGlobalScopes()
            ->expired()
            ->orderBy('id')
            ->chunkById(100, function ($exports) use ($writer, &$removed): void {
                foreach ($exports as $export) {
                    if ($export->storage_path !== null) {
                        $writer->delete($export->storage_path);
                    }

                    $export->forceFill([
                        'status' => ExportStatus::Expired,
                        'storage_path' => null,
                    ])->save();

                    $removed++;
                }
            });

        $this->info("Removed {$removed} expired report files.");

        return self::SUCCESS;
    }
}
