<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Compliance\Models\VerificationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * The verification flow as a client and a reviewer actually drive it.
 */
final class VerificationHttpTest extends TestCase
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
    public function the_verification_page_renders_for_a_client_with_no_profile_yet(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        $this->actingAs($workspace['user'])
            ->get(route('client.verification.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Verification/Show')
                ->where('profile.status', VerificationStatus::NotSubmitted->value)
                ->where('profile.editable', true));
    }

    #[Test]
    public function a_client_uploads_a_document_and_submits(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        foreach (['TRADE_LICENSE', 'NATIONAL_ID'] as $type) {
            $this->actingAs($workspace['user'])
                ->post(route('client.verification.documents.store'), [
                    'type' => $type,
                    'file' => UploadedFile::fake()->createWithContent(
                        strtolower($type).'.pdf',
                        '%PDF-1.4 evidence',
                    ),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, VerificationDocument::acrossTenants()->count());

        $this->actingAs($workspace['user'])
            ->put(route('client.verification.update'), $this->payload())
            ->assertRedirect(route('client.verification.show'))
            ->assertSessionHasNoErrors();

        $profile = VerificationProfile::acrossTenants()->firstOrFail();

        $this->assertSame(VerificationStatus::Pending, $profile->status);
        $this->assertSame('Declared Business Ltd', $profile->legal_business_name);
    }

    #[Test]
    public function submitting_without_documents_reports_what_is_missing(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        $this->actingAs($workspace['user'])
            ->put(route('client.verification.update'), $this->payload())
            ->assertSessionHasErrors('documents');

        $this->assertSame(0, VerificationProfile::acrossTenants()->where('status', 'PENDING')->count());
    }

    #[Test]
    public function a_disguised_upload_is_rejected_with_a_readable_message(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        $response = $this->actingAs($workspace['user'])
            ->post(route('client.verification.documents.store'), [
                'type' => 'TRADE_LICENSE',
                'file' => UploadedFile::fake()->createWithContent(
                    'licence.pdf',
                    "<?php system(\$_GET['cmd']); ?>",
                ),
            ]);

        $response->assertSessionHasErrors('file');

        $this->assertSame(0, VerificationDocument::acrossTenants()->count());
        $this->assertEmpty(Storage::disk('documents')->allFiles());
    }

    #[Test]
    public function expected_monthly_spend_is_converted_to_minor_units_server_side(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $this->uploadRequiredDocuments($workspace['user']);

        $this->actingAs($workspace['user'])
            ->put(route('client.verification.update'), [
                ...$this->payload(),
                'expected_monthly_spend' => '50000.75',
            ])
            ->assertSessionHasNoErrors();

        $profile = VerificationProfile::acrossTenants()->firstOrFail();

        $this->assertSame(5_000_075, $profile->expected_monthly_spend_minor);
        $this->assertSame('BDT', $profile->expected_monthly_spend_currency);
        $this->assertSame('50000.75', $profile->expectedMonthlySpend()?->toDecimal());
    }

    #[Test]
    public function a_reviewer_sees_the_queue_and_can_approve(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $this->uploadRequiredDocuments($workspace['user']);

        $this->actingAs($workspace['user'])
            ->put(route('client.verification.update'), $this->payload())
            ->assertSessionHasNoErrors();

        $reviewer = $this->createPlatformUser('compliance-admin');
        $profile = VerificationProfile::acrossTenants()->firstOrFail();

        $this->actingAs($reviewer)
            ->get(route('admin.verification.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('profiles.total', 1));

        $this->actingAs($reviewer)
            ->get(route('admin.verification.show', $profile->public_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.review', true));

        $this->actingAs($reviewer)
            ->post(route('admin.verification.review', $profile->public_id), [
                'decision' => 'APPROVED',
                'internal_note' => 'Checked against the registry.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(VerificationStatus::Verified, $profile->fresh()?->status);
    }

    #[Test]
    public function a_reviewer_without_suspend_permission_cannot_suspend(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $profile = $this->createVerificationProfile(
            $workspace['organization'],
            VerificationStatus::Verified,
        );

        // Campaign managers may not review at all, let alone suspend.
        $manager = $this->createPlatformUser('campaign-manager');

        $this->actingAs($manager)
            ->post(route('admin.verification.review', $profile->public_id), [
                'decision' => 'SUSPENDED',
                'client_message' => 'Suspended.',
            ])
            ->assertForbidden();

        $this->assertSame(VerificationStatus::Verified, $profile->fresh()?->status);
    }

    #[Test]
    public function a_rejection_without_an_explanation_is_refused(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $profile = $this->createVerificationProfile(
            $workspace['organization'],
            VerificationStatus::Pending,
        );

        $this->actingAs($this->createPlatformUser('compliance-admin'))
            ->post(route('admin.verification.review', $profile->public_id), [
                'decision' => 'REJECTED',
            ])
            ->assertSessionHasErrors('client_message');

        $this->assertSame(VerificationStatus::Pending, $profile->fresh()?->status);
    }

    private function uploadRequiredDocuments(\App\Domains\Identity\Models\User $user): void
    {
        foreach (['TRADE_LICENSE', 'NATIONAL_ID'] as $type) {
            $this->actingAs($user)->post(route('client.verification.documents.store'), [
                'type' => $type,
                'file' => UploadedFile::fake()->createWithContent(
                    strtolower($type).'.pdf',
                    '%PDF-1.4 evidence',
                ),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
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
