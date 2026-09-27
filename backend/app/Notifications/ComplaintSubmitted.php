<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BookingComplaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A new complaint has landed in the admin queue — in-app feed + email (§7). */
class ComplaintSubmitted extends Notification
{
    use Queueable;

    public function __construct(public readonly BookingComplaint $complaint) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return blank($notifiable->email ?? null) ? ['database'] : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('شكوى جديدة على الحجز '.$this->code())
            ->line('تم تقديم شكوى جديدة على الحجز '.$this->code().'.')
            ->line('عدد المرفقات: '.$this->complaint->attachments()->count())
            ->line('تواصل الضيف مع الشريك قبل الشكوى: '.($this->complaint->contacted_partner ? 'نعم' : 'لا'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'complaint.submitted',
            'complaint_id' => $this->complaint->id,
            'booking_code' => $this->code(),
        ];
    }

    private function code(): string
    {
        return (string) ($this->complaint->booking?->code ?? $this->complaint->booking_id);
    }
}
