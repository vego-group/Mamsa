<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The nightly money check found something. Sent to super admins.
 *
 * A check nobody reads is not a check. This one exists because the ledger gap
 * that went unnoticed for three days on staging did so with every surface
 * reporting healthy — so the finding has to travel to a person, not to a log
 * file that gets read a week later.
 *
 * The counts are carried in the notification rather than left in the log: an
 * alert that only says "the check failed" makes someone SSH in to learn whether
 * it is one rounding error or every booking, and that delay is the whole cost.
 */
class LedgerCheckFailed extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $uncredited,
        public readonly int $brokenSplit,
        public readonly int $rateMismatch,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // Database so it reaches the admin console's bell, mail so it does not
        // wait for someone to open the console.
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    private function lines(): array
    {
        return array_values(array_filter([
            $this->uncredited > 0
                ? "{$this->uncredited} حجز مكتمل له حصة لم تصل إلى دفتر الأستاذ"
                : null,
            $this->brokenSplit > 0
                ? "{$this->brokenSplit} حجز لا يتحقق فيه: العمولة + حصة الشريك = المبلغ الأساسي"
                : null,
            $this->rateMismatch > 0
                ? "{$this->rateMismatch} حجز قيمة العمولة فيه لا تساوي المبلغ الأساسي × النسبة"
                : null,
        ]));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('ممسى — فحص الحسابات اليومي وجد مشكلة')
            ->line('فحص اتساق الحجوزات الليلي أبلغ عن الآتي:');

        foreach ($this->lines() as $line) {
            $mail->line('• '.$line);
        }

        return $mail
            ->line('الحجوزات التي لم تُقيَّد تُصلَح بأمر: php artisan wallet:backfill-earnings')
            ->line('أي شيء آخر يحتاج فحصًا قبل تعديله — الأرقام هنا أموال.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'ledger_check_failed',
            'title'         => 'فحص الحسابات اليومي وجد مشكلة',
            'body'          => implode(' · ', $this->lines()),
            'uncredited'    => $this->uncredited,
            'broken_split'  => $this->brokenSplit,
            'rate_mismatch' => $this->rateMismatch,
            'action_url'    => '/admin/reports',
            'icon'          => 'account_balance_wallet',
        ];
    }
}
