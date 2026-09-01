<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the guest when their booking is confirmed (paid). SMS always;
 * email when the guest has a verified address (email task doc §3): code,
 * unit, dates, total and the frozen cancellation policy (FR-036).
 */
class BookingConfirmed extends Notification
{
    use Queueable;

    public function __construct(public readonly Booking $booking)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = [SmsChannel::class];

        // Email is a verified contact channel only — unverified addresses
        // may be typos and must not receive booking data.
        if (! blank($notifiable->email ?? null) && ($notifiable->email_verified_at ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->booking->loadMissing('unit', 'user');
        $snapshot = (array) $this->booking->cancellation_snapshot;

        return (new MailMessage())
            ->subject('تأكيد حجزك BK-'.$this->booking->id.' — مَمسَى')
            ->view('emails.booking-confirmed-guest', [
                'booking'     => $this->booking,
                'checkinTime' => substr((string) ($this->booking->unit->checkin_time ?? '15:00'), 0, 5),
                'policyName'  => $snapshot['policy_name'] ?? '',
                'tiers'       => $snapshot['tiers'] ?? [],
                'invoiceUrl'  => $this->invoiceUrl(),
            ]);
    }

    /**
     * Where the guest goes to read their tax invoice.
     *
     * NOT the invoice endpoint itself: that is `GET /bookings/{id}/invoice`,
     * authenticated with a Bearer token, and a token does not survive a mail
     * client. The link points at the storefront's reservation page, which
     * fetches the invoice once the guest is signed in.
     *
     * The host comes from config so staging mail lands on staging — a build
     * that pointed every guest at production would look identical to a
     * working one right up until someone clicked.
     */
    private function invoiceUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/my-reservations/'.$this->booking->id;
    }

    public function toSms(object $notifiable): string
    {
        $unit = $this->booking->unit->unit_name ?? '';

        return 'ممسى: تم تأكيد حجزك لوحدة "' . $unit . '" من ' . $this->booking->start_date
            . ' إلى ' . $this->booking->end_date . '. رقم الحجز: #' . $this->booking->id;
    }
}
