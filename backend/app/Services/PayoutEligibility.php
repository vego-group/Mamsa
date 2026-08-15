<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BankDetail;
use App\Models\Booking;
use App\Models\PartnerWallet;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * One answer to "can this partner be paid, and how much" — used by the partner
 * wallet, the admin payout run, and the recording endpoint itself.
 *
 * Shared deliberately: if the admin list and the recording endpoint each
 * decided eligibility, a partner could appear in the run and then be refused at
 * the moment of recording, after the accountant had already moved the money.
 */
class PayoutEligibility
{
    /** Reasons in evaluation order — a blocking condition outranks an arithmetic one. */
    public function reason(User $partner, ?PartnerWallet $wallet = null, ?BankDetail $bank = null, bool $adminView = false): ?string
    {
        $wallet ??= PartnerWallet::firstOrCreate(['partner_user_id' => $partner->id]);
        $bank ??= BankDetail::where('partner_user_id', $partner->id)->first();

        $available = round($wallet->available_balance, 2);

        // Only the admin run treats "already paid" as an exclusion: for the
        // partner it is a positive state (done for the cycle), reported through
        // `paidThisMonth` instead.
        if ($adminView && $this->paidThisMonth($partner->id)) {
            return 'already_paid_this_month';
        }

        return match (true) {
            ! $partner->is_active                             => 'partner_suspended',
            $available < 0                                    => 'negative_balance',
            $bank === null                                    => 'bank_missing',
            ! $bank->verified                                 => 'bank_unverified',
            $available < (float) config('wallet.min_payout_amount') => 'below_minimum',
            default                                           => null,
        };
    }

    /** The most recent PAID transfer inside this Gregorian month, if any. */
    public function paidThisMonth(int $partnerUserId): ?Payout
    {
        return Payout::where('partner_user_id', $partnerUserId)
            ->where('status', Payout::STATUS_PAID)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->latest('paid_at')
            ->first();
    }

    /**
     * The bookings a transfer would cover: finished stays not yet attached to
     * one. The payout amount is their sum, never the raw balance, so that
     * `Σ bookings[].partnerShare === payout.amount` always holds — the partner's
     * payout sheet shows that total back to them, so a mismatch is visible.
     */
    public function payableBookings(int $partnerUserId): Builder
    {
        return Booking::query()
            ->whereHas('unit', fn ($q) => $q->where('user_id', $partnerUserId))
            ->where('status', Booking::STATUS_COMPLETED)
            ->where('partner_share', '>', 0)
            ->whereNull('payout_id');
    }

    /** @return array{amount: float, count: int} */
    public function payable(int $partnerUserId): array
    {
        $rows = $this->payableBookings($partnerUserId)
            ->selectRaw('COALESCE(SUM(partner_share), 0) as total, COUNT(*) as n')
            ->first();

        return [
            'amount' => round((float) $rows->total, 2),
            'count'  => (int) $rows->n,
        ];
    }

    /**
     * The month EARNED, not the month paid: taken from the latest stay the
     * transfer covers, so a run executed in September for August's stays reads
     * "2026-08". Falls back to last month when there is nothing to date it by.
     */
    public function periodMonth(int $partnerUserId): string
    {
        $latest = $this->payableBookings($partnerUserId)->max('end_date');

        return $latest
            ? \Illuminate\Support\Carbon::parse($latest)->format('Y-m')
            : now()->subMonth()->format('Y-m');
    }
}
