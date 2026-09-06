<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A refund settled against a complaint that was not awaiting settlement.
 *
 * The ledger entry is still written — money moved, and the ledger records what
 * happened, not what was decided. What is suppressed is the status write: that
 * records a DECISION, and overwriting a rejection with `resolved_refunded`
 * would erase a decision whose financial effect had already been carried out.
 *
 * Reaching this means some path let a complaint leave `approved` while a refund
 * was in flight. The guards on reject, amend and execute exist to make that
 * impossible, so this alert is the signal that a new path has bypassed them.
 */
class SettlementOnUnexpectedState extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Refund $refund,
        public readonly string $complaintStatus,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('⚠ تسوية استرداد على شكوى في حالة غير متوقعة')
            ->line('وصلت تسوية لاسترداد بينما الشكوى ليست في حالة "معتمدة".')
            ->line('الاسترداد: #'.$this->refund->id.' — '.number_format((float) $this->refund->amount, 2).' ريال')
            ->line('الشكوى: #'.$this->refund->complaint_id.' — حالتها: '.$this->complaintStatus)
            ->line('تم تسجيل القيد المحاسبي لأن المبلغ تحرّك فعلاً.')
            ->line('لم تُغيَّر حالة الشكوى، حتى لا يُكتب فوق قرار سبق تسجيله.')
            ->line('راجعوا كيف خرجت الشكوى من حالة "معتمدة" واسترداد قيد التنفيذ — الحُرّاس يمنعون ذلك.');
    }
}
