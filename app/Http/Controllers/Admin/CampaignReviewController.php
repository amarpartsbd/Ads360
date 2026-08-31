<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Campaign\Actions\ApproveCampaign;
use App\Domains\Campaign\Actions\ControlCampaign;
use App\Domains\Campaign\Actions\RejectCampaign;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Exceptions\AllocationFailed;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Models\Ad;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Models\CampaignPublication;
use App\Domains\Campaign\Services\CampaignCosting;
use App\Domains\System\Enums\ApprovableAction;
use App\Domains\Wallet\Exceptions\InsufficientFunds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's campaign review queue (spec §21, §25).
 *
 * A reviewer sees what the client submitted and what it will cost, and their
 * decision is what releases the money. Approval and rejection are both gated
 * on the reviewer not being the submitter — separation of duties is the reason
 * this screen exists.
 */
final class CampaignReviewController
{
    public function __construct(private readonly CampaignCosting $costing) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Campaign::class);

        $campaigns = Campaign::query()
            ->withoutGlobalScopes()
            ->with('organization')
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', strtoupper($request->string('status')->toString())),
                fn ($query) => $query->awaitingReview(),
            )
            ->orderBy('submitted_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Campaigns/Index', [
            'campaigns' => $campaigns->through(fn (Campaign $campaign): array => [
                ...$campaign->describe(),
                'client' => $campaign->organization?->name,
                'statusLabel' => $campaign->status->label(),
                'budget' => $campaign->budget()->format(),
                'chargedTotal' => $campaign->chargedTotal()->format(),
                'submittedAt' => $campaign->submitted_at?->toIso8601String(),
                'needsSecondApprover' => $this->needsSecondApprover($campaign),
            ]),
            'filters' => ['status' => $request->string('status')->toString() ?: null],
            'statuses' => array_map(
                static fn (CampaignStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                CampaignStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        Gate::authorize('view', $campaign);

        $campaign->loadMissing([
            'organization',
            'adSets.ads.creative',
            'adSets.ads.identity',
            'adAccount',
            'publications',
        ]);

        return Inertia::render('Admin/Campaigns/Show', [
            'campaign' => [
                ...$campaign->describe(),
                'client' => $campaign->organization?->name,
                'objectiveLabel' => $campaign->objective->label(),
                'statusLabel' => $campaign->status->label(),
                'budget' => $campaign->budget()->format(),
                'budgetTypeLabel' => $campaign->budget_type->label(),
                'committedBudget' => $campaign->committedBudget()->format(),
                'chargedTotal' => $campaign->chargedTotal()->format(),
                'captured' => $campaign->capturedAmount()->format(),
                'reportedSpend' => $campaign->reportedSpend()->format(),
                'startsAt' => $campaign->starts_at?->toIso8601String(),
                'endsAt' => $campaign->ends_at?->toIso8601String(),
                'submittedAt' => $campaign->submitted_at?->toIso8601String(),
                'reviewNotes' => $campaign->review_notes,
                'lastError' => $campaign->last_error,
                'costs' => $this->costing->storedBreakdown($campaign),
                'adAccount' => $campaign->adAccount === null ? null : [
                    'id' => $campaign->adAccount->public_id,
                    'name' => $campaign->adAccount->name,
                    'health' => $campaign->adAccount->health_status->label(),
                ],
                'adSets' => $campaign->adSets
                    ->map(static fn (AdSet $adSet): array => [
                        'id' => $adSet->public_id,
                        'name' => $adSet->name,
                        'status' => $adSet->status->value,
                        'statusLabel' => $adSet->status->label(),
                        'bidStrategy' => $adSet->bid_strategy->label(),
                        'targeting' => $adSet->targeting,
                        'ads' => $adSet->ads
                            ->map(static fn (Ad $ad): array => [
                                'id' => $ad->public_id,
                                'name' => $ad->name,
                                'headline' => $ad->headline,
                                'primaryText' => $ad->primary_text,
                                'destinationUrl' => $ad->destination_url,
                                'creative' => $ad->creative?->name,
                                'creativeId' => $ad->creative?->public_id,
                                'identity' => $ad->identity?->name,
                                'status' => $ad->status->value,
                                'statusLabel' => $ad->status->label(),
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
                // The publishing trail, so an operator can see exactly what
                // was sent and what came back.
                'publications' => $campaign->publications
                    ->map(static fn (CampaignPublication $publication): array => [
                        ...$publication->describe(),
                        'operationLabel' => $publication->operation->label(),
                        'statusLabel' => $publication->status->label(),
                        'completedAt' => $publication->completed_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ],
            'can' => [
                'approve' => Gate::allows('approve', $campaign),
                'reject' => Gate::allows('reject', $campaign),
                'pause' => Gate::allows('pause', $campaign),
            ],
            'isOwnSubmission' => $campaign->submitted_by === $request->user()?->getKey(),
            'needsSecondApprover' => $this->needsSecondApprover($campaign),
        ]);
    }

    public function approve(Request $request, Campaign $campaign, ApproveCampaign $approve): RedirectResponse
    {
        Gate::authorize('approve', $campaign);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $approve->handle($campaign, $request->user(), $validated['notes'] ?? null);
        } catch (InsufficientFunds) {
            return back()->with(
                'error',
                'The client\'s balance no longer covers this campaign. Nothing has been held.',
            );
        } catch (AllocationFailed $exception) {
            // The reasons are for the operator; the client sees the softer
            // message if this surfaces to them.
            return back()->with('error', $exception->getMessage());
        } catch (CampaignException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            'Approved. The budget is held and the campaign is being published.',
        );
    }

    public function reject(Request $request, Campaign $campaign, RejectCampaign $reject): RedirectResponse
    {
        Gate::authorize('reject', $campaign);

        $validated = $request->validate([
            // A rejection the client cannot act on is not a rejection worth
            // sending, so the reason is required.
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'allow_changes' => ['sometimes', 'boolean'],
        ]);

        try {
            if ($request->boolean('allow_changes')) {
                $reject->requestChanges($campaign, $request->user(), $validated['reason']);
            } else {
                $reject->reject($campaign, $request->user(), $validated['reason']);
            }
        } catch (CampaignException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The client has been told.');
    }

    public function pause(Request $request, Campaign $campaign, ControlCampaign $control): RedirectResponse
    {
        Gate::authorize('pause', $campaign);

        try {
            $control->pause($campaign, $request->user());
        } catch (ProviderUnavailable $exception) {
            return back()->with('error', $exception->clientMessage);
        } catch (CampaignException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Campaign paused.');
    }

    /**
     * Whether this budget is large enough that one approver should not be able
     * to sign it off alone (spec §25).
     */
    private function needsSecondApprover(Campaign $campaign): bool
    {
        return ApprovableAction::CampaignApproval->requiredApprovals($campaign->charged_total) > 1;
    }
}
