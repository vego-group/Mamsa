<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel\Stub;

use App\Http\Controllers\AdminPanel\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STUB — contract v2.2 §5.2 payouts. Fixture-backed, static data. Registered
 * ONLY on non-production (routes/admin-panel.php) so prod never serves fixtures.
 * Shapes/casing/error-envelopes match the contract exactly so the frontend can
 * wire against this and swap to the real controller with zero rework.
 *
 * Error triggers (documented for the frontend):
 *   record: bankReference "DUP-REF-0001" → 409 DUPLICATE_BANK_REFERENCE
 *           partnerId     "prt_paid"      → 409 ALREADY_PAID_THIS_MONTH
 *           partnerId     "prt_ineligible"→ 409 NOT_ELIGIBLE
 *           anything else                 → 200 success
 */
class PayoutStubController extends Controller
{
    /** GET /admin/payouts/eligible → EligiblePartner[] (bare array). */
    public function eligible(): JsonResponse
    {
        return response()->json([
            [
                'partnerId'         => 'prt_101',
                'partnerName'       => 'شركة الواحة للضيافة',
                'partnerType'       => 'company',
                'amount'            => 4310.75,          // exactly what will be paid
                'bookingsCount'     => 7,
                'iban'              => 'SA0380000000608010167519',
                'bankName'          => 'البنك الأهلي السعودي',
                'accountHolderName' => 'شركة الواحة للضيافة',
                'lastPaidAt'        => '2026-07-18T09:00:00+03:00',
                'lastPaidPeriod'    => '2026-07',
            ],
            [
                'partnerId'         => 'prt_110',
                'partnerName'       => 'خالد العتيبي',
                'partnerType'       => 'individual',
                'amount'            => 2650.00,
                'bookingsCount'     => 3,
                'iban'              => 'SA4420000001234567891234',
                'bankName'          => 'مصرف الراجحي',
                'accountHolderName' => 'خالد سعد العتيبي',
                'lastPaidAt'        => null,
                'lastPaidPeriod'    => null,
            ],
        ]);
    }

    /** GET /admin/payouts/ineligible → IneligiblePartner[] — one per reason. */
    public function ineligible(): JsonResponse
    {
        return response()->json([
            [
                'partnerId'              => 'prt_102',
                'partnerName'            => 'محمد الشهري',
                'partnerType'            => 'individual',
                'availableBalance'       => 1240.00,
                'reason'                 => 'below_minimum',
                'shortfall'              => 760.00,
                'paidThisMonthReference' => null,
            ],
            [
                'partnerId'              => 'prt_104',
                'partnerName'            => 'نجود للوحدات',
                'partnerType'            => 'company',
                'availableBalance'       => 5200.00,
                'reason'                 => 'bank_unverified',
                'shortfall'              => null,
                'paidThisMonthReference' => null,
            ],
            [
                'partnerId'              => 'prt_105',
                'partnerName'            => 'سارة الدوسري',
                'partnerType'            => 'individual',
                'availableBalance'       => 3000.00,
                'reason'                 => 'bank_missing',
                'shortfall'              => null,
                'paidThisMonthReference' => null,
            ],
            [
                'partnerId'              => 'prt_paid',
                'partnerName'            => 'مؤسسة الديار',
                'partnerType'            => 'company',
                'availableBalance'       => 6000.00,
                'reason'                 => 'already_paid_this_month',
                'shortfall'              => null,
                'paidThisMonthReference' => 'PO-2026-08-0031',
            ],
            [
                'partnerId'              => 'prt_103',
                'partnerName'            => 'مؤسسة السحاب',
                'partnerType'            => 'company',
                'availableBalance'       => -150.00,
                'reason'                 => 'negative_balance',
                'shortfall'              => null,
                'paidThisMonthReference' => null,
            ],
        ]);
    }

    /**
     * POST /admin/payouts/record → { ok, payoutId, reference } | 409 error.
     *
     * `amount` and `iban` in the body are NEVER read — the core security control
     * (contract §3.3). Only partnerId + bankReference (+ optional paidAt/note)
     * influence anything, and even those only pick a fixture branch here.
     */
    public function record(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'partnerId'     => ['required', 'string'],
            'bankReference' => ['required', 'string', 'min:4', 'max:64'],
            'paidAt'        => ['sometimes', 'nullable', 'string'],
            'note'          => ['sometimes', 'nullable', 'string'],
        ]);

        // Re-validated at call time in the real impl; here, fixture triggers:
        if ($data['bankReference'] === 'DUP-REF-0001') {
            $this->fail('DUPLICATE_BANK_REFERENCE', 'رقم المرجع البنكي مستخدم من قبل', 409);
        }
        if ($data['partnerId'] === 'prt_paid') {
            $this->fail('ALREADY_PAID_THIS_MONTH', 'تم صرف مستحقات هذا الشهر بالفعل', 409);
        }
        if ($data['partnerId'] === 'prt_ineligible') {
            $this->fail('NOT_ELIGIBLE', 'الشريك غير مؤهل للصرف حالياً', 409);
        }

        return response()->json([
            'ok'       => true,
            'payoutId' => 'pay_stub_0001',
            'reference' => 'PO-2026-08-0042',
        ]);
    }
}
