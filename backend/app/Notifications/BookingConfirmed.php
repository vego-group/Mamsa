<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to the guest when their booking is confirmed (paid). SMS only —
 * regular users have no in-app notification center (FR-034 / FR-100).
 */
class BookingConfirmed extends Notification
{
    use Queueable;

    public function __construct(public readonly Booking $booking)
    {
    }

    /** @return array<int, class-string> */
    public function via(object $notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        $unit = $this->booking->unit->unit_name ?? '';

        return 'ممسى: تم تأكيد حجزك لوحدة "' . $unit . '" من ' . $this->booking->start_date
            . ' إلى ' . $this->booking->end_date . '. رقم الحجز: #' . $this->booking->id;
    }
}
