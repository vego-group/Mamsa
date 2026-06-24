<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a booking is confirmed (paid). Notifies the unit's partner and
 * all Admins/SuperAdmins, in-app + email (FR-101 / FR-042). The action link is
 * tailored to the recipient's dashboard.
 */
class NewBooking extends Notification
{
    use Queueable;

    public function __construct(public readonly Booking $booking)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('حجز جديد مؤكد')
            ->greeting('مرحباً ' . $notifiable->name)
            ->line('تم تأكيد حجز جديد على وحدة "' . ($this->booking->unit->unit_name ?? '') . '".')
            ->line('الضيف: ' . ($this->booking->user->name ?? '—'))
            ->line('من ' . $this->booking->start_date . ' إلى ' . $this->booking->end_date)
            ->line('المبلغ: ' . number_format((float) $this->booking->total_amount, 2) . ' ر.س')
            ->action('عرض الحجز', $this->actionUrl($notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'new_booking',
            'booking_id' => $this->booking->id,
            'unit_name'  => $this->booking->unit->unit_name ?? null,
            'guest'      => $this->booking->user->name ?? null,
            'amount'     => (float) $this->booking->total_amount,
            'title'      => 'حجز جديد',
            'message'    => 'تم تأكيد حجز جديد على وحدة "' . ($this->booking->unit->unit_name ?? '') . '"',
            'action_url' => $this->relativePath($notifiable),
            'icon'       => 'event_available',
        ];
    }

    /** Admins land on the system bookings page; partners on their own. */
    private function relativePath(object $notifiable): string
    {
        return $notifiable->hasAnyRole(['Admin', 'SuperAdmin'])
            ? '/admin/bookings'
            : '/partner/bookings';
    }

    private function actionUrl(object $notifiable): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $this->relativePath($notifiable);
    }
}
