<?php

declare(strict_types=1);

namespace App\Observers\AdminPanel\Concerns;

use App\Models\User;
use App\Notifications\AdminPanel\AdminAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Fan-out helper for the AdminPanel observers: deliver an in-app AdminAlert to
 * every Admin / SuperAdmin. Best-effort — a delivery failure (or missing roles
 * in a bare test DB) is reported but NEVER breaks the domain write that fired it.
 */
trait NotifiesAdmins
{
    /** @param array<string, mixed> $entity */
    protected function notifyAdmins(string $category, string $title, string $message, array $entity = []): void
    {
        try {
            $admins = User::role(['Admin', 'SuperAdmin'], 'web')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new AdminAlert($category, $title, $message, $entity));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
