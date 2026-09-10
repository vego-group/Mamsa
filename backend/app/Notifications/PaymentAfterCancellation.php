<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A payment arrived for a booking the platform had already cancelled, and the
 * nights could not be given back.
 *
 * This is the end of the one path in checkout where a guest can be charged for
 * a stay they will not get: the payment hold expired, the dates were released,
 * somebody else took them, and only then did the gateway confirm the money.
 * The refund is issued automatically — this exists because a human still has
 * to talk to the guest, who was told their booking was cancelled and then saw
 * a charge appear.
 *
 * Reaching this at any volume means the hold window is shorter than real
 * payments take, which is a number to change rather than an incident.
 */
class PaymentAfterCancellation extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly float $amount,
        /** How the automatic refund went: 'gateway' | 'simulated' | 'failed'. */
        public readonly string $refundOutcome,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $failed = $this->refundOutcome === 'failed';

        $mail = (new MailMessage)
            ->subject($failed
                ? '🔴 دفعة وصلت بعد إلغاء الحجز والاسترداد التلقائي فشل'
                : '⚠ دفعة وصلت بعد إلغاء الحجز — تم الاسترداد')
            ->line('وصلت دفعة ناجحة لحجز كانت المنصة ألغته لانتهاء مهلة الدفع، والتواريخ اتحجزت لضيف تاني.')
            ->line('الحجز: #'.$this->booking->id.' — الوحدة #'.$this->booking->unit_id)
            ->line('المبلغ: '.number_format($this->amount, 2).' ريال');

        $mail->line(match ($this->refundOutcome) {
            'gateway' => 'الاسترداد اتبعت للبوابة وبيستنى تأكيد الـ webhook.',
            'simulated' => 'الحجز مالوش دفعة على البوابة — الاسترداد اتسجل محلياً فقط.',
            default => '🔴 الاسترداد فشل عند البوابة. لازم يتنفذ يدوياً فوراً.',
        });

        return $mail->line('الضيف اتبلّغ إن حجزه اتلغى، وبعدين اتخصم منه. محتاج تواصل بشري.');
    }
}
