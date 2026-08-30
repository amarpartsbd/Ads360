<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Compliance\Actions\AttachVerificationDocument;
use App\Domains\Compliance\Enums\DocumentType;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * KYC data is the most sensitive the platform holds (spec §55, §68).
 *
 * These cover the two failures that would matter most: a client verifying
 * themselves, and one tenant reaching another tenant's identity documents.
 */
final class VerificationAccessTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        $this->seedAccessControl();
    }

    // ------------------------------------------------------------------
    // Self-verification
    // ------------------------------------------------------------------

    #[Test]
    public function no_client_role_can_decide_its_own_verification(): void
    {
        foreach (['client-owner', 'client-admin', 'client-marketer', 'client-accountant'] as $roleSlug) {
            $workspace = $this->createWorkspace($roleSlug);
            app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

            $profile = $this->createVerificationProfile(
                $workspace['organization'],
                VerificationStatus::Pending,
            );

            $this->assertFalse(
                $workspace['user']->can('review', $profile),
                "[{$roleSlug}] could review its own verification."
            );
            $this->assertFalse(
                $workspace['user']->hasPermissionTo('clients.verify', $workspace['organization']),
                "[{$roleSlug}] holds clients.verify."
            );
        }
    }

    #[Test]
    public function an_agency_owner_cannot_verify_their_own_client(): void
    {
        $workspace = $this->createWorkspace('agency-owner');
        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        $profile = $this->createVerificationProfile(
            $workspace['organization'],
            VerificationStatus::Pending,
        );

        $this->assertFalse($workspace['user']->can('review', $profile));
    }

    #[Test]
    public function posting_a_review_as_a_client_is_refused(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $profile = $this->createVerificationProfile(
            $workspace['organization'],
            VerificationStatus::Pending,
        );

        $this->actingAs($workspace['user'])
            ->post(route('admin.verification.review', $profile->public_id), [
                'decision' => 'APPROVED',
            ])
            ->assertForbidden();

        $this->assertSame(VerificationStatus::Pending, $profile->fresh()?->status);
    }

    #[Test]
    public function a_submitted_profile_can_no_longer_be_edited_by_the_client(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        $profile = $this->createVerificationProfile(
            $workspace['organization'],
            VerificationStatus::Pending,
        );

        $this->assertFalse(
            $workspace['user']->can('update', $profile),
            'A client could edit a submission already with the review team.'
        );
    }

    #[Test]
    public function platform_staff_do_not_fill_in_a_clients_declaration(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $profile = $this->createVerificationProfile($workspace['organization']);
        $admin = $this->createPlatformUser('super-admin');

        // The declaration must be the client's own statement.
        $this->assertFalse($admin->can('update', $profile));
        $this->assertTrue($admin->can('review', $profile));
    }

    // ------------------------------------------------------------------
    // Cross-tenant document access
    // ------------------------------------------------------------------

    #[Test]
    public function a_client_cannot_download_another_tenants_document(): void
    {
        $alpha = $this->createWorkspace('client-owner');
        $beta = $this->createWorkspace('client-owner');

        $betaDocument = $this->documentFor($beta);

        $this->actingAs($alpha['user'])
            ->get(route('client.verification.documents.download', $betaDocument->public_id))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_delete_another_tenants_document(): void
    {
        $alpha = $this->createWorkspace('client-owner');
        $beta = $this->createWorkspace('client-owner');

        $betaDocument = $this->documentFor($beta);

        $this->actingAs($alpha['user'])
            ->delete(route('client.verification.documents.destroy', $betaDocument->public_id))
            ->assertForbidden();

        $this->assertNotSoftDeleted($betaDocument);
    }

    #[Test]
    public function the_global_scope_hides_another_tenants_verification_profile(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        $this->createVerificationProfile($alpha['organization']);
        $betaProfile = $this->createVerificationProfile($beta['organization']);

        app(TenantContext::class)->for($alpha['tenant'], $alpha['organization']);

        $this->assertNull(
            VerificationProfile::query()->find($betaProfile->getKey()),
            'A tenant-scoped query reached another tenant\'s verification profile.'
        );
        $this->assertSame(1, VerificationProfile::query()->count());
    }

    #[Test]
    public function a_client_can_download_their_own_document(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $document = $this->documentFor($workspace);

        $this->actingAs($workspace['user'])
            ->get(route('client.verification.documents.download', $document->public_id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function every_document_read_is_audited(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $document = $this->documentFor($workspace);

        $this->actingAs($workspace['user'])
            ->get(route('client.verification.documents.download', $document->public_id))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::VerificationDocumentDownloaded->value,
            'actor_id' => $workspace['user']->getKey(),
            'resource_id' => (string) $document->getKey(),
        ]);
    }

    #[Test]
    public function platform_staff_may_read_a_clients_documents_for_review(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $document = $this->documentFor($workspace);
        $reviewer = $this->createPlatformUser('compliance-admin');

        $this->actingAs($reviewer)
            ->get(route('admin.verification.documents.download', $document->public_id))
            ->assertOk();
    }

    #[Test]
    public function platform_staff_cannot_delete_a_clients_evidence(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $document = $this->documentFor($workspace);
        $admin = $this->createPlatformUser('super-admin');

        $this->assertFalse($admin->can('delete', $document));
    }

    #[Test]
    public function a_document_never_serialises_its_storage_location(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $document = $this->documentFor($workspace);

        $payload = json_encode($document->toArray(), JSON_THROW_ON_ERROR);

        foreach (['path', 'disk', 'checksum'] as $hidden) {
            $this->assertStringNotContainsString(
                $hidden,
                $payload,
                "A document exposed [{$hidden}] through serialisation."
            );
        }
    }

    #[Test]
    public function internal_reviewer_notes_never_reach_the_client(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        $profile = $this->createVerificationProfile(
            $workspace['organization'],
            VerificationStatus::RequiresInformation,
        );

        // tenant_id is stamped by the BelongsToTenant trait from the bound
        // context, so it is deliberately not mass assignable.
        $profile->reviews()->create([
            'organization_id' => $profile->organization_id,
            'reviewer_id' => $this->createPlatformUser('compliance-admin')->getKey(),
            'decision' => 'INFORMATION_REQUESTED',
            'from_status' => VerificationStatus::Pending,
            'to_status' => VerificationStatus::RequiresInformation,
            'internal_note' => 'SECRET-INTERNAL-SUSPICION',
            'client_message' => 'Please upload a clearer trade licence.',
        ]);

        $response = $this->actingAs($workspace['user'])
            ->get(route('client.verification.show'))
            ->assertOk();

        $this->assertStringNotContainsString(
            'SECRET-INTERNAL-SUSPICION',
            $response->getContent() ?: '',
            'An internal compliance note leaked into a client-facing response.'
        );
    }

    /**
     * @param  array{tenant: \App\Domains\Tenant\Models\Tenant, organization: Organization, user: User}  $workspace
     */
    private function documentFor(array $workspace): VerificationDocument
    {
        return app(TenantContext::class)->runFor(
            $workspace['tenant'],
            $workspace['organization'],
            function () use ($workspace): VerificationDocument {
                $profile = $this->createVerificationProfile($workspace['organization']);

                return app(AttachVerificationDocument::class)->handle(
                    $profile,
                    UploadedFile::fake()->createWithContent('licence.pdf', '%PDF-1.4 evidence'),
                    DocumentType::TradeLicense,
                    $workspace['user'],
                );
            },
        );
    }
}
