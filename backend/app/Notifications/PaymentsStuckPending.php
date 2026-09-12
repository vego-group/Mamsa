<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Payments the gateway still will not resolve.
 *
 * The reconciliation job asks Moyasar about anything whose webhook did not
 * arrive, so most of these settle themselves within a run. One that is still
 * unresolved hours later is not a webhook problem: either Moyasar is
 * unreachable from this host, or the payment is in a state the code does not
 * handle. Both need a person, and neither announces itself — the guest has paid
 * and is looking at an unconfirmed booking.
 */
class PaymentsStuckPending extends Notification
{
    use Queueable;

    /** @param Collection<int, \App\Models\Payment> $payments */
    public function __construct(
        public readonly Collection $payments,
        public readonly int $hours,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('⚠ مدفوعات معلّقة لم تُحسم عبر البوابة')
            ->line($this->payments->count().' دفعة لسه معلّقة بعد أكثر من '.$this->hours.' ساعة، والمطابقة مع ميسر ما حسمتهاش.')
            ->line('الضيف دفع ويشوف حجز غير مؤكد — الحالة دي محتاجة مراجعة بشرية.');

        foreach ($this->payments->take(20) as $p) {
            $mail->line(sprintf(
                '• دفعة #%s — حجز #%s — %s ريال — %s',
                $p->id, $p->booking_id, number_format((float) $p->amount, 2), $p->created_at,
            ));
        }

        if ($this->payments->count() > 20) {
            $mail->line('… و'.($this->payments->count() - 20).' غيرها.');
        }

        return $mail->line('ابدأ بسؤال ميسر عن حالة الدفعة مباشرة، وتأكد إن الخادم يقدر يوصلها.');
    }
}
