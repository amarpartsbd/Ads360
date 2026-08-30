<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites someone to join an organization (spec §82).
 *
 * The plaintext token exists only here, in transit. It is never stored, never
 * logged, and never returned in a response.
 */
final class TeamInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
        private readonly Role $role,
        private readonly User $inviter,
        private readonly string $token,
        private readonly DateTimeInterface $expiresAt,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviter->name} invited you to {$this->organization->name}")
            ->greeting('Hello')
            ->line("{$this->inviter->name} has invited you to join {$this->organization->name} on ".config('platform.name').'.')
            ->line("You will join as {$this->role->name}.")
            ->action('Accept invitation', route('invitations.show', ['token' => $this->token]))
            ->line('This invitation expires on '.$this->expiresAt->format('j F Y').'.')
            ->line('If you were not expecting this invitation you can safely ignore this email.');
    }
}
