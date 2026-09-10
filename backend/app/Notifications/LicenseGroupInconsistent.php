<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Apartments in one building disagree about the permit they operate under.
 *
 * This should be impossible: the licence is written to the whole group in one
 * transaction, and the Unit model refuses an update that touches those columns
 * any other way. Reaching this means a path exists that does neither — most
 * likely a query-builder update, which fires no model events and so slips past
 * the model guard entirely.
 *
 * It matters because the number is a legal claim. A group of ten whose members
 * disagree means at least some of those apartments are being let under a permit
 * that may not cover them, and the system currently believes otherwise.
 */
class LicenseGroupInconsistent extends Notification
{
    use Queueable;

    /** @param array<int, object> $groups */
    public function __construct(public readonly array $groups) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('⚠ تعارض في بيانات التصريح داخل مبنى')
            ->line('فيه مبنى أو أكتر شققه مش متفقة على نوع التصريح أو عدد الوحدات المرخّصة.')
            ->line('ده معناه إن فيه شقة شغالة تحت تصريح ممكن ما يغطيهاش، والنظام فاكر العكس.');

        foreach (array_slice($this->groups, 0, 20) as $g) {
            $mail->line(sprintf(
                '• المجموعة %s — أنواع مختلفة: %d، أعداد مختلفة: %d',
                $g->unit_group_id, (int) $g->types, (int) $g->counts,
            ));
        }

        if (count($this->groups) > 20) {
            $mail->line('… و'.(count($this->groups) - 20).' مجموعة أخرى.');
        }

        return $mail->line('التصحيح: حدّد القيمة الصحيحة من ملف التصريح واكتبها على المجموعة كلها.');
    }
}
