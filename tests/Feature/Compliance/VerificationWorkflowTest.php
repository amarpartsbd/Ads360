<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Compliance\Actions\AttachVerificationDocument;
use App\Domains\Compliance\Actions\ReviewVerification;
use App\Domains\Compliance\Actions\SubmitVerification;
use App\Domains\Compliance\Enums\DocumentType;
use App\Domains\Compliance\Enums\ReviewDecision;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Exceptions\IncompleteSubmission;
use App\Domains\Compliance\Exceptions\InvalidVerificationTransition;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Compliance\Notifications\VerificationOutcome;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * The verification state machine and its side effects (spec §11).
 */
final class VerificationWorkflowTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Notification::fake();
        $this->seedAccessControl();
    }

    #[Test]
    public function submitting_moves_the_profile_into_the_queue_and_locks_it(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $profile = $this->profileWithRequiredDocuments($organization, $user);

        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        $profile->refresh();

        $this->assertSame(VerificationStatus::Pending, $profile->status);
        $this->assertNotNull($profile->submitted_at);
        $this->assertFalse($profile->isEditableByClient(), 'A queued submission should be locked.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::VerificationSubmitted->value,
            'organization_id' => $organization->getKey(),
        ]);
    }

    #[Test]
    public function a_pending_organization_moves_under_review_on_submission(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace(
            organizationState: OrganizationStatus::Pending,
        );

        $this->profileWithRequiredDocuments($organization, $user);

        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        $this->assertSame(OrganizationStatus::UnderReview, $organization->fresh()?->status);
    }

    #[Test]
    public function submitting_without_the_required_documents_is_refused(): void
    {
        ['organization' => $organization] = $this->workspace();

        $this->createVerificationProfile($organization);

        $this->expectException(IncompleteSubmission::class);

        app(SubmitVerification::class)->handle($organization, $this->declaredFields());
    }

    #[Test]
    public function either_a_national_id_or_a_passport_satisfies_the_identity_requirement(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $profile = $this->createVerificationProfile($organization);
        $this->attach($profile, $user, DocumentType::TradeLicense);
        $this->attach($profile, $user, DocumentType::Passport);

        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        $this->assertSame(VerificationStatus::Pending, $profile->fresh()?->status);
    }

    #[Test]
    public function approving_verifies_the_business_and_activates_the_organization(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace(
            organizationState: OrganizationStatus::Pending,
        );

        $profile = $this->profileWithRequiredDocuments($organization, $user);
        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        $reviewer = $this->createPlatformUser('compliance-admin');

        app(ReviewVerification::class)->handle(
            profile: $profile->fresh(),
            reviewer: $reviewer,
            decision: ReviewDecision::Approved,
            internalNote: 'Trade licence checked against the registry.',
        );

        $this->assertSame(VerificationStatus::Verified, $profile->fresh()?->status);
        $this->assertSame(OrganizationStatus::Active, $organization->fresh()?->status);
        $this->assertTrue($organization->fresh()->isVerified());

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ClientVerificationApproved->value,
            'actor_id' => $reviewer->getKey(),
        ]);
    }

    #[Test]
    public function requesting_information_returns_control_to_the_client(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $profile = $this->profileWithRequiredDocuments($organization, $user);
        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        app(ReviewVerification::class)->handle(
            profile: $profile->fresh(),
            reviewer: $this->createPlatformUser('compliance-admin'),
            decision: ReviewDecision::InformationRequested,
            clientMessage: 'The trade licence scan is unreadable.',
        );

        $profile->refresh();

        $this->assertSame(VerificationStatus::RequiresInformation, $profile->status);
        $this->assertTrue($profile->isEditableByClient());
        $this->assertSame('The trade licence scan is unreadable.', $profile->client_message);
    }

    #[Test]
    public function a_resubmission_clears_the_previous_outcome(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $profile = $this->profileWithRequiredDocuments($organization, $user);
        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        app(ReviewVerification::class)->handle(
            profile: $profile->fresh(),
            reviewer: $this->createPlatformUser('compliance-admin'),
            decision: ReviewDecision::InformationRequested,
            clientMessage: 'Please upload a clearer copy.',
        );

        app(SubmitVerification::class)->handle($organization->fresh(), $this->declaredFields());

        $profile->refresh();

        $this->assertSame(VerificationStatus::Pending, $profile->status);
        $this->assertNull($profile->client_message, 'A stale reviewer message survived resubmission.');
        $this->assertNull($profile->reviewed_by);
    }

    #[Test]
    public function a_decision_that_refuses_or_defers_must_explain_itself(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $profile = $this->profileWithRequiredDocuments($organization, $user);
        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        $this->expectException(InvalidArgumentException::class);

        app(ReviewVerification::class)->handle(
            profile: $profile->fresh(),
            reviewer: $this->createPlatformUser('compliance-admin'),
            decision: ReviewDecision::Rejected,
            clientMessage: '   ',
        );
    }

    #[Test]
    public function an_illegal_transition_is_refused(): void
    {
        ['organization' => $organization] = $this->workspace();

        // Nothing has been submitted, so there is nothing to approve.
        $profile = $this->createVerificationProfile($organization);

        $this->expectException(InvalidVerificationTransition::class);

        app(ReviewVerification::class)->handle(
            profile: $profile,
            reviewer: $this->createPlatformUser('compliance-admin'),
            decision: ReviewDecision::Approved,
        );
    }

    #[Test]
    public function suspending_a_verified_business_restricts_the_organization(): void
    {
        ['organization' => $organization] = $this->workspace();

        $profile = $this->createVerificationProfile($organization, VerificationStatus::Verified);

        app(ReviewVerification::class)->handle(
            profile: $profile,
            reviewer: $this->createPlatformUser('super-admin'),
            decision: ReviewDecision::Suspended,
            clientMessage: 'Verification withdrawn pending further checks.',
        );

        $this->assertSame(VerificationStatus::Suspended, $profile->fresh()?->status);
        $this->assertSame(OrganizationStatus::Suspended, $organization->fresh()?->status);
    }

    #[Test]
    public function every_decision_is_recorded_in_an_immutable_history(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $profile = $this->profileWithRequiredDocuments($organization, $user);
        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        $reviewer = $this->createPlatformUser('compliance-admin');

        app(ReviewVerification::class)->handle(
            profile: $profile->fresh(),
            reviewer: $reviewer,
            decision: ReviewDecision::Claimed,
        );

        app(ReviewVerification::class)->handle(
            profile: $profile->fresh(),
            reviewer: $reviewer,
            decision: ReviewDecision::Approved,
            internalNote: 'Verified against the public registry.',
        );

        $reviews = $profile->reviews()->orderBy('created_at')->get();

        $this->assertCount(2, $reviews);
        $this->assertSame(ReviewDecision::Claimed, $reviews[0]->decision);
        $this->assertSame(VerificationStatus::Pending, $reviews[0]->from_status);
        $this->assertSame(VerificationStatus::UnderReview, $reviews[0]->to_status);

        $this->expectException(\RuntimeException::class);
        $reviews[0]->update(['internal_note' => 'tampered']);
    }

    #[Test]
    public function the_client_is_notified_of_a_decision_but_not_of_a_reviewer_claiming_it(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $profile = $this->profileWithRequiredDocuments($organization, $user);
        app(SubmitVerification::class)->handle($organization, $this->declaredFields());

        $reviewer = $this->createPlatformUser('compliance-admin');

        app(ReviewVerification::class)->handle(
            profile: $profile->fresh(),
            reviewer: $reviewer,
            decision: ReviewDecision::Claimed,
        );

        Notification::assertNothingSent();

        app(ReviewVerification::class)->handle(
            profile: $profile->fresh(),
            reviewer: $reviewer,
            decision: ReviewDecision::Approved,
        );

        Notification::assertSentTo($user, VerificationOutcome::class);
    }

    #[Test]
    public function the_database_refuses_an_incomplete_submission_even_if_code_tries(): void
    {
        ['organization' => $organization] = $this->workspace();

        $profile = $this->createVerificationProfile($organization);

        // Bypasses every application-level guard on purpose: the point is that
        // the constraint holds regardless of what code does (spec §59).
        $this->expectException(\Illuminate\Database\QueryException::class);

        $profile->forceFill([
            'legal_business_name' => null,
            'status' => VerificationStatus::Pending,
            'submitted_at' => now(),
        ])->save();
    }

    /**
     * @return array{tenant: \App\Domains\Tenant\Models\Tenant, organization: Organization, user: User}
     */
    private function workspace(OrganizationStatus $organizationState = OrganizationStatus::Active): array
    {
        $workspace = $this->createWorkspace('client-owner');

        $workspace['organization']->forceFill(['status' => $organizationState])->save();

        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function profileWithRequiredDocuments(Organization $organization, User $user): VerificationProfile
    {
        $profile = $this->createVerificationProfile($organization);

        $this->attach($profile, $user, DocumentType::TradeLicense);
        $this->attach($profile, $user, DocumentType::NationalId);

        return $profile;
    }

    private function attach(VerificationProfile $profile, User $user, DocumentType $type): void
    {
        app(AttachVerificationDocument::class)->handle(
            $profile,
            UploadedFile::fake()->createWithContent(strtolower($type->value).'.pdf', '%PDF-1.4 evidence'),
            $type,
            $user,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function declaredFields(): array
    {
        return [
            'legal_business_name' => 'Declared Business Ltd',
            'business_type' => 'Retail',
            'contact_number' => '+8801700000000',
            'business_email' => 'business@example.test',
            'address_line_1' => '1 Example Road',
            'city' => 'Dhaka',
            'country' => 'BD',
            'authorized_person_name' => 'Authorised Person',
            'authorized_person_designation' => 'Director',
            'authorized_person_email' => 'director@example.test',
            'authorized_person_phone' => '+8801700000001',
        ];
    }
}
