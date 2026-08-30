<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Enums\ActorType;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Audit\Services\SecretRedactor;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

final class AuditRecorderTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function it_attributes_an_entry_to_the_authenticated_actor_and_their_tenant(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user']);
        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        $entry = app(AuditRecorder::class)->record(
            action: AuditAction::OrganizationUpdated,
            resource: $workspace['organization'],
        );

        $this->assertSame($workspace['user']->getKey(), $entry->actor_id);
        $this->assertSame(ActorType::User, $entry->actor_type);
        $this->assertSame($workspace['tenant']->getKey(), $entry->tenant_id);
        $this->assertSame($workspace['organization']->getKey(), $entry->organization_id);
        $this->assertSame((string) $workspace['organization']->getKey(), $entry->resource_id);
    }

    #[Test]
    public function it_redacts_secrets_from_every_payload_column(): void
    {
        $workspace = $this->createWorkspace();
        $this->actingAs($workspace['user']);

        $entry = app(AuditRecorder::class)->record(
            action: AuditAction::PasswordChanged,
            resource: $workspace['user'],
            before: ['password' => 'old-secret-value'],
            after: ['password' => 'new-secret-value'],
            context: ['api_key' => 'live-key-value', 'reason' => 'user requested'],
        );

        $this->assertSame(SecretRedactor::REDACTED, $entry->before_data['password']);
        $this->assertSame(SecretRedactor::REDACTED, $entry->after_data['password']);
        $this->assertSame(SecretRedactor::REDACTED, $entry->context['api_key']);
        $this->assertSame('user requested', $entry->context['reason']);

        $stored = json_encode($entry->fresh()?->getAttributes(), JSON_THROW_ON_ERROR);

        foreach (['old-secret-value', 'new-secret-value', 'live-key-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, "[{$secret}] reached durable storage.");
        }
    }

    #[Test]
    public function it_records_only_the_attributes_that_changed(): void
    {
        $workspace = $this->createWorkspace();
        $this->actingAs($workspace['user']);

        $organization = $workspace['organization'];
        $originalName = $organization->name;

        // Captured before the write: Eloquent discards the previous values on
        // save, so an after-the-fact diff would report the new value as the old.
        $before = AuditRecorder::snapshot($organization);

        $organization->update(['name' => 'Renamed Workspace']);

        $entry = app(AuditRecorder::class)->recordChange(
            action: AuditAction::OrganizationUpdated,
            resource: $organization,
            before: $before,
        );

        $this->assertSame($originalName, $entry->before_data['name']);
        $this->assertSame('Renamed Workspace', $entry->after_data['name']);
        $this->assertArrayNotHasKey('country', $entry->after_data);
    }

    #[Test]
    public function a_system_event_has_no_actor(): void
    {
        $entry = app(AuditRecorder::class)->recordSystemEvent(
            action: AuditAction::LoginBlocked,
            actorType: ActorType::Job,
            context: ['queue' => 'critical'],
            label: 'ReconciliationJob',
        );

        $this->assertNull($entry->actor_id);
        $this->assertSame(ActorType::Job, $entry->actor_type);
        $this->assertSame('ReconciliationJob', $entry->actor_label);
    }

    #[Test]
    public function entries_carry_the_request_correlation_id(): void
    {
        $workspace = $this->createWorkspace();

        $response = $this->actingAs($workspace['user'])->get(route('client.dashboard'));

        $requestId = $response->headers->get('X-Request-Id');

        $this->assertNotNull($requestId, 'Every response should carry a correlation id.');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]{8,64}$/', $requestId);
    }

    #[Test]
    public function a_forged_correlation_id_is_replaced_rather_than_echoed(): void
    {
        $workspace = $this->createWorkspace();

        $response = $this->actingAs($workspace['user'])
            ->withHeader('X-Request-Id', "injected\nlog line")
            ->get(route('client.dashboard'));

        $this->assertNotSame("injected\nlog line", $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function audit_entries_receive_a_public_identifier(): void
    {
        $workspace = $this->createWorkspace();

        $entry = AuditLog::create([
            'tenant_id' => $workspace['tenant']->getKey(),
            'action' => 'test.event',
        ]);

        $this->assertNotNull($entry->public_id);
        $this->assertSame(26, strlen($entry->public_id));
    }
}
