<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The production marker is gone.
 *
 * That file is the only thing standing between a mis-set APP_DEBUG and a
 * rendered exception page full of live credentials — which is not hypothetical
 * here: production ran that way for four days and 23 hours in July 2026.
 *
 * Its absence is invisible by nature. The site serves traffic normally and the
 * guard simply never fires, so nothing else in the system will ever mention it.
 */
class ProductionMarkerMissing extends Notification
{
    use Queueable;

    public function __construct(public readonly string $path) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('⚠ علامة الإنتاج مفقودة — الحارس معطّل')
            ->line('ملف علامة الإنتاج غير موجود على هذا الخادم:')
            ->line($this->path)
            ->line('بدونه، التطبيق لن يرفض الإقلاع لو تم تفعيل APP_DEBUG — وهي الحالة التي '
                .'استمرت على الإنتاج من 2026-06-30 حتى 2026-07-05.')
            ->line('أعيدوا إنشاءه: touch '.$this->path);
    }
}
