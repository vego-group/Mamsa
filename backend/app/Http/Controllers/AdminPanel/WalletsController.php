<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\PartnerLedgerEntry;
use App\Models\PartnerWallet;
use App\Models\Payout;
use App\Models\User;
use App\Services\PartnerWalletService;
use App\Services\PayoutEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin view of partner wallets — contract v2.2 §5.1. Read-only: balances are
 * moved by earnings and payouts, never by this screen.
 */
class WalletsController extends Controller
{
    public function __construct(
        private readonly PayoutEligibility $eligibility,
        private readonly PartnerWalletService $wallet,
    ) {}

    /** GET /admin/wallets → Paginated<PartnerWallet>. */
    public function index(Request $request): JsonResponse
    {
        $args = $this->listArgs($request);

        $query = User::query()
            ->whereHas('partnerDetail')
            ->with(['partnerDetail', 'partnerWallet', 'bankDetail']);

        $page = $this->queryList($query, $args, ['name', 'phone'], ['partnerName' => 'name'], ['id', 'asc']);

        return $this->items($page, fn (User $u) => $this->row($u));
    }

    /**
     * GET /admin/wallets/stats → the KPI row above the wallets table.
     *
     * Every count comes from the SAME PayoutEligibility service as
     * /admin/payouts/ineligible, and `eligibleAmount` from the same payable()
     * the run actually pays — so a tile can never promise a row the run does
     * not list. A separately-computed version of these numbers would be worse
     * than no tiles at all.
     *
     * Must stay registered BEFORE wallets/{partnerId}, or it resolves as a
     * partner with the id "stats" and dies as NOT_FOUND after authentication —
     * alive from outside, silently broken for a signed-in admin.
     */
    public function stats(): JsonResponse
    {
        $partners = User::query()->whereHas('partnerDetail')
            ->with(['partnerWallet', 'bankDetail'])->get();

        $t = [
            'totalAvailable' => 0.0, 'totalPending' => 0.0, 'eligibleAmount' => 0.0,
            'eligibleCount' => 0, 'belowMinimumCount' => 0, 'bankUnverifiedCount' => 0,
            'bankMissingCount' => 0, 'negativeBalanceCount' => 0, 'alreadyPaidCount' => 0,
            'suspendedCount' => 0, 'nothingPayableCount' => 0,
        ];

        foreach ($partners as $partner) {
            $wallet = $partner->partnerWallet ?? PartnerWallet::firstOrCreate(['partner_user_id' => $partner->id]);

            $t['totalAvailable'] += (float) $wallet->available_balance;
            $t['totalPending']   += $this->wallet->pendingBalance($partner->id);

            $reason = $this->eligibility->reason($partner, $wallet, $partner->bankDetail, adminView: true);

            if ($reason !== null) {
                $t[match ($reason) {
                    'already_paid_this_month' => 'alreadyPaidCount',
                    'partner_suspended'       => 'suspendedCount',
                    'negative_balance'        => 'negativeBalanceCount',
                    'bank_missing'            => 'bankMissingCount',
                    'bank_unverified'         => 'bankUnverifiedCount',
                    default                   => 'belowMinimumCount',
                }]++;

                continue;
            }

            // Eligible on balance but with no finished stay left to attach the
            // money to. /admin/payouts/eligible drops these, so the tile has to
            // as well — an unbacked payout is not a payout.
            $payable = $this->eligibility->payable($partner->id);

            if ($payable['amount'] <= 0) {
                $t['nothingPayableCount']++;

                continue;
            }

            $t['eligibleCount']++;
            $t['eligibleAmount'] += $payable['amount'];
        }

        return response()->json([
            'totalAvailable'       => round($t['totalAvailable'], 2),
            'totalPending'         => round($t['totalPending'], 2),
            'eligibleCount'        => $t['eligibleCount'],
            'eligibleAmount'       => round($t['eligibleAmount'], 2),
            'belowMinimumCount'    => $t['belowMinimumCount'],
            'bankUnverifiedCount'  => $t['bankUnverifiedCount'],
            'bankMissingCount'     => $t['bankMissingCount'],
            'negativeBalanceCount' => $t['negativeBalanceCount'],

            // Additive: without these three the buckets do not sum, and a row
            // of counts that does not add up to the partner count is a row an
            // accountant stops trusting.
            'alreadyPaidCount'     => $t['alreadyPaidCount'],
            'suspendedCount'       => $t['suspendedCount'],
            'nothingPayableCount'  => $t['nothingPayableCount'],
            'partnersCount'        => $partners->count(),

            'currency'             => config('wallet.currency'),
            'minimumPayout'        => (float) config('wallet.min_payout_amount'),
        ]);
    }

    /** GET /admin/wallets/{partnerId} → PartnerWalletDetail. */
    public function show(Request $request, string $partnerId): JsonResponse
    {
        $partner = $this->partner($partnerId);
        $bank    = $partner->bankDetail;

        return response()->json($this->row($partner) + [
            'bankDetails' => $bank ? [
                'iban'              => $bank->iban,
                'accountHolderName' => $bank->account_holder_name,
                'bankName'          => $bank->bank_name,
                'verified'          => (bool) $bank->verified,
                'verifiedAt'        => $bank->verified_at?->toIso8601ZuluString(),
                // Additive: who approved this destination, for the audit trail.
                'verifiedBy'        => $bank->verifiedByAdmin?->name,
                'rejectionReason'   => $bank->rejection_reason,
                'updatedAt'         => $bank->updated_at?->toIso8601ZuluString(),
            ] : null,

            // A bounded preview — the paginated feed is the /ledger endpoint.
            'recentLedger' => PartnerLedgerEntry::where('partner_user_id', $partner->id)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()
                ->map(fn ($e) => $this->entry($e))->values(),

            // Same row shape as GET /admin/payouts, built by the same function:
            // two shapes for one thing is how a client ends up with two
            // renderers and a field that exists on only one of them.
            'recentPayouts' => Payout::where('partner_user_id', $partner->id)
                ->orderByDesc('paid_at')->limit(10)->get()
                ->map(fn (Payout $p) => PayoutsController::payoutRow($p, $partner->name))->values(),
        ]);
    }

    /** GET /admin/wallets/{partnerId}/ledger?limit=&before= → { items, hasMore, nextCursor }. */
    public function ledger(Request $request, string $partnerId): JsonResponse
    {
        $partner = $this->partner($partnerId);
        $limit   = min(100, max(1, (int) $request->query('limit', '20')));

        $query = PartnerLedgerEntry::where('partner_user_id', $partner->id)
            ->orderByDesc('created_at')->orderByDesc('id');

        if ($before = $request->query('before')) {
            $query->where('created_at', '<', Carbon::parse($before));
        }

        // One extra row answers "is there another page" without a second count
        // query — the ledger only grows, so a count would be stale anyway.
        $rows    = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items   = $rows->take($limit);

        return response()->json([
            'items'      => $items->map(fn ($e) => $this->entry($e))->values(),
            'hasMore'    => $hasMore,
            'nextCursor' => $hasMore ? $items->last()?->created_at?->toIso8601ZuluString() : null,
        ]);
    }

    /**
     * POST /admin/wallets/{partnerId}/bank/verify
     *
     * The control that lets money leave the platform toward a specific account
     * number. Deliberately NOT available to the finance role: finance records
     * transfers, so letting the same role also approve destinations would put
     * both halves of a payment in one pair of hands.
     */
    public function verifyBank(Request $request, string $partnerId): JsonResponse
    {
        $bank = $this->bank($partnerId);

        $bank->update([
            'verified'             => true,
            'verified_at'          => now(),
            'verified_by_admin_id' => $request->user()?->id,
            'rejection_reason'     => null,
        ]);

        return $this->ok();
    }

    /**
     * POST /admin/wallets/{partnerId}/bank/reject — { reason }
     *
     * The reason reaches the partner verbatim on GET /me/bank-details, so it
     * has to say what to fix: this is the only channel telling them why they
     * are not being paid.
     */
    public function rejectBank(Request $request, string $partnerId): JsonResponse
    {
        $data = $this->validate($request, [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'سبب الرفض مطلوب']);

        $bank = $this->bank($partnerId);

        $bank->update([
            'verified'             => false,
            'verified_at'          => null,
            'verified_by_admin_id' => null,
            'rejection_reason'     => $data['reason'],
        ]);

        return $this->ok();
    }

    private function bank(string $partnerId): \App\Models\BankDetail
    {
        $bank = $this->partner($partnerId)->bankDetail;

        if (! $bank) {
            $this->fail('NOT_FOUND', 'لا يوجد حساب بنكي لهذا الشريك', 404);
        }

        return $bank;
    }

    /* ---- transformers ---- */

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        $wallet = $u->partnerWallet ?? PartnerWallet::firstOrCreate(['partner_user_id' => $u->id]);
        $reason = $this->eligibility->reason($u, $wallet, $u->bankDetail, adminView: true);
        $last   = Payout::where('partner_user_id', $u->id)
            ->where('status', Payout::STATUS_PAID)->latest('paid_at')->first();

        return [
            'partnerId'        => 'prt_'.$u->id,
            'partnerName'      => $u->name,
            'partnerType'      => $u->partnerDetail?->type ?? 'individual',
            'availableBalance' => round($wallet->available_balance, 2),
            'pendingBalance'   => $this->wallet->pendingBalance($u->id),
            'lifetimeEarnings' => round($wallet->lifetime_earnings, 2),
            'lifetimePaidOut'  => round((float) Payout::where('partner_user_id', $u->id)
                ->where('status', Payout::STATUS_PAID)->sum('amount'), 2),
            'currency'         => config('wallet.currency'),
            'bankVerified'     => (bool) $u->bankDetail?->verified,
            'payoutEligible'   => $reason === null,
            'ineligibleReason' => $reason,
            'lastPayoutAt'     => $last?->paid_at?->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    private function entry(PartnerLedgerEntry $e): array
    {
        return [
            'id'           => 'led_'.$e->id,
            'type'         => $e->type,
            'amount'       => round($e->amount, 2),
            'balanceAfter' => round($e->balance_after, 2),
            'refType'      => $e->ref_type,
            // 'pay_' to match this surface's payout id, so a ledger row
            // deep-links to a payout that actually resolves. (The partner
            // surface uses 'po_' throughout — each surface is internally
            // consistent; the ids are not shared between them.)
            'refId'        => $e->ref_type === 'booking' ? 'b_'.$e->ref_id : 'pay_'.$e->ref_id,
            'refCode'      => $e->ref_code ?? '',
            'description'  => $e->description ?? '',
            'createdAt'    => $e->created_at?->toIso8601ZuluString(),
        ];
    }

    private function partner(string $id): User
    {
        $raw     = str_starts_with($id, 'prt_') ? substr($id, 4) : $id;
        $partner = User::whereKey($raw)->whereHas('partnerDetail')
            ->with(['partnerDetail', 'partnerWallet', 'bankDetail'])->first();

        if (! $partner) {
            $this->fail('NOT_FOUND', 'الشريك غير موجود', 404);
        }

        return $partner;
    }
}
