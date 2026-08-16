<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\BankDetail;
use App\Models\PartnerWallet;
use App\Models\Payout;
use App\Models\User;
use App\Services\PartnerWalletService;
use App\Services\PayoutEligibility;
use App\Support\Iban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The monthly payout run — contract v2.2 §5.2.
 *
 * The accountant executes transfers in their bank, then records each one here.
 * Recording is therefore a report of something that already happened, which is
 * why a payout has no pending state and why the amount is never taken from the
 * request: the server decides what was owed.
 */
class PayoutsController extends Controller
{
    public function __construct(
        private readonly PayoutEligibility $eligibility,
        private readonly PartnerWalletService $wallet,
    ) {}

    /**
     * GET /admin/payouts?periodMonth=&partnerId=&status=&page=&pageSize=
     *
     * The month-level view of what was transferred. `recentPayouts` on a wallet
     * answers "what did we pay THIS partner"; reconciling a month asks the other
     * question, and there was no way to ask it.
     *
     * Standard paginated envelope plus two aggregates over the WHOLE filter (not
     * the page) — an accountant closing a month needs the month's figure, and it
     * has to exclude reversed transfers because that money came back.
     */
    public function index(Request $request): JsonResponse
    {
        $args  = $this->listArgs($request);
        $query = Payout::query()->with('partner:id,name');

        if ($month = $this->cleanParam($request->query('periodMonth'))) {
            // A malformed month silently matches nothing, and "no rows" reads as
            // "we paid nobody in July" — a wrong answer to a reconciliation
            // question is worse than an error.
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                $this->fail('VALIDATION_ERROR', 'صيغة الشهر غير صالحة (YYYY-MM)', 422);
            }
            $query->where('period_month', $month);
        }

        if ($partnerId = $this->cleanParam($request->query('partnerId'))) {
            $query->where('partner_user_id', (int) (str_starts_with($partnerId, 'prt_') ? substr($partnerId, 4) : $partnerId));
        }

        if ($status = $this->cleanParam($request->query('status'))) {
            $query->where('status', $status);
        }

        $totals = (clone $query)->where('status', Payout::STATUS_PAID)
            ->selectRaw('COALESCE(SUM(amount),0) as amount, COALESCE(SUM(bookings_count),0) as bookings')
            ->first();

        $page = $this->queryList($query, $args, ['reference', 'bank_reference'], [
            'paidAt'      => 'paid_at',
            'amount'      => 'amount',
            'periodMonth' => 'period_month',
        ], ['paid_at', 'desc']);

        return response()->json([
            'items'    => collect($page->items())->map(fn (Payout $p) => $this->payoutRow($p))->values(),
            'total'    => $page->total(),
            'page'     => $page->currentPage(),
            'pageSize' => $page->perPage(),

            'totalAmount'        => round((float) $totals->amount, 2),
            'totalBookingsCount' => (int) $totals->bookings,
        ]);
    }

    /**
     * One payout row shape, shared with `recentPayouts` on the wallet detail so
     * the two surfaces cannot drift.
     *
     * @return array<string, mixed>
     */
    public static function payoutRow(Payout $p, ?string $partnerName = null): array
    {
        return [
            'id'            => 'pay_'.$p->id,
            'reference'     => $p->reference,
            'partnerId'     => 'prt_'.$p->partner_user_id,
            'partnerName'   => $partnerName ?? $p->partner?->name ?? '',
            'periodMonth'   => $p->period_month,
            'amount'        => round($p->amount, 2),
            'bookingsCount' => (int) $p->bookings_count,
            'currency'      => $p->currency,
            'status'        => $p->status,
            'paidAt'        => $p->paid_at?->toIso8601ZuluString(),
            'bankReference' => $p->bank_reference,
            // Where it went, frozen at payout time — a transfer record without a
            // destination is half a record when a payment is disputed.
            'ibanMasked'    => $p->iban_masked,
            'bankName'      => $p->bank_name,
            'note'          => $p->note,
        ];
    }

    /** GET /admin/payouts/eligible → EligiblePartner[] (bare array). */
    public function eligible(): JsonResponse
    {
        $rows = [];

        foreach ($this->partners() as $partner) {
            $wallet = $partner->partnerWallet ?? PartnerWallet::firstOrCreate(['partner_user_id' => $partner->id]);
            $bank   = $partner->bankDetail;

            if ($this->eligibility->reason($partner, $wallet, $bank, adminView: true) !== null) {
                continue;
            }

            $payable = $this->eligibility->payable($partner->id);

            // Eligible on balance but with no finished stay left to attach the
            // money to — a transfer here would be an unbacked payout.
            if ($payable['amount'] <= 0) {
                continue;
            }

            $last = Payout::where('partner_user_id', $partner->id)
                ->where('status', Payout::STATUS_PAID)->latest('paid_at')->first();

            $rows[] = [
                'partnerId'         => 'prt_'.$partner->id,
                'partnerName'       => $partner->name,
                'partnerType'       => $partner->partnerDetail?->type ?? 'individual',
                'amount'            => $payable['amount'],       // exactly what will be paid
                'bookingsCount'     => $payable['count'],
                'iban'              => $bank?->iban,
                'bankName'          => $bank?->bank_name,
                'accountHolderName' => $bank?->account_holder_name,
                'lastPaidAt'        => $last?->paid_at?->toIso8601ZuluString(),
                'lastPaidPeriod'    => $last?->period_month,
            ];
        }

        return response()->json($rows);
    }

    /** GET /admin/payouts/ineligible → IneligiblePartner[] (bare array). */
    public function ineligible(): JsonResponse
    {
        $rows    = [];
        $minimum = (float) config('wallet.min_payout_amount');

        foreach ($this->partners() as $partner) {
            $wallet = $partner->partnerWallet ?? PartnerWallet::firstOrCreate(['partner_user_id' => $partner->id]);
            $reason = $this->eligibility->reason($partner, $wallet, $partner->bankDetail, adminView: true);

            if ($reason === null) {
                continue;
            }

            $available = round($wallet->available_balance, 2);
            $paid      = $reason === 'already_paid_this_month'
                ? $this->eligibility->paidThisMonth($partner->id)
                : null;

            $rows[] = [
                'partnerId'              => 'prt_'.$partner->id,
                'partnerName'            => $partner->name,
                'partnerType'            => $partner->partnerDetail?->type ?? 'individual',
                'availableBalance'       => $available,
                'reason'                 => $reason,
                'shortfall'              => $reason === 'below_minimum' ? round($minimum - $available, 2) : null,
                'paidThisMonthReference' => $paid?->reference,
            ];
        }

        return response()->json($rows);
    }

    /**
     * POST /admin/payouts/record
     *
     * `amount` and `iban` are accepted in the body and deliberately IGNORED —
     * the whole point of the control. An accountant states which partner they
     * paid and the bank's reference; the server decides what that was worth and
     * where it went. Anything else would let a typo, or a compromised admin
     * session, set the size and destination of a real transfer.
     */
    public function record(Request $request): JsonResponse
    {
        // Arabic messages: the admin panel renders `message` straight to the
        // user, so a Laravel default would surface as English on an otherwise
        // Arabic screen.
        $data = $this->validate($request, [
            'partnerId'     => ['required', 'string'],
            'bankReference' => ['required', 'string', 'min:4', 'max:64'],
            'paidAt'        => ['sometimes', 'nullable', 'date'],
            'note'          => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'partnerId.required'     => 'الشريك مطلوب',
            'bankReference.required' => 'رقم المرجع البنكي مطلوب',
            'bankReference.min'      => 'رقم المرجع البنكي قصير جداً',
            'bankReference.max'      => 'رقم المرجع البنكي طويل جداً',
            'paidAt.date'            => 'تاريخ التحويل غير صالح',
            'note.max'               => 'الملاحظة طويلة جداً',
        ]);

        $partner = $this->partner($data['partnerId']);

        // The bank's own reference is the idempotency key: a double-submitted
        // form must not record the same transfer twice.
        if (Payout::where('bank_reference', $data['bankReference'])->exists()) {
            $this->fail('DUPLICATE_BANK_REFERENCE', 'رقم المرجع البنكي مستخدم من قبل', 409);
        }

        if ($this->eligibility->paidThisMonth($partner->id)) {
            $this->fail('ALREADY_PAID_THIS_MONTH', 'تم صرف مستحقات هذا الشهر بالفعل', 409);
        }

        // Re-checked at the moment of recording, not trusted from the list the
        // accountant loaded: minutes may have passed and a refund landed.
        if ($this->eligibility->reason($partner, adminView: true) !== null) {
            $this->fail('NOT_ELIGIBLE', 'الشريك غير مؤهل للصرف حالياً', 409);
        }

        $bank    = BankDetail::where('partner_user_id', $partner->id)->firstOrFail();
        $payable = $this->eligibility->payable($partner->id);

        if ($payable['amount'] <= 0) {
            $this->fail('NOT_ELIGIBLE', 'لا توجد حجوزات مستحقة للصرف', 409);
        }

        $payout = DB::transaction(function () use ($partner, $bank, $payable, $data) {
            $payout = Payout::create([
                'partner_user_id' => $partner->id,
                'reference'       => $this->nextReference(),
                'period_month'    => $this->eligibility->periodMonth($partner->id),
                'amount'          => $payable['amount'],
                'bookings_count'  => $payable['count'],
                'currency'        => config('wallet.currency'),
                // Frozen at payout time: a partner who changes bank later must
                // still see which account the old money went to.
                'iban_masked'     => Iban::mask($bank->iban),
                'bank_name'       => $bank->bank_name,
                'status'          => Payout::STATUS_PAID,
                'paid_at'         => isset($data['paidAt']) && $data['paidAt']
                    ? \Illuminate\Support\Carbon::parse($data['paidAt'])
                    : now(),
                'bank_reference'  => $data['bankReference'],
                'note'            => $data['note'] ?? null,
                'created_by_admin_id' => request()->user()?->id,
            ]);

            // Attach the covered stays BEFORE the ledger debit, so the amount
            // and the bookings behind it are written in the same transaction.
            $this->eligibility->payableBookings($partner->id)->update(['payout_id' => $payout->id]);

            $this->wallet->recordPayout($payout);

            return $payout;
        });

        return response()->json([
            'ok'        => true,
            'payoutId'  => 'pay_'.$payout->id,
            'reference' => $payout->reference,
        ]);
    }

    /* ---- helpers ---- */

    /** Every partner account, with the relations both listings need. */
    private function partners()
    {
        return User::query()
            ->whereHas('partnerDetail')
            ->with(['partnerDetail', 'partnerWallet', 'bankDetail'])
            ->orderBy('id')
            ->get();
    }

    private function partner(string $id): User
    {
        $raw = str_starts_with($id, 'prt_') ? substr($id, 4) : $id;

        $partner = User::whereKey($raw)->whereHas('partnerDetail')->first();

        if (! $partner) {
            $this->fail('NOT_FOUND', 'الشريك غير موجود', 404);
        }

        return $partner;
    }

    /**
     * PO-YYYY-MM-NNNN, sequential within the month.
     *
     * Per-partner rows share a month, so the month alone cannot identify a
     * transfer — the sequence is what makes the reference the accountant quotes
     * unique.
     */
    private function nextReference(): string
    {
        $prefix = 'PO-'.now()->format('Y-m').'-';
        $last   = Payout::where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');
        $next   = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
