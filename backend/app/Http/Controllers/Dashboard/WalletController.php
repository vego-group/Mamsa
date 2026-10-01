<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\BankDetail;
use App\Models\PartnerLedgerEntry;
use App\Models\PartnerWallet;
use App\Models\Payout;
use App\Services\PartnerWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner wallet — wallet contract §1 and §2.
 */
class WalletController extends DashboardController
{
    public function __construct(private readonly PartnerWalletService $wallet) {}

    /** GET /wallet → WalletSummary. */
    public function summary(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wallet = PartnerWallet::firstOrCreate(['partner_user_id' => $user->id]);
        $bank   = BankDetail::where('partner_user_id', $user->id)->first();

        $available = round($wallet->available_balance, 2);
        $minimum   = (float) config('wallet.min_payout_amount');

        $lastPayout = Payout::where('partner_user_id', $user->id)
            ->where('status', Payout::STATUS_PAID)
            ->latest('paid_at')->first();

        $reason = $this->ineligibleReason($user, $bank, $available, $minimum);

        return $this->ok([
            'availableBalance' => $available,
            'pendingBalance'   => $this->wallet->pendingBalance($user->id),
            'lifetimeEarnings' => round($wallet->lifetime_earnings, 2),
            // Reversed transfers are excluded — the money came back, so it was
            // never paid out (contract §5 invariant 3).
            'lifetimePaidOut'  => round((float) Payout::where('partner_user_id', $user->id)
                ->where('status', Payout::STATUS_PAID)->sum('amount'), 2),
            'currency'         => config('wallet.currency'),
            'minPayoutAmount'  => $minimum,
            'payoutEligible'   => $reason === null,
            'ineligibleReason' => $reason,
            'paidThisMonth'    => Payout::where('partner_user_id', $user->id)
                ->where('status', Payout::STATUS_PAID)
                ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->exists(),
            'bankVerified'     => (bool) $bank?->verified,
            'lastPayoutAt'     => $lastPayout?->paid_at?->toIso8601ZuluString(),
            'lastPayoutAmount' => $lastPayout ? round($lastPayout->amount, 2) : null,
        ]);
    }

    /**
     * Exactly one reason, first match wins; null means genuinely queued.
     *
     * DEVIATION from the spec's table order, deliberately: suspension and a
     * negative balance are evaluated BEFORE below_minimum. The spec asks for a
     * suspended partner to see `partner_suspended` (§7), but a suspended
     * partner whose balance is also small would report `below_minimum` under
     * the table's order and be told to keep earning — advice that cannot help
     * them. The blocking condition outranks the arithmetic one.
     */
    private function ineligibleReason($user, ?BankDetail $bank, float $available, float $minimum): ?string
    {
        return match (true) {
            ! $user->is_active            => 'partner_suspended',
            $available < 0                => 'negative_balance',
            $bank === null                => 'bank_missing',
            ! $bank->verified             => 'bank_unverified',
            $available < $minimum         => 'below_minimum',
            default                       => null,
        };
    }

    /**
     * GET /wallet/ledger?limit=&before= → bare array, newest first.
     *
     * `before` is a cursor (the last row's createdAt), not an offset: the ledger
     * only grows, and an offset would skip or repeat rows whenever a new
     * earning lands between two pages.
     */
    public function ledger(Request $request): JsonResponse
    {
        $limit = (int) ($request->query('limit') ?? 20);

        if ($limit < 1 || $limit > 100) {
            $this->fail('VALIDATION', 'قيمة limit غير صالحة', 422, ['limit' => 'يجب أن تكون بين 1 و 100']);
        }

        $query = PartnerLedgerEntry::query()
            ->where('partner_user_id', $request->user()->id)
            ->orderByDesc('created_at')->orderByDesc('id');

        if ($before = $request->query('before')) {
            try {
                $query->where('created_at', '<', \Illuminate\Support\Carbon::parse($before));
            } catch (\Throwable) {
                $this->fail('VALIDATION', 'قيمة before غير صالحة', 422, ['before' => 'تاريخ غير صالح']);
            }
        }

        return $this->ok($query->limit($limit)->get()->map(fn (PartnerLedgerEntry $e) => [
            'id'           => 'led_'.$e->id,
            'type'         => $e->type,
            'amount'       => round($e->amount, 2),
            'balanceAfter' => round($e->balance_after, 2),
            'refType'      => $e->ref_type,
            'refId'        => $e->ref_type === 'booking' ? 'b_'.$e->ref_id : 'po_'.$e->ref_id,
            'refCode'      => $e->ref_code ?? '',
            'description'  => $e->description ?? '',
            'createdAt'    => $e->created_at?->toIso8601ZuluString(),
        ])->values());
    }
}
