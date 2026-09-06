<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BookingComplaint;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** The decision went against the complaint. Carries the admin's guest_message. */
class ComplaintRejected extends Notification
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
        // guest_message is the admin's own wording. internal_note is NEVER used
        // on this path — it is the note they wrote for themselves (T13).
        $reason = $this->complaint->guest_message;

        return 'ممسى: تمت مراجعة شكواك على الحجز '
            .($this->complaint->booking?->code ?? $this->complaint->booking_id)
            .'. '.($reason ?: 'لم يتم قبول الشكوى.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['complaint_id' => $this->complaint->id, 'status' => $this->complaint->status];
    }
}
