<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Analytics\Actions\ResolveReconciliation;
use App\Domains\Analytics\Enums\ReconciliationStatus;
use App\Domains\Analytics\Models\SpendReconciliation;
use App\Domains\Analytics\Services\AnalyticsQuery;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The reconciliation queue and platform analytics (spec §78, §38).
 *
 * Platform-only. A discrepancy between what a provider reports and what the
 * platform charged is an internal finance matter; putting it in front of a
 * client would raise a question about their bill that nobody has answered yet.
 */
final class ReconciliationController
{
    public function __construct(private readonly AnalyticsQuery $analytics) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SpendReconciliation::class);

        $reconciliations = SpendReconciliation::query()
            ->withoutGlobalScopes()
            ->with(['campaign', 'organization'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', strtoupper($request->string('status')->toString())),
                fn ($query) => $query->needingAttention(),
            )
            // Biggest absolute variance first: the ones that cost the most are
            // the ones worth a person's time first.
            ->orderByRaw('ABS(variance) DESC')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Analytics/Reconciliation', [
            'reconciliations' => $reconciliations->through(
                fn (SpendReconciliation $row): array => [
                    ...$row->describe(),
                    'client' => $row->organization?->name,
                    'campaign' => $row->campaign?->name,
                    'campaignId' => $row->campaign?->public_id,
                    'statusLabel' => $row->status->label(),
                    'underCharged' => $row->underCharged(),
                    'providerSpendFormatted' => $row->providerSpend()->format(),
                    'ledgerSpendFormatted' => $row->ledgerSpend()->format(),
                    'varianceFormatted' => $row->variance()->format(),
                    'checkedAt' => $row->checked_at->toIso8601String(),
                    'resolutionNote' => $row->resolution_note,
                    'can' => ['resolve' => Gate::allows('resolve', $row)],
                ],
            ),
            'filters' => ['status' => $request->string('status')->toString() ?: null],
            'statuses' => array_map(
                static fn (ReconciliationStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                ReconciliationStatus::cases(),
            ),
            'summary' => $this->summary(),
        ]);
    }

    public function resolve(
        Request $request,
        SpendReconciliation $spendReconciliation,
        ResolveReconciliation $resolve,
    ): RedirectResponse {
        Gate::authorize('resolve', $spendReconciliation);

        $validated = $request->validate([
            // Required, and long enough to be a reason rather than a shrug. A
            // discrepancy closed with no explanation looks settled to everyone
            // who comes after.
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $resolve->handle($spendReconciliation, $request->user(), $validated['note']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            'Recorded. If money needs to move, do it through a wallet adjustment.',
        );
    }

    /** Platform-wide performance, for the admin analytics screen. */
    public function overview(Request $request): Response
    {
        Gate::authorize('viewAny', SpendReconciliation::class);

        $to = Carbon::now()->startOfDay();
        $from = $to->copy()->subDays(29);

        $clients = Organization::query()
            ->withoutGlobalScopes()
            ->orderBy('name')
            ->limit(200)
            ->get();

        $rows = $clients
            ->map(function (Organization $organization) use ($from, $to): ?array {
                $totals = $this->analytics->totalsForOrganization($organization, $from, $to);

                if ($totals->spend->isZero() && $totals->impressions === 0) {
                    return null;
                }

                return [
                    'id' => $organization->public_id,
                    'name' => $organization->name,
                    ...$totals->toArray(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return Inertia::render('Admin/Analytics/Overview', [
            'clients' => $rows,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => $this->summary(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return [
            'openDiscrepancies' => SpendReconciliation::query()
                ->withoutGlobalScopes()
                ->needingAttention()
                ->count(),
            'campaignsChecked' => SpendReconciliation::query()
                ->withoutGlobalScopes()
                ->distinct('campaign_id')
                ->count('campaign_id'),
            'liveCampaigns' => Campaign::query()->withoutGlobalScopes()->live()->count(),
        ];
    }
}
