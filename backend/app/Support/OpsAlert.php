<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Where an operational alert goes.
 *
 * Extracted from ComplaintRefundService when a second caller needed it: a
 * payment landing on a booking the platform had already cancelled. Two copies
 * of "who hears about this" is how one of them quietly stops including the
 * recipient somebody added to the environment.
 *
 * The recipient list is read from config rather than a constant so adding a
 * second address is a value change, not a release. An EMPTY list falls back to
 * every active SuperAdmin — an unset variable degrades to today's behaviour
 * rather than to silence, so an alert is never lost because nobody set a key.
 */
final class OpsAlert
{
    public static function raise(object $notification): void
    {
        try {
            $recipients = config('complaints.alert_recipients');

            if (! empty($recipients)) {
                Notification::route('mail', $recipients)->notify($notification);

                return;
            }

            $admins = User::role('SuperAdmin')->where('is_active', true)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, $notification);
            }
        } catch (\Throwable $e) {
            // An alert that throws must never take down the path that raised
            // it — the caller is mid-way through handling money.
            report($e);
        }
    }
}
