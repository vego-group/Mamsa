<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Permit;
use App\Models\PermitReminder;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your tourism permit expires in N days" — and, on the day, "it has expired".
 *
 * Sent once per permit per threshold; the ledger that guarantees that is
 * {@see PermitReminder}, not this class.
 *
 * The channel set widens as the date approaches. Every threshold writes an
 * in-app notification — that is what the dashboard banner reads, and it is the
 * only channel that reaches a partner who registered by phone and never gave
 * an email address (one in three on production). Mail goes out when there is
 * an address. SMS is held back for the last three — 7 days, 1 day, and the day
 * itself — because a text message is an interruption and spending it early is
 * how partners learn to ignore them.
 */
class PermitExpiring extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Permit $permit,
        /** Days remaining: 60, 30, 14, 7, 1, or 0 on the day it lapses. */
        public readonly int $threshold,
        /** What the partner sees this permit as covering. */
        public readonly string $unitName,
        /**
         * The listing to send them to. A banner that can say "your permit
         * expires" but not WHICH listing, and offers no way to act on it,
         * makes the reader go and find both themselves.
         */
        public readonly ?int $unitId = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (filled($notifiable->routeNotificationFor('mail'))) {
            $channels[] = 'mail';
        }

        if (in_array($this->threshold, PermitReminder::URGENT, true)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    private function lapsed(): bool
    {
        return $this->threshold === 0;
    }

    private function headline(): string
    {
        return $this->lapsed()
            ? 'انتهى تصريح وحدتك "'.$this->unitName.'" — الإعلان متوقف عن الظهور'
            : 'تصريح وحدتك "'.$this->unitName.'" ينتهي خلال '.$this->threshold.' يوم';
    }

    public function toSms(object $notifiable): string
    {
        return 'ممسى: '.$this->headline().'. جدّد التصريح من لوحة الشريك.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->headline())
            ->greeting('مرحباً')
            ->line($this->headline().'.')
            ->line('تاريخ انتهاء التصريح: '.$this->permit->expires_at?->toDateString().'.');

        // Said plainly, because it is the part that costs money: the calendar
        // closes ahead of the date rather than on it.
        $mail->line($this->lapsed()
            ? 'الإعلان لا يستقبل حجوزات جديدة حتى يتم تجديد التصريح.'
            : 'لا يمكن استقبال حجوزات تنتهي إقامتها بعد هذا التاريخ.');

        return $mail->line('يمكنك رفع التصريح الجديد من لوحة الشريك، والإعلان يظل ظاهراً أثناء المراجعة.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'permit_expiring',
            'permit_id' => $this->permit->id,
            'threshold' => $this->threshold,
            'expires_at' => $this->permit->expires_at?->toDateString(),
            'unit_id' => $this->unitId,
            'unit_name' => $this->unitName,
            'title' => $this->headline(),
            // `body` and `href` are the two keys the notification list actually
            // renders. Without them the banner arrives as a title with no
            // detail and no way to act, and the reader has to go and find both
            // the listing and the form themselves.
            'body' => $this->lapsed()
                ? 'لا يمكن استقبال حجوزات جديدة حتى يتم تجديد التصريح.'
                : 'لا يمكن استقبال حجوزات تنتهي إقامتها بعد '.($this->permit->expires_at?->toDateString() ?? '').'.',
            'href' => $this->unitId ? "/units/{$this->unitId}/permit/renew" : null,
        ];
    }
}
