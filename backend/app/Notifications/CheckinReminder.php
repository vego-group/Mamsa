<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Day-before check-in reminder (email task doc §3): unit address + check-in
 * time. Email only, dispatched by bookings:checkin-reminders (10:00 Riyadh).
 */
class CheckinReminder extends Notification
{
    use Queueable;

    public function __construct(public readonly Booking $booking)
    {
    }

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
        $this->booking->loadMissing('unit', 'user');
        $unit = $this->booking->unit;

        return (new MailMessage())
            ->subject('تذكير: موعد وصولك غداً — حجز BK-'.$this->booking->id)
            ->view('emails.booking-reminder', [
                'booking'     => $this->booking,
                'checkinTime' => substr((string) ($unit->checkin_time ?? '15:00'), 0, 5),
                'address'     => trim(implode('، ', array_filter([
                    $unit->address ?? null,
                    $unit->district ?? null,
                    $unit->city ?? null,
                ]))) ?: null,
            ]);
    }
}
