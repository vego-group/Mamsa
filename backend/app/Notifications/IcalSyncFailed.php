<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\UnitIcalFeed;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Partner-dashboard contract §8 — `sync_failed`. Fired when an external iCal
 * feed errors on sync. In-app + SMS to the partner.
 */
class IcalSyncFailed extends Notification
{
    use Queueable;

    public function __construct(public readonly UnitIcalFeed $feed) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return 'ممسى: تعذّرت مزامنة تقويم "'.$this->feed->source.'" لوحدة '
            .($this->feed->unit->unit_name ?? '').'. يرجى مراجعة الرابط.';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'sync_failed',
            'unit_id'    => $this->feed->unit_id,
            'feed_id'    => $this->feed->id,
            'source'     => $this->feed->source,
            'title'      => 'فشل مزامنة التقويم',
            'body'       => 'تعذّرت مزامنة تقويم "'.$this->feed->source.'" — تحقق من صحة الرابط.',
            'action_url' => '/calendar',
            'icon'       => 'sync_problem',
        ];
    }
}
