<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the guest when their booking is cancelled, stating the refunded
 * amount — FR-047. SMS always; email (verified addresses) per the email
 * task doc §3. $byHost switches the wording to host-cancellation + 100%.
 */
class BookingCancelled extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly float $refundAmount,
        public readonly bool $byHost = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = [SmsChannel::class];

        if (! blank($notifiable->email ?? null) && ($notifiable->email_verified_at ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->booking->loadMissing('unit', 'user');

        return (new MailMessage())
            ->subject(($this->byHost ? 'إلغاء الحجز من المضيف BK-' : 'تأكيد إلغاء حجزك BK-').$this->booking->id.' — مَمسَى')
            ->view('emails.booking-cancelled-guest', [
                'booking'      => $this->booking,
                'refundAmount' => $this->refundAmount,
                'byHost'       => $this->byHost,
            ]);
    }

    public function toSms(object $notifiable): string
    {
        $unit = $this->booking->unit->unit_name ?? '';

        $refundLine = $this->refundAmount > 0
            ? ' وسيتم رد مبلغ ' . rtrim(rtrim(number_format($this->refundAmount, 2), '0'), '.') . ' ريال.'
            : ' لا يوجد مبلغ مسترد وفق سياسة الإلغاء.';

        return 'ممسى: تم إلغاء حجزك رقم #' . $this->booking->id . ' لوحدة "' . $unit . '".' . $refundLine;
    }
}
