<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Notifications;

use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Models\AdAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells platform staff that a managed ad account needs looking at (spec §20).
 *
 * Goes to operators, never to clients: the inventory is ours, and a client
 * hearing about an account that also serves other clients would learn
 * something they have no business knowing.
 */
final class AdAccountNeedsAttention extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly AdAccount $account,
        private readonly AdAccountHealth $health,
        private readonly ?string $detail = null,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->health === AdAccountHealth::Critical
            ? ['mail', 'database']
            // A degraded account is worth recording, not worth an email at
            // three in the morning.
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Ad account needs attention: {$this->account->name}")
            ->greeting('Hello')
            ->line("{$this->account->name} ({$this->account->provider->label()}) is now {$this->health->label()}.")
            ->lineIf($this->detail !== null, (string) $this->detail)
            ->line('Campaigns will not be allocated to this account until it recovers.')
            ->action('Open ad account', route('admin.ad-accounts.show', $this->account));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ad_account.needs_attention',
            // describe() carries no provider credentials.
            'account' => $this->account->describe(),
            'health' => $this->health->value,
            'detail' => $this->detail,
        ];
    }
}
