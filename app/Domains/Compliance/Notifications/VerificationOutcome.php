<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Notifications;

use App\Domains\Compliance\Enums\ReviewDecision;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a client what compliance decided about their business verification
 * (spec §49).
 *
 * Only the reviewer's client-facing message is included. Internal reasoning
 * never leaves the admin surface.
 */
final class VerificationOutcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
        private readonly ReviewDecision $decision,
        private readonly ?string $clientMessage,
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
        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello')
            ->line($this->headline());

        if ($this->clientMessage !== null && $this->clientMessage !== '') {
            $message->line('The reviewer noted:')->line($this->clientMessage);
        }

        return $message
            ->action('Open your workspace', route('client.dashboard'))
            ->line('If you have questions, reply to this email and our support team will help.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'verification.outcome',
            'organization' => $this->organization->public_id,
            'decision' => $this->decision->value,
            'headline' => $this->headline(),
            'message' => $this->clientMessage,
        ];
    }

    private function subject(): string
    {
        return match ($this->decision) {
            ReviewDecision::Approved => "{$this->organization->name} is verified",
            ReviewDecision::Rejected => "Verification was not approved for {$this->organization->name}",
            ReviewDecision::InformationRequested => "More information needed for {$this->organization->name}",
            ReviewDecision::Suspended => "Verification suspended for {$this->organization->name}",
            ReviewDecision::Claimed => "Verification update for {$this->organization->name}",
        };
    }

    private function headline(): string
    {
        return match ($this->decision) {
            ReviewDecision::Approved => 'Your business has been verified. Your account is now fully active.',
            ReviewDecision::Rejected => 'We were unable to verify your business from the documents provided.',
            ReviewDecision::InformationRequested => 'We need a little more information before we can verify your business.',
            ReviewDecision::Suspended => 'Verification for your business has been suspended and account access is restricted.',
            ReviewDecision::Claimed => 'A reviewer has started checking your submission.',
        };
    }
}
