<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the guest when Moyasar confirms a refund has settled (webhook
 * `refund.*` → processing → succeeded). Email only — the cancellation
 * itself was already announced by SMS (email task doc §3).
 */
class RefundProcessed extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly float $refundAmount,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! blank($notifiable->email ?? null) && ($notifiable->email_verified_at ?? null)) {
            return ['mail'];
        }

        return [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('تم اكتمال استرداد مبلغ حجزك BK-'.$this->booking->id.' — مَمسَى')
            ->view('emails.refund-processed', [
                'booking'      => $this->booking->loadMissing('unit', 'user'),
                'refundAmount' => $this->refundAmount,
            ]);
    }
}
