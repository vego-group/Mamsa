<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Refund;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The refund has SETTLED at the gateway. The only complaint message that names
 * an amount (G2) — everything earlier could still have failed.
 */
class ComplaintRefundSettled extends Notification
{
    use Queueable;

    public function __construct(public readonly Refund $refund) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database', SmsChannel::class];

        if (! blank($notifiable->email ?? null) && ($notifiable->email_verified_at ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toSms(object $notifiable): string
    {
        return 'ممسى: تم استرداد '.$this->amount().' ريال عن الحجز '.$this->code().'.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('تم استرداد مبلغ عن الحجز '.$this->code().' — مَمسَى')
            ->greeting('مرحباً '.($notifiable->name ?? ''))
            ->line('تم استرداد مبلغ '.$this->amount().' ريال عن الحجز '.$this->code().'.')
            ->line('قد يستغرق ظهور المبلغ في حسابك بضعة أيام عمل حسب بنكك.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'refund_id'    => $this->refund->id,
            'complaint_id' => $this->refund->complaint_id,
            'amount'       => round((float) $this->refund->amount, 2),
        ];
    }

    private function amount(): string
    {
        return number_format((float) $this->refund->amount, 2);
    }

    private function code(): string
    {
        return (string) ($this->refund->booking?->code ?? $this->refund->booking_id);
    }
}
