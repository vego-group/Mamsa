<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Unit;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the unit's partner when an Admin approves or rejects their unit
 * (FR-089/FR-090/FR-091, "review results" in FR-101). In-app + email.
 */
class UnitReviewResult extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Unit $unit,
        public readonly bool $approved,
        public readonly ?string $reason = null,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->unit->unit_name;
        $url  = rtrim((string) config('app.frontend_url'), '/') . '/partner/units/' . $this->unit->id . '/edit';

        $mail = (new MailMessage())->greeting('مرحباً ' . $notifiable->name);

        if ($this->approved) {
            return $mail
                ->subject('تمت الموافقة على وحدتك')
                ->line('تمت الموافقة على وحدة "' . $name . '" وأصبحت ظاهرة الآن على المنصة.')
                ->action('عرض الوحدة', $url);
        }

        return $mail
            ->subject('تم رفض وحدتك')
            ->line('نأسف، تم رفض وحدة "' . $name . '".')
            ->line('السبب: ' . ($this->reason ?? '—'))
            ->line('يمكنك تعديل الوحدة وإعادة تقديمها للمراجعة.')
            ->action('تعديل الوحدة', $url);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'       => $this->approved ? 'unit_approved' : 'unit_rejected',
            'unit_id'    => $this->unit->id,
            'unit_name'  => $this->unit->unit_name,
            'reason'     => $this->approved ? null : $this->reason,
            'title'      => $this->approved ? 'تمت الموافقة على وحدتك' : 'تم رفض وحدتك',
            'message'    => $this->approved
                ? 'تمت الموافقة على وحدة "' . $this->unit->unit_name . '" وأصبحت ظاهرة على المنصة'
                : 'تم رفض وحدة "' . $this->unit->unit_name . '"' . ($this->reason ? ' — ' . $this->reason : ''),
            'action_url' => '/partner/units/' . $this->unit->id . '/edit',
            'icon'       => $this->approved ? 'check_circle' : 'cancel',
        ];
    }
}
