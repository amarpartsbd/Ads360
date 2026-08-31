<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Enums\ReportType;
use App\Domains\Analytics\Models\ReportExport;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes a report to the private disk (spec §39, §55).
 *
 * Rows are streamed rather than assembled in memory. A year of daily figures
 * across a busy client's campaigns is a large array, and a worker that built
 * the whole file before writing any of it would fall over on exactly the
 * clients whose reports matter most.
 *
 * Every cell goes through `escape()`. A campaign name a client chose is
 * untrusted text, and a spreadsheet opening a CSV treats a leading `=` as a
 * formula — so a name like `=cmd|...` becomes code the moment someone
 * double-clicks the file. Quoting is not enough for that; the value has to be
 * prefixed so the spreadsheet reads it as text.
 */
final class ReportWriter
{
    private const DISK = 'reports';

    /** Characters a spreadsheet will treat as the start of a formula. */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly AnalyticsQuery $analytics) {}

    /**
     * Generate the file and return what the export row needs.
     *
     * @return array{path: string, rows: int, bytes: int}
     */
    public function write(ReportExport $export, Organization $organization): array
    {
        $path = $this->pathFor($export, $organization);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a buffer for the report.');
        }

        try {
            $this->putRow($handle, $export->type->columns());

            $rows = $this->writeBody($handle, $export, $organization);

            rewind($handle);

            $this->disk()->put($path, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return [
            'path' => $path,
            'rows' => $rows,
            'bytes' => (int) $this->disk()->size($path),
        ];
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        return $this->disk()->readStream($path);
    }

    /**
     * @param  resource  $handle
     */
    private function writeBody($handle, ReportExport $export, Organization $organization): int
    {
        $from = Carbon::instance($export->period_start->toDateTime());
        $to = Carbon::instance($export->period_end->toDateTime());

        return match ($export->type) {
            ReportType::CampaignPerformance => $this->writeCampaignPerformance($handle, $organization, $from, $to),
            ReportType::DailyPerformance => $this->writeDailyPerformance($handle, $organization, $from, $to),
            ReportType::SpendStatement => $this->writeSpendStatement($handle, $organization),
        };
    }

    /**
     * @param  resource  $handle
     */
    private function writeCampaignPerformance($handle, Organization $organization, Carbon $from, Carbon $to): int
    {
        $rows = 0;

        foreach ($this->analytics->campaignBreakdown($organization, $from, $to, limit: 5000) as $row) {
            $this->putRow($handle, [
                $row['name'],
                $row['statusLabel'],
                // The currency is on the row, not assumed from the
                // organization: a client can run in more than one.
                $this->currencyOf($row['spend']),
                $row['spend'],
                $row['impressions'],
                $row['clicks'],
                $row['clickThroughRate'] ?? '',
                $row['conversions'],
                $row['costPerConversion'] ?? '',
            ]);

            $rows++;
        }

        return $rows;
    }

    /**
     * @param  resource  $handle
     */
    private function writeDailyPerformance($handle, Organization $organization, Carbon $from, Carbon $to): int
    {
        $rows = 0;

        foreach ($this->analytics->dailySeries($organization, $from, $to) as $day) {
            // Days the provider never reported are skipped rather than written
            // as zeroes: a spreadsheet row of zeroes reads as "nothing
            // happened", which is a different claim from "we do not know".
            if ($day['reported'] === false) {
                continue;
            }

            $this->putRow($handle, [
                $day['date'],
                $this->currencyOf($day['spend']),
                $day['spend'],
                $day['impressions'],
                $day['clicks'],
                $day['conversions'],
            ]);

            $rows++;
        }

        return $rows;
    }

    /**
     * The money view: what each campaign was charged against, from the
     * campaign records rather than the metrics tables.
     *
     * @param  resource  $handle
     */
    private function writeSpendStatement($handle, Organization $organization): int
    {
        $rows = 0;

        Campaign::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('wallet_reservation_id')
            ->orderBy('id')
            ->chunkById(200, function ($campaigns) use ($handle, &$rows): void {
                foreach ($campaigns as $campaign) {
                    $this->putRow($handle, [
                        $campaign->name,
                        $campaign->currency,
                        $campaign->budget()->toDecimal(),
                        $campaign->chargedTotal()->toDecimal(),
                        $campaign->capturedAmount()->toDecimal(),
                        $campaign->reportedSpend()->toDecimal(),
                        $campaign->status->label(),
                    ]);

                    $rows++;
                }
            });

        return $rows;
    }

    /**
     * @param  resource  $handle
     * @param  list<mixed>  $cells
     */
    private function putRow($handle, array $cells): void
    {
        fputcsv(
            $handle,
            array_map(fn (mixed $cell): string => $this->escape($cell), $cells),
            escape: '',
        );
    }

    /**
     * Stops a spreadsheet treating client-supplied text as a formula.
     *
     * A campaign name is whatever a client typed. Quoting protects the CSV's
     * own structure but not the spreadsheet that opens it: Excel and Sheets
     * both evaluate a cell beginning with `=`, `+`, `-` or `@`. Prefixing with
     * an apostrophe makes them read it as text.
     */
    private function escape(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };

        if ($text !== '' && in_array($text[0], self::FORMULA_TRIGGERS, true)) {
            return "'".$text;
        }

        return $text;
    }

    /** Pulls the currency code off an already-formatted amount. */
    private function currencyOf(string $formatted): string
    {
        return Str::before($formatted, ' ');
    }

    /**
     * The organization's ULID groups its files for lifecycle rules, and the
     * filename is random. Nothing in the path is guessable.
     */
    private function pathFor(ReportExport $export, Organization $organization): string
    {
        return sprintf(
            '%s/%s/%s.csv',
            $organization->public_id,
            $export->type->filename(),
            Str::ulid()->toString().'-'.Str::random(16),
        );
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
