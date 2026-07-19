<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the unit's partner when the GUEST cancels a paid booking (email
 * task doc §3): in-app + email with the refund granted from the frozen
 * policy snapshot. (Host-cancellations use HostCancellation instead.)
 */
class GuestCancelledBooking extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly float $refundAmount,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! blank($notifiable->email ?? null) && ($notifiable->email_verified_at ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('إلغاء حجز BK-'.$this->booking->id.' على وحدتك — مَمسَى')
            ->view('emails.booking-cancelled-partner', [
                'booking'      => $this->booking->loadMissing('unit', 'user'),
                'partnerName'  => (string) $notifiable->name,
                'refundAmount' => $this->refundAmount,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'booking_cancelled',
            'booking_id'   => $this->booking->id,
            'booking_code' => 'BK-'.$this->booking->id,
            'unit_name'    => $this->booking->unit?->unit_name,
            'refund'       => $this->refundAmount,
            'title'        => 'قام الضيف بإلغاء الحجز',
            'body'         => 'تم إلغاء الحجز BK-'.$this->booking->id.' وتحرير التواريخ في التقويم.',
            'action_url'   => '/bookings/b_'.$this->booking->id,
            'icon'         => 'event_busy',
        ];
    }
}
