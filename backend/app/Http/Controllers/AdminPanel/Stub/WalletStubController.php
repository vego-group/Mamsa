<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel\Stub;

use App\Http\Controllers\AdminPanel\Controller;
use App\Support\StubLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STUB — contract v2.2 §5.1 admin wallets. Fixture-backed, non-production only.
 * List uses the admin Paginated<T> envelope { items, total, page, pageSize }.
 * One wallet carries a NEGATIVE availableBalance (prt_103) to exercise the signed
 * column + the frontend's negative rendering. Ledger sums to availableBalance.
 */
class WalletStubController extends Controller
{
    /** GET /admin/wallets → Paginated<PartnerWallet>. */
    public function index(Request $request): JsonResponse
    {
        $wallets = [
            $this->wallet('prt_101', 'شركة الواحة للضيافة', 'company', 4310.75, 1204.00, 38920.40, 34609.65, true, true, null),
            $this->wallet('prt_102', 'محمد الشهري', 'individual', 1240.00, 300.00, 8200.00, 6960.00, true, false, 'below_minimum'),
            $this->wallet('prt_103', 'مؤسسة السحاب', 'company', -150.00, 0.00, 12500.00, 12650.00, true, false, 'negative_balance'),
            $this->wallet('prt_104', 'نجود للوحدات', 'company', 5200.00, 900.00, 15200.00, 10000.00, false, false, 'bank_unverified'),
            $this->wallet('prt_105', 'سارة الدوسري', 'individual', 3000.00, 0.00, 3000.00, 0.00, false, false, 'bank_missing'),
        ];

        return response()->json([
            'items'    => $wallets,
            'total'    => count($wallets),
            'page'     => 1,
            'pageSize' => 10,
        ]);
    }

    /**
     * GET /admin/wallets/{partnerId} → PartnerWalletDetail.
     * `recentLedger` is a bounded preview (last 10), NOT paginated — the paginated
     * feed is the dedicated /ledger endpoint below.
     */
    public function show(Request $request, string $partnerId): JsonResponse
    {
        $wallet = $this->wallet($partnerId, 'شركة الواحة للضيافة', 'company', 4310.75, 1204.00, 38920.40, 34609.65, true, true, null);

        return response()->json($wallet + [
            'bankDetails' => [
                'iban'              => 'SA0380000000608010167519',
                'accountHolderName' => 'شركة الواحة للضيافة',
                'bankName'          => 'البنك الأهلي السعودي',
                'verified'          => true,
                'verifiedAt'        => '2026-07-01T08:00:00+03:00',
                'rejectionReason'   => null,
                'updatedAt'         => '2026-07-01T08:00:00+03:00',
            ],
            'recentLedger'  => StubLedger::entries($partnerId), // bounded preview
            'recentPayouts' => [
                [
                    'id'            => 'pay_7001',
                    'reference'     => 'PO-2026-07-0018',
                    'partnerId'     => $partnerId,
                    'periodMonth'   => '2026-07',
                    'amount'        => 1000.00,
                    'bookingsCount' => 2,
                    'currency'      => 'SAR',
                    'status'        => 'paid',
                    'paidAt'        => '2026-08-06T09:00:00+03:00',
                    'bankReference' => 'FT26080600091',
                ],
            ],
        ]);
    }

    /** GET /admin/wallets/{partnerId}/ledger?limit=&before= → { items, hasMore, nextCursor }. */
    public function ledger(Request $request, string $partnerId): JsonResponse
    {
        return response()->json(StubLedger::page($request, StubLedger::entries($partnerId)));
    }

    /** @return array<string,mixed> PartnerWallet — §2.4. */
    private function wallet(
        string $id, string $name, string $type,
        float $available, float $pending, float $lifeEarn, float $lifePaid,
        bool $bankVerified, bool $eligible, ?string $ineligibleReason,
    ): array {
        return [
            'partnerId'        => $id,
            'partnerName'      => $name,
            'partnerType'      => $type,
            'availableBalance' => $available,
            'pendingBalance'   => $pending,
            'lifetimeEarnings' => $lifeEarn,
            'lifetimePaidOut'  => $lifePaid,
            'currency'         => 'SAR',
            'bankVerified'     => $bankVerified,
            'payoutEligible'   => $eligible,
            'ineligibleReason' => $ineligibleReason,
            'lastPayoutAt'     => '2026-08-06T09:00:00+03:00',
            'updatedAt'        => '2026-08-11T18:30:00+03:00',
        ];
    }
}
