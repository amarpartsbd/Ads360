<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Integration\Enums\WebhookStatus;
use App\Domains\Integration\Jobs\ProcessProviderWebhook;
use App\Domains\Integration\Models\ProviderWebhookEvent;
use App\Domains\Integration\Services\MetaWebhookVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where Meta posts updates (spec §52).
 *
 * The endpoint does as little as it possibly can: verify, record, queue,
 * acknowledge. Meta retries anything it does not get a prompt 200 for, so
 * doing real work here would turn one slow update into a stream of duplicate
 * deliveries (Rule 16).
 *
 * A redelivery of a payload already recorded is acknowledged and dropped. That
 * is not an error — it is Meta behaving correctly after a timeout — and
 * treating it as one would fill the log with noise.
 */
final class MetaWebhookController
{
    public function __construct(
        private readonly MetaWebhookVerifier $verifier,
        private readonly AuditRecorder $audit,
    ) {}

    /** The subscription handshake, run once when the subscription is made. */
    public function verify(Request $request): Response
    {
        $challenge = $this->verifier->challenge($request);

        if ($challenge === null) {
            return response('', 403);
        }

        // Meta expects the bare challenge, not JSON.
        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): JsonResponse
    {
        if (! $this->verifier->verify($request)) {
            // Recorded as a security event: an unsigned or wrongly signed post
            // to this URL is somebody trying something.
            $this->audit->recordSystemEvent(
                action: AuditAction::WebhookRejected,
                context: [
                    'provider' => Provider::Meta->value,
                    'reason' => 'signature verification failed',
                    'ip' => $request->ip(),
                ],
                label: 'MetaWebhookController',
            );

            return response()->json(['status' => 'refused'], 403);
        }

        $raw = $request->getContent();
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $digest = ProviderWebhookEvent::digest($raw);

        /*
         * Looked up before inserting, rather than relying on the unique index
         * to reject a duplicate. A redelivery is Meta's *normal* behaviour
         * after a timeout, and on PostgreSQL a failed statement aborts the
         * whole transaction it sits in — so letting the common case fail would
         * poison any transaction this ever ran inside.
         */
        if (ProviderWebhookEvent::query()->where('payload_digest', $digest)->exists()) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        try {
            // Wrapped so a genuine race — two deliveries arriving together —
            // rolls back to a savepoint instead of taking the connection with
            // it. The index is still the authority; this is its backstop.
            $event = DB::transaction(fn (): ProviderWebhookEvent => ProviderWebhookEvent::query()->create([
                'provider' => Provider::Meta,
                'object_type' => isset($payload['object']) ? (string) $payload['object'] : null,
                'payload_digest' => $digest,
                'status' => WebhookStatus::Received,
                'payload' => $payload,
                'received_at' => Carbon::now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // The other delivery won. Acknowledging is the correct answer.
            return response()->json(['status' => 'duplicate'], 200);
        }

        ProcessProviderWebhook::dispatch($event->getKey())->afterCommit();

        // Acknowledged immediately. The work happens on a queue.
        return response()->json(['status' => 'accepted'], 200);
    }
}
