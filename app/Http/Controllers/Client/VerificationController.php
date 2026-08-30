<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Client\Enums\DocumentMediaType;
use App\Domains\Client\Exceptions\RejectedUpload;
use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Compliance\Actions\AttachVerificationDocument;
use App\Domains\Compliance\Actions\RemoveVerificationDocument;
use App\Domains\Compliance\Actions\SubmitVerification;
use App\Domains\Compliance\Enums\DocumentType;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Exceptions\IncompleteSubmission;
use App\Domains\Compliance\Exceptions\InvalidVerificationTransition;
use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use App\Http\Requests\Client\SubmitVerificationRequest;
use App\Http\Requests\Client\UploadVerificationDocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business verification, from the client's side (spec §11).
 */
final class VerificationController
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): Response
    {
        $organization = $this->context->requireOrganization();
        $profile = $this->profileFor($organization);

        Gate::authorize('view', $profile);

        $profile->loadMissing('documents');

        return Inertia::render('Client/Verification/Show', [
            'profile' => $this->serialiseProfile($profile),
            'documents' => $profile->documents
                ->map(fn (VerificationDocument $document): array => $this->serialiseDocument($document))
                ->values()
                ->all(),
            'missingDocuments' => array_map(
                static fn (DocumentType $type): array => ['value' => $type->value, 'label' => $type->label()],
                $profile->missingRequiredDocuments(),
            ),
            'documentTypes' => array_map(
                static fn (DocumentType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'required' => $type->isRequired(),
                ],
                DocumentType::cases(),
            ),
            'upload' => [
                'maxBytes' => DocumentStorage::MAX_BYTES,
                'acceptedExtensions' => DocumentMediaType::allowedExtensions(),
            ],
            'can' => [
                'update' => Gate::allows('update', $profile),
            ],
        ]);
    }

    public function update(SubmitVerificationRequest $request): RedirectResponse
    {
        $organization = $this->context->requireOrganization();
        $profile = $this->profileFor($organization);

        Gate::authorize('submit', $profile);

        try {
            app(SubmitVerification::class)->handle(
                $organization,
                $request->attributesForProfile($organization->default_currency),
            );
        } catch (IncompleteSubmission $exception) {
            throw ValidationException::withMessages(['documents' => $exception->getMessage()]);
        } catch (InvalidVerificationTransition) {
            // The profile moved on between the page loading and the submission
            // arriving — a reviewer picked it up, most likely.
            throw ValidationException::withMessages([
                'documents' => 'This submission can no longer be edited. Refresh the page to see its current status.',
            ]);
        }

        return redirect()
            ->route('client.verification.show')
            ->with('success', 'Your business details have been submitted for review.');
    }

    public function storeDocument(UploadVerificationDocumentRequest $request): RedirectResponse
    {
        $organization = $this->context->requireOrganization();
        $profile = $this->profileFor($organization);

        Gate::authorize('update', $profile);

        if (! $profile->exists) {
            // Documents attach to a profile, so a first upload creates the
            // draft the client is building up.
            $profile->save();
        }

        /** @var User $user */
        $user = $request->user();

        try {
            app(AttachVerificationDocument::class)->handle(
                $profile,
                $request->file('file'),
                $request->documentType(),
                $user,
            );
        } catch (RejectedUpload $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return back()->with('success', 'Document uploaded.');
    }

    public function destroyDocument(Request $request, VerificationDocument $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        /** @var User $user */
        $user = $request->user();

        app(RemoveVerificationDocument::class)->handle($document, $user);

        return back()->with('success', 'Document removed.');
    }

    /**
     * The organization's profile, or an unsaved one so the form has something
     * to render on a first visit.
     */
    private function profileFor(Organization $organization): VerificationProfile
    {
        $profile = VerificationProfile::query()
            ->where('organization_id', $organization->getKey())
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        $profile = new VerificationProfile([
            // Pre-filled from what the client already told us at registration.
            'legal_business_name' => $organization->legal_name ?? $organization->name,
            'trading_name' => $organization->name,
            'business_type' => $organization->business_type,
            'website' => $organization->website,
            'business_email' => $organization->contact_email,
            'contact_number' => $organization->contact_number,
            'country' => $organization->country,
        ]);

        $profile->organization_id = $organization->getKey();
        $profile->tenant_id = $organization->tenant_id;
        $profile->status = VerificationStatus::NotSubmitted;

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseProfile(VerificationProfile $profile): array
    {
        return [
            'status' => $profile->status->value,
            'statusLabel' => $profile->status->label(),
            'statusDescription' => $profile->status->description(),
            'editable' => $profile->isEditableByClient(),
            'submittedAt' => $profile->submitted_at?->toIso8601String(),
            'reviewedAt' => $profile->reviewed_at?->toIso8601String(),
            // Only the reviewer's client-facing message. Internal notes stay in
            // the review history and never reach this response (spec §54).
            'reviewerMessage' => $profile->client_message,
            'fields' => [
                'legal_business_name' => $profile->legal_business_name,
                'trading_name' => $profile->trading_name,
                'business_type' => $profile->business_type,
                'website' => $profile->website,
                'facebook_page' => $profile->facebook_page,
                'contact_number' => $profile->contact_number,
                'business_email' => $profile->business_email,
                'address_line_1' => $profile->address_line_1,
                'address_line_2' => $profile->address_line_2,
                'city' => $profile->city,
                'state' => $profile->state,
                'postal_code' => $profile->postal_code,
                'country' => $profile->country,
                'authorized_person_name' => $profile->authorized_person_name,
                'authorized_person_designation' => $profile->authorized_person_designation,
                'authorized_person_email' => $profile->authorized_person_email,
                'authorized_person_phone' => $profile->authorized_person_phone,
                'trade_license_number' => $profile->trade_license_number,
                'tin' => $profile->tin,
                'bin_vat_number' => $profile->bin_vat_number,
                'expected_monthly_spend' => $profile->expectedMonthlySpend()?->toDecimal(),
                'advertising_category' => $profile->advertising_category,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseDocument(VerificationDocument $document): array
    {
        return [
            'id' => $document->public_id,
            'type' => $document->type->value,
            'typeLabel' => $document->type->label(),
            'filename' => $document->original_filename,
            'size' => $document->humanSize(),
            'status' => $document->status->value,
            'statusLabel' => $document->status->label(),
            'reviewNote' => $document->review_note,
            'uploadedAt' => $document->created_at?->toIso8601String(),
            // A route, not a storage path: the bytes are reachable only through
            // an authorization check.
            'downloadUrl' => route('client.verification.documents.download', $document->public_id),
        ];
    }
}
