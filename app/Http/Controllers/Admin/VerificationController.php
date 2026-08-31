<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Compliance\Actions\ReviewVerification;
use App\Domains\Compliance\Enums\ReviewDecision;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Exceptions\InvalidVerificationTransition;
use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Compliance\Models\VerificationReview;
use App\Domains\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The compliance review queue (spec §41 Compliance).
 *
 * This is the only surface where internal reviewer notes are exposed, and it is
 * reachable only by platform staff holding `clients.verify`.
 */
final class VerificationController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', VerificationProfile::class);

        $status = $request->query('status');

        $profiles = VerificationProfile::acrossTenants()
            ->with(['organization:id,public_id,name,tenant_id', 'organization.tenant:id,name,type'])
            ->when(
                is_string($status) && $status !== '',
                fn ($query) => $query->where('status', $status),
                // The default view is the work queue: everything waiting on a
                // reviewer, oldest submission first.
                fn ($query) => $query->whereIn('status', [
                    VerificationStatus::Pending->value,
                    VerificationStatus::UnderReview->value,
                    VerificationStatus::RequiresInformation->value,
                ]),
            )
            ->orderByRaw('submitted_at asc nulls last')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (VerificationProfile $profile): array => [
                'id' => $profile->public_id,
                'organization' => $profile->organization->name,
                'tenant' => $profile->organization->tenant->name,
                'legalName' => $profile->legal_business_name,
                'status' => $profile->status->value,
                'statusLabel' => $profile->status->label(),
                'submittedAt' => $profile->submitted_at?->toIso8601String(),
                'waitingDays' => $profile->submitted_at?->diffInDays(now()),
            ]);

        return Inertia::render('Admin/Verification/Index', [
            'profiles' => $profiles,
            'filters' => ['status' => $status],
            'statuses' => array_map(
                static fn (VerificationStatus $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                ],
                VerificationStatus::cases(),
            ),
            'counts' => $this->queueCounts(),
        ]);
    }

    public function show(VerificationProfile $profile): Response
    {
        Gate::authorize('view', $profile);

        $profile->load([
            'organization:id,public_id,name,tenant_id,status,country,contact_email',
            'organization.tenant:id,name,type',
            'documents',
            'reviews' => fn ($query) => $query->latest('created_at'),
            'reviews.reviewer:id,name,email',
        ]);

        return Inertia::render('Admin/Verification/Show', [
            'profile' => [
                'id' => $profile->public_id,
                'status' => $profile->status->value,
                'statusLabel' => $profile->status->label(),
                'submittedAt' => $profile->submitted_at?->toIso8601String(),
                'reviewedAt' => $profile->reviewed_at?->toIso8601String(),
                'clientMessage' => $profile->client_message,
                'organization' => [
                    'id' => $profile->organization->public_id,
                    'name' => $profile->organization->name,
                    'status' => $profile->organization->status->value,
                    'tenant' => $profile->organization->tenant->name,
                    'tenantType' => $profile->organization->tenant->type->value,
                ],
                'business' => [
                    'legalName' => $profile->legal_business_name,
                    'tradingName' => $profile->trading_name,
                    'type' => $profile->business_type,
                    'website' => $profile->website,
                    'facebookPage' => $profile->facebook_page,
                    'contactNumber' => $profile->contact_number,
                    'businessEmail' => $profile->business_email,
                    'address' => array_values(array_filter([
                        $profile->address_line_1,
                        $profile->address_line_2,
                        $profile->city,
                        $profile->state,
                        $profile->postal_code,
                        $profile->country,
                    ])),
                    'tradeLicenseNumber' => $profile->trade_license_number,
                    'tin' => $profile->tin,
                    'binVatNumber' => $profile->bin_vat_number,
                    'expectedMonthlySpend' => $profile->expectedMonthlySpend()?->format(),
                    'advertisingCategory' => $profile->advertising_category,
                ],
                'authorizedPerson' => [
                    'name' => $profile->authorized_person_name,
                    'designation' => $profile->authorized_person_designation,
                    'email' => $profile->authorized_person_email,
                    'phone' => $profile->authorized_person_phone,
                ],
            ],
            'documents' => $profile->documents
                ->map(fn (VerificationDocument $document): array => [
                    'id' => $document->public_id,
                    'type' => $document->type->value,
                    'typeLabel' => $document->type->label(),
                    'filename' => $document->original_filename,
                    'size' => $document->humanSize(),
                    'isImage' => $document->isImage(),
                    'status' => $document->status->value,
                    'statusLabel' => $document->status->label(),
                    'reviewNote' => $document->review_note,
                    'uploadedAt' => $document->created_at?->toIso8601String(),
                    'downloadUrl' => route('admin.verification.documents.download', $document->public_id),
                ])
                ->values()
                ->all(),
            // Internal notes appear here and nowhere else.
            'history' => $profile->reviews
                ->map(fn (VerificationReview $review): array => [
                    'id' => $review->public_id,
                    'decision' => $review->decision->value,
                    'decisionLabel' => $review->decision->label(),
                    'fromStatus' => $review->from_status->label(),
                    'toStatus' => $review->to_status->label(),
                    'reviewer' => $review->reviewer->name ?? 'System',
                    'internalNote' => $review->internal_note,
                    'clientMessage' => $review->client_message,
                    'at' => $review->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'availableDecisions' => $this->availableDecisions($profile),
            'can' => [
                'review' => Gate::allows('review', $profile),
                'suspend' => Gate::allows('suspend', $profile),
            ],
        ]);
    }

    public function review(Request $request, VerificationProfile $profile): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'client_message' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['string', 'size:26'],
        ]);

        $decision = ReviewDecision::from($validated['decision']);

        // Suspension is a separate, more privileged permission than an ordinary
        // review decision.
        Gate::authorize(
            $decision === ReviewDecision::Suspended ? 'suspend' : 'review',
            $profile,
        );

        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            app(ReviewVerification::class)->handle(
                profile: $profile,
                reviewer: $reviewer,
                decision: $decision,
                clientMessage: $validated['client_message'] ?? null,
                internalNote: $validated['internal_note'] ?? null,
                referencedDocuments: $validated['documents'] ?? [],
            );
        } catch (InvalidVerificationTransition $exception) {
            throw ValidationException::withMessages(['decision' => $exception->getMessage()]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['client_message' => $exception->getMessage()]);
        }

        return back()->with('success', $decision->label().' recorded.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableDecisions(VerificationProfile $profile): array
    {
        $available = [];

        foreach (ReviewDecision::cases() as $decision) {
            if (! $profile->status->canTransitionTo($decision->resultingStatus())) {
                continue;
            }

            $available[] = [
                'value' => $decision->value,
                'label' => $decision->label(),
                'requiresMessage' => $decision->requiresClientMessage(),
            ];
        }

        return $available;
    }

    /**
     * @return array<string, int>
     */
    private function queueCounts(): array
    {
        $counts = VerificationProfile::acrossTenants()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'pending' => (int) ($counts[VerificationStatus::Pending->value] ?? 0),
            'underReview' => (int) ($counts[VerificationStatus::UnderReview->value] ?? 0),
            'requiresInformation' => (int) ($counts[VerificationStatus::RequiresInformation->value] ?? 0),
            'verified' => (int) ($counts[VerificationStatus::Verified->value] ?? 0),
            'rejected' => (int) ($counts[VerificationStatus::Rejected->value] ?? 0),
        ];
    }
}
