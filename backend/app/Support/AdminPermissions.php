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
     * The superadmin set — every permission literal in the matrix.
     *
     * `complaints.execute_refund` is here as well as in FINANCE, and that is
     * deliberate (v1.4 §4). The security property the split exists for is that
     * FINANCE cannot set an arbitrary amount, and it is untouched: finance does
     * not hold `complaints.approve` and executes a figure checked for exact
     * equality. Withholding execution from superadmin would add no property —
     * superadmin is already the highest authority — while creating a real
     * operational deadlock, where one absent finance account halts every refund
     * on a platform holding guests' money.
     *
     * Two people on two accounts remains the intended path. This is the visible
     * emergency exit, and an execution where approver and executor are the same
     * person is stamped `single_actor` in the audit trail so a later review can
     * find those cases without comparing columns by hand.
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
        'complaints.execute_refund',
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
