<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the applicant when an Admin approves or rejects their partner
 * application. The approval email carries the partner dashboard login link.
 */
class PartnerApplicationResult extends Notification
{
    use Queueable;

    public function __construct(
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
        $mail = (new MailMessage())->greeting('مرحباً '.$notifiable->name);

        if ($this->approved) {
            return $mail
                ->subject('تمت الموافقة على طلب شراكتك في ممسى')
                ->line('يسعدنا إبلاغك بقبول طلب انضمامك كشريك في منصة ممسى.')
                ->line('يمكنك الآن الدخول إلى لوحة تحكم الشركاء وإضافة وحداتك.')
                ->action('الدخول إلى لوحة التحكم', $this->dashboardUrl());
        }

        return $mail
            ->subject('بخصوص طلب شراكتك في ممسى')
            ->line('نأسف، لم تتم الموافقة على طلب انضمامك كشريك في الوقت الحالي.')
            ->line('السبب: '.($this->reason ?? '—'))
            ->line('يمكنك تعديل بياناتك وإعادة التقديم في أي وقت.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'       => $this->approved ? 'partner_approved' : 'partner_rejected',
            'reason'     => $this->approved ? null : $this->reason,
            'title'      => $this->approved ? 'تمت الموافقة على طلب الشراكة' : 'تم رفض طلب الشراكة',
            'message'    => $this->approved
                ? 'تم قبولك كشريك في منصة ممسى — يمكنك الآن إدارة وحداتك من لوحة التحكم'
                : 'لم تتم الموافقة على طلب الشراكة'.($this->reason ? ' — '.$this->reason : ''),
            'action_url' => $this->approved ? '/partner/dashboard' : null,
            'icon'       => $this->approved ? 'check_circle' : 'cancel',
        ];
    }

    /** Absolute dashboard URL — dedicated DASHBOARD_URL env wins, frontend URL as fallback. */
    private function dashboardUrl(): string
    {
        return (string) (config('app.dashboard_url')
            ?: rtrim((string) config('app.frontend_url'), '/').'/partner/dashboard');
    }
}
