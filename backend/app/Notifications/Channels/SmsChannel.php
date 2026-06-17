<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Sms\SmsProvider;
use App\Support\PhoneNumber;
use Illuminate\Notifications\Notification;

/**
 * Custom notification channel that routes through the pluggable SmsProvider
 * (FR-100). A notification opts in by adding this class to via() and exposing
 * a toSms($notifiable): string method. Sending is best-effort — provider/network
 * failures are reported but never bubble up to break the triggering flow.
 */
class SmsChannel
{
    public function __construct(private readonly SmsProvider $sms)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $message = trim((string) $notification->toSms($notifiable));
        $to = $notifiable->routeNotificationFor('sms', $notification) ?? ($notifiable->phone ?? null);

        if ($message === '' || blank($to)) {
            return;
        }

        try {
            $this->sms->send(PhoneNumber::toE164Ksa((string) $to), $message, config('sms.sender_id'));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
