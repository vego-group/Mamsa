<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to the guest when their booking is cancelled, stating the refunded
 * amount — FR-047. SMS only (regular users have no in-app inbox).
 */
class BookingCancelled extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly float $refundAmount,
    ) {}

    /** @return array<int, class-string> */
    public function via(object $notifiable): array
    {
        return [SmsChannel::class];
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
