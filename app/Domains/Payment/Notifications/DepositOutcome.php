<?php

declare(strict_types=1);

namespace App\Domains\Payment\Notifications;

use App\Domains\Payment\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a client whether their deposit was accepted (spec §49).
 */
final class DepositOutcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Payment $payment,
        private readonly bool $approved,
        private readonly ?string $reason,
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
        $amount = $this->payment->amountMoney()->format();

        $message = (new MailMessage)
            ->subject($this->approved
                ? "{$amount} added to your wallet"
                : "We could not confirm your deposit {$this->payment->reference}")
            ->greeting('Hello');

        if ($this->approved) {
            $message
                ->line("Your deposit of {$amount} has been confirmed and added to your wallet.")
                ->line("Reference: {$this->payment->reference}");
        } else {
            $message->line("We were unable to confirm your deposit of {$amount}.");

            if ($this->reason !== null && $this->reason !== '') {
                $message->line($this->reason);
            }

            $message->line('No funds have been taken from your wallet.');
        }

        return $message->action('Open your wallet', route('client.wallet.overview'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'deposit.outcome',
            'payment' => $this->payment->public_id,
            'reference' => $this->payment->reference,
            'amount' => $this->payment->amountMoney()->jsonSerialize(),
            'approved' => $this->approved,
            'reason' => $this->reason,
        ];
    }
}
