<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Permits\PermitExpiry;
use Illuminate\Http\JsonResponse;

/**
 * The flags a frontend needs to know at RUN time.
 *
 * All three apps read these from `NEXT_PUBLIC_*`, which Next.js inlines at
 * BUILD time — so flipping one on the server does nothing until somebody
 * rebuilds and redeploys three applications. `MULTI_UNIT_ENABLED` and
 * `PERMIT_EXPIRY_REQUIRED` are both meant to be flipped by an operator when
 * the data is ready, which is exactly the case a build-time constant cannot
 * serve.
 *
 * Public and unauthenticated on purpose: these are switches, not secrets —
 * whether the platform currently accepts multi-unit listings is visible from
 * the UI of anyone who opens it. Nothing here names a server, a key or a
 * count. Anything that would is not a flag and does not belong in this
 * response.
 */
class RuntimeConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'flags' => [
                // May a partner turn one listing into a building?
                'multiUnitEnabled' => (bool) config('units.multi_unit_enabled'),
                // Must a listing carry a permit expiry date to be submitted?
                'permitExpiryRequired' => (bool) config('permits.expiry_required'),
                // May the legacy Bearer surfaces still write units? Retired
                // everywhere, and here so a client can explain a 410 rather
                // than reporting it as an outage.
                'legacyUnitWritesEnabled' => (bool) config('units.legacy_unit_writes'),
            ],
            // How many days before expiry a permit reads as "expiring" — the
            // consoles colour a badge with it, and hard-coding 30 in three
            // apps is how they end up disagreeing with the daily job.
            'permitWarningDays' => PermitExpiry::warningDays(),
        ]);
    }
}
