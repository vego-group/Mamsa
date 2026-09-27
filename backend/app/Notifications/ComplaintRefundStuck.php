<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Operational alert (G1): refunds accepted by the gateway that never settled.
 *
 * A stuck refund is invisible by nature — nothing failed, so nothing is logged
 * as an error. The guest is waiting, the partner has not been debited, and the
 * complaint sits half-closed. This is the only thing that will say so.
 */
class ComplaintRefundStuck extends Notification
{
    use Queueable;

    /** @param Collection<int, \App\Models\Refund> $refunds */
    public function __construct(
        public readonly Collection $refunds,
        public readonly int $thresholdHours,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('⚠ '.$this->refunds->count().' استرداد معلّق أكثر من '.$this->thresholdHours.' ساعة')
            ->line('الاستردادات التالية قبلتها البوابة ولم تصل تسويتها:');

        foreach ($this->refunds as $refund) {
            $mail->line(
                '• استرداد #'.$refund->id
                .' — الحجز '.($refund->booking?->code ?? $refund->booking_id)
                .' — '.number_format((float) $refund->amount, 2).' ريال'
                .' — منذ '.$refund->created_at?->diffForHumans()
            );
        }

        return $mail->line('راجعوا حالة كل منها في لوحة ميسر قبل إعادة المحاولة.');
    }
}
