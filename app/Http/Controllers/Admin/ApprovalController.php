<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Identity\Models\User;
use App\Domains\System\Enums\ApprovableAction;
use App\Domains\System\Enums\ApprovalStatus;
use App\Domains\System\Models\ApprovalDecision;
use App\Domains\System\Models\ApprovalRequest;
use App\Domains\System\Services\ApprovalService;
use App\Domains\Wallet\Actions\AdjustWallet;
use App\Domains\Wallet\Actions\RefundToClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The maker-checker queue (spec §25).
 *
 * Approving here does not merely record a vote: once the last required approval
 * lands, the recorded payload is executed. That matters — an approval that
 * leaves someone to go and perform the action by hand is not the same control.
 */
final class ApprovalController
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ApprovalRequest::class);

        /** @var User $user */
        $user = $request->user();

        $status = $request->query('status');

        $requests = ApprovalRequest::query()
            ->with(['requester:id,name,email', 'organization:id,name', 'decisions.approver:id,name'])
            ->when(
                is_string($status) && $status !== '',
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereIn('status', [
                    ApprovalStatus::Pending->value,
                    ApprovalStatus::Approved->value,
                ]),
            )
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ApprovalRequest $item): array => [
                'id' => $item->public_id,
                'action' => $item->action_type->value,
                'actionLabel' => $item->action_type->label(),
                'summary' => $item->summary,
                'amount' => $item->amountMoney()?->format(),
                'organization' => $item->organization?->name,
                'status' => $item->status->value,
                'statusLabel' => $item->status->label(),
                'requestedBy' => $item->requester->name,
                'reason' => $item->request_reason,
                'required' => $item->required_approvals,
                'received' => $item->approvals_received,
                'decisions' => $item->decisions->map(fn (ApprovalDecision $decision): array => [
                    'approver' => $decision->approver?->name ?? 'Unknown',
                    'decision' => $decision->decision,
                    'note' => $decision->note,
                    'at' => $decision->created_at?->toIso8601String(),
                ])->values()->all(),
                // Whether *this* viewer may vote. The requester never can, and
                // the interface says so rather than letting them try.
                'canDecide' => Gate::allows('decide', $item),
                'isOwnRequest' => $item->requested_by === $user->getKey(),
                'createdAt' => $item->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Finance/Approvals', [
            'requests' => $requests,
            'filters' => ['status' => $status],
            'statuses' => array_map(
                static fn (ApprovalStatus $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                ],
                ApprovalStatus::cases(),
            ),
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        Gate::authorize('decide', $approvalRequest);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $approver */
        $approver = $request->user();

        $decided = $this->approvals->approve($approvalRequest, $approver, $validated['note'] ?? null);

        if ($decided->status !== ApprovalStatus::Approved) {
            return back()->with(
                'success',
                "Approval recorded. {$decided->outstandingApprovals()} more still needed.",
            );
        }

        $this->execute($decided, $approver);

        return back()->with('success', 'Approved and executed.');
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        Gate::authorize('decide', $approvalRequest);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Record why this request is being refused.',
        ]);

        /** @var User $approver */
        $approver = $request->user();

        $this->approvals->reject($approvalRequest, $approver, $validated['reason']);

        return back()->with('success', 'Request rejected.');
    }

    /**
     * Carry out what was approved, from the payload recorded when it was
     * requested — never from anything re-submitted with the approval.
     */
    private function execute(ApprovalRequest $request, User $executor): void
    {
        match ($request->action_type) {
            ApprovableAction::WalletAdjustment => app(AdjustWallet::class)
                ->executeApproved($request, $executor),
            ApprovableAction::Refund => app(RefundToClient::class)
                ->executeApproved($request, $executor),
            // Rate changes are recorded and applied by the finance screen; the
            // request is closed so the queue does not hold it open.
            ApprovableAction::ExchangeRateChange => $this->approvals->markExecuted($request),
        };
    }
}
