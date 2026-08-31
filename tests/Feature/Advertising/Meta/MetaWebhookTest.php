<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Meta;

use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Advertising\Providers\Meta\MetaConfig;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integration\Enums\WebhookStatus;
use App\Domains\Integration\Jobs\ProcessProviderWebhook;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Integration\Models\ProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakesMetaGraph;
use Tests\TestCase;

/**
 * The webhook endpoint (spec §52, §98).
 *
 * This URL is reachable by anyone on the internet. Every test here is a way
 * somebody could try to tell the platform something untrue about a client's
 * account.
 */
final class MetaWebhookTest extends TestCase
{
    use FakesMetaGraph;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The endpoint resolves its config from the container, so the test's
        // fake credentials have to be what it finds.
        $this->app->instance(MetaConfig::class, $this->metaConfig());

        Queue::fake();
    }

    #[Test]
    public function an_unsigned_payload_is_refused(): void
    {
        $response = $this->postJson(route('webhooks.meta.receive'), ['object' => 'permissions']);

        $response->assertForbidden();
        $this->assertSame(0, ProviderWebhookEvent::query()->count());
    }

    #[Test]
    public function a_wrongly_signed_payload_is_refused(): void
    {
        $body = json_encode(['object' => 'permissions'], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            route('webhooks.meta.receive'),
            [],
            [],
            [],
            ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-wrong-secret'),
                'CONTENT_TYPE' => 'application/json'],
            $body,
        );

        $response->assertForbidden();
        $this->assertSame(0, ProviderWebhookEvent::query()->count());
    }

    #[Test]
    public function a_signature_over_different_bytes_is_refused(): void
    {
        $signedBody = json_encode(['object' => 'permissions'], JSON_THROW_ON_ERROR);
        $sentBody = json_encode(['object' => 'ad_account'], JSON_THROW_ON_ERROR);

        // A valid signature for one payload, attached to another.
        $response = $this->call(
            'POST',
            route('webhooks.meta.receive'),
            [],
            [],
            [],
            ['HTTP_X_HUB_SIGNATURE_256' => $this->signature($signedBody),
                'CONTENT_TYPE' => 'application/json'],
            $sentBody,
        );

        $response->assertForbidden();
    }

    #[Test]
    public function a_refused_delivery_is_recorded_as_a_security_event(): void
    {
        $this->postJson(route('webhooks.meta.receive'), ['object' => 'permissions']);

        $entry = AuditLog::query()->where('action', 'integration.webhook.rejected')->firstOrFail();

        $this->assertSame('META', $entry->context['provider']);
    }

    #[Test]
    public function a_correctly_signed_payload_is_accepted_and_queued(): void
    {
        $response = $this->sendSigned(['object' => 'permissions', 'entry' => []]);

        $response->assertOk()->assertJson(['status' => 'accepted']);

        $event = ProviderWebhookEvent::query()->firstOrFail();

        $this->assertSame(WebhookStatus::Received, $event->status);
        $this->assertSame('permissions', $event->object_type);

        // Meta retries anything it does not get a prompt 200 for, so the work
        // happens on a queue rather than in the request.
        Queue::assertPushed(ProcessProviderWebhook::class);
    }

    #[Test]
    public function a_redelivery_of_the_same_payload_is_acknowledged_once(): void
    {
        $payload = ['object' => 'permissions', 'entry' => [['uid' => '900100']]];

        $this->sendSigned($payload)->assertOk()->assertJson(['status' => 'accepted']);

        // Meta redelivering after a timeout is correct behaviour, not an error.
        $this->sendSigned($payload)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, ProviderWebhookEvent::query()->count());
        Queue::assertPushed(ProcessProviderWebhook::class, 1);
    }

    #[Test]
    public function the_subscription_handshake_needs_the_configured_token(): void
    {
        $this->get(route('webhooks.meta.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge' => 'a-challenge-value',
        ]))->assertOk()->assertSee('a-challenge-value');
    }

    #[Test]
    public function the_handshake_is_refused_with_the_wrong_token(): void
    {
        // Without this check, anyone could point their own Meta app here.
        $this->get(route('webhooks.meta.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'not-the-token',
            'hub_challenge' => 'a-challenge-value',
        ]))->assertForbidden();
    }

    #[Test]
    public function a_permission_change_marks_the_connection_for_re_checking(): void
    {
        $connection = ProviderConnection::factory()->create(['external_user_id' => '900100']);

        $event = ProviderWebhookEvent::query()->create([
            'provider' => 'META',
            'object_type' => 'permissions',
            'payload_digest' => str_repeat('a', 64),
            'status' => WebhookStatus::Received,
            'payload' => ['object' => 'permissions', 'entry' => [['uid' => '900100']]],
            'received_at' => now(),
        ]);

        (new ProcessProviderWebhook($event->getKey()))->handle(app(\App\Domains\Audit\Services\AuditRecorder::class));

        $connection->refresh();

        // Marked for attention, not torn down: the platform's own verification
        // establishes whether the grant is truly gone.
        $this->assertSame(ConnectionStatus::Error, $connection->status);
        $this->assertNotNull($connection->status_detail);
        $this->assertNull($connection->revoked_at);
        $this->assertSame(WebhookStatus::Processed, $event->fresh()->status);
    }

    #[Test]
    public function an_object_type_the_platform_does_not_handle_is_ignored_not_failed(): void
    {
        $event = ProviderWebhookEvent::query()->create([
            'provider' => 'META',
            'object_type' => 'something_new',
            'payload_digest' => str_repeat('b', 64),
            'status' => WebhookStatus::Received,
            'payload' => ['object' => 'something_new'],
            'received_at' => now(),
        ]);

        (new ProcessProviderWebhook($event->getKey()))->handle(app(\App\Domains\Audit\Services\AuditRecorder::class));

        $this->assertSame(WebhookStatus::Ignored, $event->fresh()->status);
    }

    #[Test]
    public function a_delivery_record_cannot_be_deleted(): void
    {
        $event = ProviderWebhookEvent::query()->create([
            'provider' => 'META',
            'object_type' => 'permissions',
            'payload_digest' => str_repeat('c', 64),
            'status' => WebhookStatus::Received,
            'payload' => [],
            'received_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        $event->delete();
    }

    #[Test]
    public function the_endpoint_needs_no_csrf_token(): void
    {
        // Registered outside the web group on purpose: a provider has no
        // session and cannot carry a token. The signature authenticates it.
        $this->sendSigned(['object' => 'permissions'])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function sendSigned(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            route('webhooks.meta.receive'),
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $this->signature($body),
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );
    }

    private function signature(string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');
    }
}
