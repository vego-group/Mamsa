<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Stub;

use App\Http\Controllers\Dashboard\DashboardController;
use App\Support\StubLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STUB — contract v2.2 §6 partner wallet. Fixture-backed, non-production only.
 * Partner-surface camelCase; ledger entries sum to availableBalance (4310.75).
 * `commission` is never itemised on the partner side (contract §6).
 */
class WalletStubController extends DashboardController
{
    /** GET /wallet → PartnerWalletSummary. */
    public function summary(Request $request): JsonResponse
    {
        return $this->ok([
            'availableBalance' => 4310.75,
            'pendingBalance'   => 1204.00,
            'lifetimeEarnings' => 38920.40,
            'lifetimePaidOut'  => 34609.65,
            'currency'         => 'SAR',
            'minPayoutAmount'  => 2000.00,
            'payoutEligible'   => true,
            'ineligibleReason' => null,
            'nextPayoutDate'   => '2026-09-01',
            'bankVerified'     => true,
            'lastPayoutAt'     => '2026-08-06T09:00:00+03:00',
            'lastPayoutAmount' => 1000.00,
        ]);
    }

    /**
     * GET /wallet/ledger?limit=&before= → { items, hasMore, nextCursor }.
     * Cursor pagination: the ledger grows without bound, so it is never a full dump.
     */
    public function ledger(Request $request): JsonResponse
    {
        return response()->json(StubLedger::page($request, StubLedger::entries('prt_101')));
    }
}
