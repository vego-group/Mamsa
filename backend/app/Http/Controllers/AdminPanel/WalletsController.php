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
                'rejectionReason'   => $bank->rejection_reason,
                'updatedAt'         => $bank->updated_at?->toIso8601ZuluString(),
            ] : null,

            // A bounded preview — the paginated feed is the /ledger endpoint.
            'recentLedger' => PartnerLedgerEntry::where('partner_user_id', $partner->id)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()
                ->map(fn ($e) => $this->entry($e))->values(),

            'recentPayouts' => Payout::where('partner_user_id', $partner->id)
                ->orderByDesc('paid_at')->limit(10)->get()
                ->map(fn (Payout $p) => [
                    'id'            => 'pay_'.$p->id,
                    'reference'     => $p->reference,
                    'partnerId'     => 'prt_'.$partner->id,
                    'periodMonth'   => $p->period_month,
                    'amount'        => round($p->amount, 2),
                    'bookingsCount' => (int) $p->bookings_count,
                    'currency'      => $p->currency,
                    'status'        => $p->status,
                    'paidAt'        => $p->paid_at?->toIso8601ZuluString(),
                    'bankReference' => $p->bank_reference,
                ])->values(),
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
            'refId'        => $e->ref_type === 'booking' ? 'b_'.$e->ref_id : 'po_'.$e->ref_id,
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
