<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operational alert: a settlement event could not be matched to specific rows.
 *
 * Moyasar reports a cumulative refunded total on the payment and gives no
 * per-refund identifier, so when several refunds are pending on one payment
 * the total is the only way to tell which of them the event covers. When it
 * does not add up, guessing would either credit a refund that never settled or
 * debit a partner for money the guest never received — and the ledger is
 * append-only, so a wrong entry is corrected by a second entry, forever
 * visible. Nothing is settled and a human is told instead.
 */
class RefundSettlementAmbiguous extends Notification
{
    use Queueable;

    /** @param list<array{id:int,amount:float}> $pending */
    public function __construct(
        public readonly Payment $payment,
        public readonly int $gatewayRefundedHalalas,
        public readonly int $unexplainedHalalas,
        public readonly array $pending,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('⚠ تسوية استرداد غير قابلة للمطابقة — دفعة #'.$this->payment->id)
            ->line('وصل حدث تسوية من ميسر ولم نتمكن من تحديد أي صفوف الاسترداد يخصّها.')
            ->line('إجمالي المسترد لدى ميسر: '.number_format($this->gatewayRefundedHalalas / 100, 2).' ريال')
            ->line('الفرق غير المفسَّر: '.number_format($this->unexplainedHalalas / 100, 2).' ريال')
            ->line('الصفوف المعلّقة على هذه الدفعة:');

        foreach ($this->pending as $row) {
            $mail->line('• استرداد #'.$row['id'].' — '.number_format($row['amount'], 2).' ريال');
        }

        return $mail
            ->line('لم تُسوَّ أي صفوف ولم يُكتب أي قيد. راجعوا الدفعة في لوحة ميسر يدوياً.');
    }
}
