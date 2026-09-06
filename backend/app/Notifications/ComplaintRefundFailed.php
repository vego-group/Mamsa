<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operational alert: the gateway refused a complaint refund.
 *
 * Goes to config('complaints.alert_recipients'), so the notifiable may be an
 * AnonymousNotifiable with no name or id — nothing here reads a user property.
 */
class ComplaintRefundFailed extends Notification
{
    use Queueable;

    public function __construct(public readonly Refund $refund) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('⚠ فشل تنفيذ استرداد شكوى — الحجز '.$this->code())
            ->line('تعذّر تنفيذ الاسترداد عبر بوابة الدفع.')
            ->line('الحجز: '.$this->code())
            ->line('المبلغ: '.number_format((float) $this->refund->amount, 2).' ريال')
            ->line('سبب الرفض: '.($this->refund->failure_reason ?: '—'))
            ->line('لم يُسجَّل أي قيد في محفظة الشريك، وحالة الشكوى رجعت إلى "معتمدة" لإعادة المحاولة.');
    }

    private function code(): string
    {
        return (string) ($this->refund->booking?->code ?? $this->refund->booking_id);
    }
}
