<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ICU on this server has no Arabic collation and fell back to another locale.
 *
 * The mail states the value that actually came back, not "something is wrong
 * with sorting": whoever opens it should know what to do without asking.
 */
class CollationFallback extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $actualLocale,
        public readonly string $icuVersion,
        public readonly string $phpVersion,
        public readonly string $appUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("⚠ ICU رجّع \"{$this->actualLocale}\" بدل \"ar\" — ترتيب الأبواب مختلف عن الواجهة")
            ->line("السيرفر: {$this->appUrl}")
            ->line("القيمة اللي رجعت من Collator('ar'): {$this->actualLocale} — المتوقع: ar")
            ->line("نسخة ICU: {$this->icuVersion} · PHP: {$this->phpVersion}")
            ->line('الأثر: أسماء الأبواب جوّه المبنى بقت بتترتب اللاتيني قبل العربي عند الباك اند، والواجهة (localeCompare(\'ar\')) بتحط العربي الأول. مفيش حاجة بتقع — بس المبنى بيظهر بترتيبين.')
            ->line('السبب الغالب: تحديث لـPHP أو للسيرفر نزّل ICU من غير بيانات اللغة العربية.')
            ->line('العلاج: رجّعوا امتداد intl بـICU كامل (من الاستضافة لو لزم)، وبعدين اتأكدوا بالأمر: php artisan ops:check-collation');
    }
}
