<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BookingComplaint;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A complaint has been filed against the partner's unit (§7).
 *
 * Carries no guest phone number and no internal note — the partner learns that
 * a complaint exists and on which booking, not who to call about it. Contact
 * between the two parties goes through the admin reviewing the case.
 */
class ComplaintReceivedPartner extends Notification
{
    use Queueable;

    public function __construct(public readonly BookingComplaint $complaint) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database', SmsChannel::class];

        if (! blank($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toSms(object $notifiable): string
    {
        return 'ممسى: تم تقديم شكوى على وحدتك بخصوص الحجز '.$this->code()
            .'. سنتواصل معك قبل اتخاذ أي قرار.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('شكوى على وحدتك — الحجز '.$this->code())
            ->greeting('مرحباً '.($notifiable->name ?? ''))
            ->line('تم تقديم شكوى من الضيف بخصوص الحجز '.$this->code().'.')
            ->line('فريق ممسى سيراجع الشكوى وسيتواصل معك قبل اتخاذ أي قرار.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'complaint.received',
            'complaint_id' => $this->complaint->id,
            'booking_code' => $this->code(),
        ];
    }

    private function code(): string
    {
        return (string) ($this->complaint->booking?->code ?? $this->complaint->booking_id);
    }
}
