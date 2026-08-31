<?php

declare(strict_types=1);

namespace App\Domains\Integration\Jobs;

use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Integration\Enums\WebhookStatus;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Integration\Models\ProviderWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Acts on a webhook that has already been verified and recorded (spec §52).
 *
 * What this job deliberately does *not* do is move money. A webhook is an
 * assertion by an outside party arriving on a public URL; the signature proves
 * it came from Meta, not that it is the whole truth. Spend is drawn from a
 * client's wallet by the reconciler, which asks Meta directly and compares
 * against what it has already captured.
 *
 * So a webhook's job here is to make the platform *look sooner* — mark a
 * connection as needing attention, note that a campaign changed — rather than
 * to be believed about amounts.
 */
final class ProcessProviderWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(private readonly int $eventId)
    {
        $this->onQueue('provider_webhooks');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->eventId))->dontRelease()];
    }

    public function handle(AuditRecorder $audit): void
    {
        $event = ProviderWebhookEvent::query()->find($this->eventId);

        if ($event === null || $event->status->isSettled()) {
            return;
        }

        $event->attempts++;

        try {
            $handled = $this->dispatchByObject($event);

            $event->status = $handled ? WebhookStatus::Processed : WebhookStatus::Ignored;
            $event->processed_at = Carbon::now();
            $event->last_error = null;
            $event->save();

            if ($handled) {
                $audit->recordSystemEvent(
                    action: AuditAction::WebhookProcessed,
                    resource: $event,
                    context: $event->describe(),
                    label: 'ProcessProviderWebhook',
                );
            }
        } catch (Throwable $exception) {
            $event->status = WebhookStatus::Failed;
            $event->last_error = mb_substr($exception->getMessage(), 0, 250);
            $event->save();

            throw $exception;
        }
    }

    /**
     * @return bool whether anything was actually done with it
     */
    private function dispatchByObject(ProviderWebhookEvent $event): bool
    {
        return match ($event->object_type) {
            'permissions' => $this->handlePermissions($event),
            default => false,
        };
    }

    /**
     * Meta tells us when a person removes a permission they had granted.
     *
     * The connection is marked as needing attention rather than revoked
     * outright: the platform's own verification is what establishes whether
     * the grant is truly gone, and a webhook that arrived out of order should
     * not tear down a working connection.
     */
    private function handlePermissions(ProviderWebhookEvent $event): bool
    {
        $touched = false;

        foreach ($event->payload['entry'] ?? [] as $entry) {
            $externalUserId = isset($entry['uid']) ? (string) $entry['uid'] : null;

            if ($externalUserId === null) {
                continue;
            }

            $connections = ProviderConnection::query()
                ->withoutGlobalScopes()
                ->where('provider', Provider::Meta)
                ->where('external_user_id', $externalUserId)
                ->whereNull('revoked_at')
                ->get();

            foreach ($connections as $connection) {
                $connection->forceFill([
                    'status' => ConnectionStatus::Error,
                    'status_detail' => 'A permission was changed at Meta. We are re-checking this connection.',
                ])->save();

                $touched = true;
            }
        }

        return $touched;
    }
}
