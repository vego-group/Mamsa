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
    /**
     * The superadmin set.
     *
     * NOT "every literal in the matrix" any more, which is what it used to be.
     * `complaints.execute_refund` is deliberately absent: the complaints
     * contract splits deciding what is owed from moving the money, and a role
     * that can do both is the thing that split exists to prevent (T12). It is
     * the first permission superadmin does not hold, so read the omission as
     * intentional rather than as an oversight to be tidied up.
     */
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
        'complaints.view', 'complaints.review', 'complaints.approve',
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

        // Finance executes a refund but cannot decide its size: the amount is
        // fixed beforehand by a superadmin and checked for exact equality at
        // execution. That is what keeps this from amounting to `wallets.adjust`,
        // which finance deliberately does not hold — a complaint refund debits
        // a partner wallet, so an unbounded version of this would be that
        // permission by another name.
        'complaints.view', 'complaints.execute_refund',

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
