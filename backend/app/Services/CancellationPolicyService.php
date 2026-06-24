<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RefundQuote;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The cancellation & refund engine — SRS 2.3. Pure, UI-independent logic.
 *
 * Two responsibilities:
 *  1. snapshotForBooking(): freeze the unit's policy onto a booking at payment
 *     time so later partner edits never change a confirmed booking (FR-036,
 *     FR-069, NFR-014).
 *  2. quote(): given a frozen snapshot and a moment in time, compute the refund
 *     amount (FR-043/044). The calculation reads ONLY the snapshot, never the
 *     live policy — that is what makes refunds deterministic and auditable.
 */
class CancellationPolicyService
{
    private const DEFAULT_CHECKIN_TIME = '15:00:00';

    /**
     * Build the frozen policy snapshot for a booking.
     *
     * @return array<string, mixed>
     */
    public function snapshotForBooking(Booking $booking): array
    {
        $booking->loadMissing('unit.cancellationPolicy.tiers');

        $policy = $booking->unit?->cancellationPolicy ?? $this->defaultPolicy();
        $tiers  = $policy?->tiers ?? collect();

        return [
            'policy_key'  => $policy?->key,
            'policy_name' => $policy?->name_ar,
            'checkin_at'  => $this->checkinAt($booking)->toIso8601String(),
            'tiers'       => $tiers->map(fn ($t) => [
                'min_hours_before_checkin' => (int) $t->min_hours_before_checkin,
                'refund_percent'           => (int) $t->refund_percent,
                'label'                    => $t->label_ar,
            ])->values()->all(),
        ];
    }

    /**
     * Compute the refund quote for a booking at a given moment (defaults to now).
     * Falls back to a live snapshot if the booking has none yet (e.g. an unpaid
     * booking being previewed before confirmation).
     */
    public function quote(Booking $booking, ?CarbonInterface $at = null): RefundQuote
    {
        $at       = $at ?? Carbon::now();
        $snapshot = $booking->cancellation_snapshot ?: $this->snapshotForBooking($booking);

        $checkinAt   = Carbon::parse($snapshot['checkin_at']);
        $hoursBefore = $this->signedHoursBetween($at, $checkinAt);

        // FR-045: no cancellation once check-in has started.
        if ($hoursBefore <= 0) {
            return RefundQuote::blocked($hoursBefore, 'لا يمكن الإلغاء بعد موعد تسجيل الدخول');
        }

        $tier    = $this->matchTier($snapshot['tiers'] ?? [], $hoursBefore);
        $percent = (int) ($tier['refund_percent'] ?? 0);
        $amount  = round($booking->total_amount * $percent / 100, 2);

        return new RefundQuote(
            cancellable: true,
            refundAmount: $amount,
            refundPercent: $percent,
            tierLabel: $tier['label'] ?? null,
            hoursBeforeCheckin: $hoursBefore,
        );
    }

    /**
     * Pick the matching tier: the highest min_hours_before_checkin threshold
     * that is still <= the hours remaining before check-in.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<string, mixed>|null
     */
    private function matchTier(array $tiers, int $hoursBefore): ?array
    {
        $match = null;

        foreach ($tiers as $tier) {
            $threshold = (int) $tier['min_hours_before_checkin'];

            if ($hoursBefore >= $threshold
                && ($match === null || $threshold > (int) $match['min_hours_before_checkin'])) {
                $match = $tier;
            }
        }

        return $match;
    }

    private function checkinAt(Booking $booking): CarbonInterface
    {
        $time = $booking->unit?->checkin_time ?: self::DEFAULT_CHECKIN_TIME;

        return Carbon::parse(
            $booking->start_date->format('Y-m-d').' '.$time,
            config('app.timezone'),
        );
    }

    /** Signed whole hours from $from to $to (positive when $to is in the future). */
    private function signedHoursBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 3600);
    }

    private function defaultPolicy(): ?CancellationPolicy
    {
        return CancellationPolicy::with('tiers')
            ->orderByDesc('is_default')
            ->first();
    }
}
