<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Analytics\Actions\RequestReportExport;
use App\Domains\Analytics\Enums\ReportType;
use App\Domains\Analytics\Models\ReportExport;
use App\Domains\Analytics\Services\AnalyticsQuery;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * A client's performance figures (spec §38, §39).
 *
 * Every number in these props is already computed and formatted. The browser
 * draws a chart from values it was given; it does not sum, average or divide
 * anything (Rule 8).
 */
final class AnalyticsController
{
    /** The longest window the screen offers, so one request stays bounded. */
    private const MAX_WINDOW_DAYS = 366;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AnalyticsQuery $analytics,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ReportExport::class);

        $organization = $this->context->requireOrganization();
        [$from, $to] = $this->window($request);

        $currency = $this->currencyFor($request, $organization);
        $totals = $this->analytics->totalsForOrganization($organization, $from, $to, $currency);

        // The same length of window, immediately before this one — worked out
        // here so the browser never has to guess what "the previous period" is.
        $preceding = $this->analytics->precedingWindow($from, $to);
        $previous = $this->analytics->totalsForOrganization(
            $organization,
            $preceding['from'],
            $preceding['to'],
            $currency,
        );

        return Inertia::render('Client/Analytics/Index', [
            'totals' => $totals->toArray(),
            'previous' => $previous->toArray(),
            'change' => $this->change($totals->spend->minorUnits, $previous->spend->minorUnits),
            'series' => $this->analytics->dailySeries($organization, $from, $to, $currency),
            'campaigns' => $this->analytics->campaignBreakdown($organization, $from, $to),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'currency' => $currency,
            ],
            'currencies' => $this->analytics->currenciesFor($organization) ?: [$currency],
            'exports' => $this->recentExports($organization),
            'reportTypes' => array_map(
                static fn (ReportType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                ],
                ReportType::cases(),
            ),
            'can' => [
                'export' => Gate::allows('create', ReportExport::class),
            ],
        ]);
    }

    public function export(Request $request, RequestReportExport $exports): RedirectResponse
    {
        Gate::authorize('create', ReportExport::class);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ReportType::class)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        try {
            $exports->handle(
                organization: $this->context->requireOrganization(),
                type: ReportType::from($validated['type']),
                from: Carbon::parse($validated['from'])->startOfDay(),
                to: Carbon::parse($validated['to'])->startOfDay(),
                actor: $request->user(),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            'Your report is being prepared. It will appear below when it is ready.',
        );
    }

    /**
     * The window being looked at, clamped.
     *
     * Bounds are applied to whatever arrives rather than trusted: a query
     * string is user input, and an unbounded range would let one request ask
     * the database for everything.
     *
     * @return array{Carbon, Carbon}
     */
    private function window(Request $request): array
    {
        $to = $this->parse($request->query('to')) ?? Carbon::now()->startOfDay();
        $from = $this->parse($request->query('from')) ?? $to->copy()->subDays(29);

        if ($from->greaterThan($to)) {
            $from = $to->copy()->subDays(29);
        }

        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $from = $to->copy()->subDays(self::MAX_WINDOW_DAYS);
        }

        return [$from, $to];
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            // A malformed date falls back to the default rather than raising:
            // a bad bookmark should show a sensible page, not an error.
            return null;
        }
    }

    /**
     * A currency the organization actually has figures in. Anything else is
     * ignored in favour of their default.
     */
    private function currencyFor(Request $request, $organization): string
    {
        $requested = strtoupper((string) $request->query('currency', ''));
        $available = $this->analytics->currenciesFor($organization);

        if ($requested !== '' && in_array($requested, $available, true)) {
            return $requested;
        }

        return $organization->default_currency;
    }

    /**
     * Percentage change against the previous period, as a string, or null when
     * there is nothing to compare against — "up ∞%" from zero is not a fact.
     */
    private function change(int $current, int $previous): ?string
    {
        if ($previous === 0) {
            return null;
        }

        return number_format((($current - $previous) * 10000 / $previous) / 100, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentExports($organization): array
    {
        return ReportExport::query()
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(static fn (ReportExport $export): array => [
                'id' => $export->public_id,
                'type' => $export->type->value,
                'typeLabel' => $export->type->label(),
                'status' => $export->status->value,
                'statusLabel' => $export->status->label(),
                'message' => $export->status->clientMessage(),
                'period' => $export->period_start?->toDateString().' – '.$export->period_end?->toDateString(),
                'rows' => $export->row_count,
                'downloadable' => $export->isDownloadable(),
                'requestedAt' => $export->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
