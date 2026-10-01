<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-endpoint authorisation for the admin-panel BFF (contract §4.3).
 *
 * Before this existed the BFF had authentication only: every authenticated
 * admin could call every admin endpoint, and a `finance` account could read
 * users, units and approvals it has no business seeing.
 *
 * The permission list is resolved from {@see AdminPermissions} — the SAME
 * source `/admin/me` returns to the client. That is deliberate: if the server
 * enforced a DB-seeded permission set while the client gated on a different
 * resolved list, the two would drift and the UI would either hide things the
 * server allows or offer things the server refuses.
 *
 * Usage: ->middleware('admin.can:payouts.execute')
 */
class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        // Unauthenticated requests are the auth middleware's job; this runs
        // after it, so a missing user here means the route is misconfigured.
        if (! $user) {
            return $this->deny();
        }

        $role = $user->hasRole('finance') ? 'finance' : 'superadmin';

        if (! in_array($permission, AdminPermissions::for($role), true)) {
            return $this->deny();
        }

        return $next($request);
    }

    /**
     * Flat admin-panel envelope. The code is INSUFFICIENT_PERMISSION rather than
     * the login gate's FORBIDDEN so a client can tell "you may not do this" from
     * "you may not be here at all" — the frontend accepts both.
     */
    private function deny(): Response
    {
        return response()->json([
            'message' => 'ليس لديك صلاحية لهذا الإجراء',
            'code'    => 'INSUFFICIENT_PERMISSION',
        ], 403);
    }
}
