<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Models\Booking;

/**
 * Maps a Booking to the partner-dashboard contract shape (§6). Financials use
 * the frozen 2% commission (commission + partnerShare === total).
 */
class BookingPresenter
{
    public static function make(Booking $booking): array
    {
        $booking->loadMissing(['unit.images', 'user', 'payment', 'refunds']);

        $total      = (float) $booking->total_amount;
        $commission = (float) ($booking->commission_amount ?? round($total * 0.02, 2));

        $cover = $booking->unit?->images->firstWhere('is_main', true) ?? $booking->unit?->images->first();

        $data = [
            'id'         => 'b_'.$booking->id,
            'code'       => 'BK-'.$booking->id,
            'unitId'     => 'u_'.$booking->unit_id,
            'unitName'   => $booking->unit?->unit_name,
            'unitThumb'  => $cover?->url,
            'guestName'  => $booking->user?->name,
            'guestPhone' => $booking->user?->phone,
            'checkIn'    => $booking->start_date?->copy()->setTimeFromTimeString(self::time($booking->unit?->checkin_time, '15:00'))->toIso8601ZuluString(),
            'checkOut'   => $booking->end_date?->copy()->setTimeFromTimeString(self::time($booking->unit?->checkout_time, '12:00'))->toIso8601ZuluString(),
            'nights'     => $booking->nights,
            'guests'     => (int) $booking->guests,
            'status'     => $booking->status,
            'financials' => [
                'total'        => $total,
                'commission'   => $commission,
                'partnerShare' => round($total - $commission, 2),
            ],
            // Guest-facing invoice lines, frozen at booking time (percent
            // fields included so invoice screens can label "رسوم الخدمة 10%"
            // with the rate that applied THEN, not the live setting). Legacy
            // pre-breakdown rows fall back like the user-site resource does.
            'pricing' => [
                'nightlyRate'       => (float) ($booking->nightly_rate ?? ($booking->nights ? round($total / $booking->nights, 2) : 0)),
                'nights'            => $booking->nights,
                'subtotal'          => (float) ($booking->subtotal ?? $total),
                'serviceFee'        => (float) $booking->service_fee,
                'serviceFeePercent' => (float) ($booking->service_fee_percent ?? ($booking->subtotal > 0 ? round($booking->service_fee / $booking->subtotal * 100, 2) : 0)),
                'cleaningFee'       => (float) $booking->cleaning_fee,
                'taxes'             => (float) $booking->taxes,
                'taxPercent'        => (float) ($booking->tax_percent ?? (($base = $booking->subtotal + $booking->cleaning_fee + $booking->service_fee) > 0 ? round($booking->taxes / $base * 100, 2) : 0)),
                'total'             => $total,
            ],
            'policySnapshot' => $booking->cancellation_snapshot ? [
                'name'  => $booking->cancellation_snapshot['policy_key'] ?? null,
                'rules' => $booking->cancellation_snapshot['policy_name'] ?? null,
                'tiers' => $booking->cancellation_snapshot['tiers'] ?? [],
            ] : null,
            'notes'      => $booking->notes,
        ];

        if ($booking->status === Booking::STATUS_CANCELLED) {
            $refund = $booking->refunds->last();
            $data['cancellation'] = [
                'type'         => $booking->cancelled_by === 'partner' ? 'host' : 'guest',
                'reason'       => $booking->cancellation_reason,
                'date'         => $booking->cancelled_at?->toIso8601ZuluString(),
                'refundAmount' => (float) ($booking->payment?->refunded_amount ?? 0),
                'refundStatus' => ($booking->payment && $booking->payment->refunded_amount > 0)
                    ? (($refund && $refund->status === 'succeeded') ? 'completed' : 'processing')
                    : 'completed',
            ];
        }

        return $data;
    }

    private static function time(mixed $t, string $default): string
    {
        return $t ? substr((string) $t, 0, 8) : $default.':00';
    }
}
