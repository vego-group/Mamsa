<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Partner-dashboard contract §8 — `host_cancellation`. Recorded on the
 * partner's own account when they host-cancel a booking. In-app + SMS.
 */
class HostCancellation extends Notification
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return 'ممسى: تم تسجيل إلغائك للحجز BK-'.$this->booking->id
            .' واسترداد كامل المبلغ للضيف.';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'host_cancellation',
            'booking_id'  => $this->booking->id,
            'booking_code' => 'BK-'.$this->booking->id,
            'unit_name'   => $this->booking->unit?->unit_name,
            'title'       => 'تم تسجيل إلغاء الحجز',
            'body'        => 'تم إلغاء الحجز BK-'.$this->booking->id.' واسترداد كامل المبلغ للضيف.',
            'action_url'  => '/bookings/b_'.$this->booking->id,
            'icon'        => 'event_busy',
        ];
    }
}
