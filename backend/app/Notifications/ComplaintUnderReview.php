<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BookingComplaint;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** The guest is told their complaint is being looked at. No amount, no promise. */
class ComplaintUnderReview extends Notification
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
        return 'ممسى: جارٍ مراجعة شكواك على الحجز '
            .($this->complaint->booking?->code ?? $this->complaint->booking_id).'.';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['complaint_id' => $this->complaint->id, 'status' => $this->complaint->status];
    }
}
