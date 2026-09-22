<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolved permission sets per admin role — contract v2.2 §4.3.
 *
 * The frontend gates on the flat `permissions[]` array returned by /admin/me,
 * never on the role string, so the role→permission mapping lives here (server
 * side) and is the single source of truth. Server-side enforcement of each
 * permission is a separate concern (middleware); this only resolves the list.
 */
final class AdminPermissions
{
    /** Every permission literal in the matrix (the superadmin set). */
    public const ALL = [
        'dashboard.view',
        'users.view', 'users.manage',
        'partners.view', 'partners.manage',
        'units.view', 'units.manage',
        'approvals.view', 'approvals.manage',
        'bookings.view',
        'cancellations.view', 'cancellations.manage',
        'wallets.view', 'wallets.adjust',
        'payouts.view', 'payouts.execute', 'payouts.reverse', 'payouts.manage',
        'reports.financial', 'reports.operational',
        'notifications.view', 'profile.view',
    ];

    /** Finance can view finances + record a transfer; nothing destructive. */
    public const FINANCE = [
        'partners.view',
        'bookings.view',
        'cancellations.view',
        'wallets.view',
        'payouts.view', 'payouts.execute',
        'reports.financial',
        'notifications.view', 'profile.view',
    ];

    /** @return list<string> resolved, flat permission list for the role. */
    public static function for(string $role): array
    {
        return match ($role) {
            'finance' => self::FINANCE,
            default   => self::ALL, // superadmin
        };
    }
}
