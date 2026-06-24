<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Unit;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired to Admins/SuperAdmins when a partner submits a unit for review
 * (FR-086/FR-101). Delivered in-app (database) and by email.
 */
class NewUnitRequest extends Notification
{
    use Queueable;

    public function __construct(public readonly Unit $unit)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('طلب وحدة جديد بانتظار المراجعة')
            ->greeting('مرحباً ' . $notifiable->name)
            ->line('تم تقديم وحدة جديدة للمراجعة من قبل ' . ($this->unit->owner->name ?? 'شريك') . '.')
            ->line('الوحدة: ' . $this->unit->unit_name . ' (' . $this->unit->code . ') - ' . $this->unit->city)
            ->action('مراجعة الطلب', rtrim((string) config('app.frontend_url'), '/') . '/admin/requests/' . $this->unit->id)
            ->line('يرجى مراجعة الطلب في أقرب وقت ممكن.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'new_unit_request',
            'unit_id'    => $this->unit->id,
            'unit_name'  => $this->unit->unit_name,
            'unit_code'  => $this->unit->code,
            'city'       => $this->unit->city,
            'partner'    => $this->unit->owner->name ?? null,
            'title'      => 'طلب وحدة جديد',
            'message'    => 'تم تقديم وحدة "' . $this->unit->unit_name . '" للمراجعة',
            'action_url' => '/admin/requests/' . $this->unit->id,
            'icon'       => 'home_work',
        ];
    }
}
