<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BookingComplaint;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The complaint was accepted and a refund is on its way.
 *
 * Deliberately names NEITHER the amount NOR how long it will take (G2). The
 * refund has only been approved at this point — it has not reached the gateway,
 * let alone settled, and it can still fail. A figure here would be a promise
 * made before the money moved; a duration would be a promise about a third
 * party. The amount is stated once, by {@see ComplaintRefundSettled}, after
 * settlement is confirmed.
 */
class ComplaintApproved extends Notification
{
    use Queueable;

    public function __construct(public readonly BookingComplaint $complaint) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return 'ممسى: تمت الموافقة على شكواك على الحجز '
            .($this->complaint->booking?->code ?? $this->complaint->booking_id)
            .'، وجارٍ تنفيذ الاسترداد.';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['complaint_id' => $this->complaint->id, 'status' => $this->complaint->status];
    }
}
