<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Notifications;

use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a client what happened to a campaign they submitted (spec §49).
 *
 * The reviewer's notes go through verbatim because they were written for the
 * client. Nothing here paraphrases a decision someone else made.
 */
final class CampaignReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Campaign $campaign,
        private readonly CampaignStatus $outcome,
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
        $subject = match ($this->outcome) {
            CampaignStatus::Approved => "Approved: {$this->campaign->name}",
            CampaignStatus::Rejected => "Not approved: {$this->campaign->name}",
            default => "Changes needed: {$this->campaign->name}",
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello')
            ->line($this->outcome->clientMessage())
            ->lineIf(
                $this->campaign->review_notes !== null,
                (string) $this->campaign->review_notes,
            )
            ->action('Open campaign', route('client.campaigns.show', $this->campaign));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'campaign.reviewed',
            'campaign' => $this->campaign->describe(),
            'outcome' => $this->outcome->value,
            'message' => $this->outcome->clientMessage(),
            'notes' => $this->campaign->review_notes,
        ];
    }
}
