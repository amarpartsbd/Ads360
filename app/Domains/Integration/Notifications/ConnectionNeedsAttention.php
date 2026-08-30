<?php

declare(strict_types=1);

namespace App\Domains\Integration\Notifications;

use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Integration\Models\ProviderConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a client their provider connection needs reconnecting (spec §49).
 *
 * The message says what to do in plain language. Spec §80 is explicit that
 * "OAuthException #190" is the wrong thing to put in front of someone.
 */
final class ConnectionNeedsAttention extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ProviderConnection $connection,
        private readonly ConnectionStatus $status,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $provider = $this->connection->provider->connectionLabel();

        return (new MailMessage)
            ->subject("Action needed: your {$provider} connection")
            ->greeting('Hello')
            ->line($this->status->clientMessage())
            ->line("Until it is reconnected we cannot publish or update campaigns using your {$provider} assets.")
            ->action('Reconnect now', route('client.assets.index'))
            ->line('Your existing campaigns and spend are unaffected.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'connection.needs_attention',
            // describe() carries no credentials.
            'connection' => $this->connection->describe(),
            'status' => $this->status->value,
            'message' => $this->status->clientMessage(),
        ];
    }
}
