<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Refund;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The partner's share of a complaint refund has been taken back.
 *
 * States the amount and the booking outright, per §7 — a partner who finds a
 * debit on their statement with no explanation has to open a support ticket to
 * learn what it was. The figure is the partner's SHARE, not the sum the guest
 * received: VAT goes back to ZATCA and the commission back to Mamsa, so
 * quoting the gross here would overstate what the partner actually bore.
 */
class ComplaintPartnerDebited extends Notification
{
    use Queueable;

    public function __construct(public readonly Refund $refund) {}

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
        return 'ممسى: تم خصم '.$this->share().' ريال من رصيدك بسبب شكوى على الحجز '.$this->code().'.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('خصم من رصيدك — شكوى على الحجز '.$this->code())
            ->greeting('مرحباً '.($notifiable->name ?? ''))
            ->line('تم خصم '.$this->share().' ريال من رصيدك بسبب شكوى على الحجز '.$this->code().'.')
            ->line('المبلغ المخصوم هو حصتك من قيمة الاسترداد؛ الضريبة والعمولة لا تُخصم منك.')
            ->line('تجد السطر في كشف حسابك داخل لوحة الشريك.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'refund_id'      => $this->refund->id,
            'complaint_id'   => $this->refund->complaint_id,
            'amount_partner' => round((float) $this->refund->amount_partner, 2),
        ];
    }

    private function share(): string
    {
        return number_format((float) $this->refund->amount_partner, 2);
    }

    private function code(): string
    {
        return (string) ($this->refund->booking?->code ?? $this->refund->booking_id);
    }
}
