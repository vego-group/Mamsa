<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\SavedCard;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fills the demo guest's account pages so nothing renders empty:
 *   • saved cards      → /account/payment-methods (backend gaps #4)
 *   • wallet ledger    → /account/payment-methods (backend gaps #4)
 *   • favourites       → wishlist sync (backend gaps #7)
 *   • an upcoming stay → active booking on the bookings page
 *
 * Idempotent: safe to re-run. Targets the seeded guest (+966500000004).
 */
class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        $guest = User::where('phone', '+966500000004')->first();
        if (! $guest) {
            return; // DevUsersSeeder must run first
        }

        $this->seedCards($guest);
        $this->seedFavorites($guest);
        $upcoming = $this->seedUpcomingBooking($guest);
        $this->seedTransactions($guest, $upcoming);
    }

    /** Two saved cards — mada is the default. */
    private function seedCards(User $guest): void
    {
        if ($guest->savedCards()->exists()) {
            return;
        }

        $guest->savedCards()->createMany([
            ['brand' => 'mada',       'last4' => '4321', 'exp_month' => 8,  'exp_year' => 2028, 'is_default' => true],
            ['brand' => 'visa',       'last4' => '1187', 'exp_month' => 3,  'exp_year' => 2027, 'is_default' => false],
            ['brand' => 'mastercard', 'last4' => '9042', 'exp_month' => 11, 'exp_year' => 2026, 'is_default' => false],
        ]);
    }

    /** Favourite the first few available, supported units. */
    private function seedFavorites(User $guest): void
    {
        $units = Unit::query()
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES)
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->orderBy('id')
            ->take(3)
            ->pluck('id');

        foreach ($units as $unitId) {
            $guest->favorites()->firstOrCreate(['unit_id' => $unitId]);
        }
    }

    /** A confirmed, paid upcoming stay so the bookings page has an active card. */
    private function seedUpcomingBooking(User $guest): ?Booking
    {
        $existing = $guest->bookings()->where('status', Booking::STATUS_CONFIRMED)->first();
        if ($existing) {
            return $existing;
        }

        $unit = Unit::query()
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES)
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->orderBy('id')
            ->first();

        if (! $unit) {
            return null;
        }

        $start   = Carbon::today()->addDays(12);
        $end     = $start->copy()->addDays(3);
        $nights  = $start->diffInDays($end);
        $pricing = $this->pricing((float) $unit->price, $nights);

        $booking = $guest->bookings()->create([
            'unit_id'      => $unit->id,
            'start_date'   => $start->toDateString(),
            'end_date'     => $end->toDateString(),
            'guests'       => min(2, $unit->capacity),
            'nightly_rate' => $pricing['nightly_rate'],
            'subtotal'     => $pricing['subtotal'],
            'service_fee'  => $pricing['service_fee'],
            'cleaning_fee' => $pricing['cleaning_fee'],
            'taxes'        => $pricing['taxes'],
            'total_amount' => $pricing['total'],
            'status'       => Booking::STATUS_CONFIRMED,
        ]);

        Payment::create([
            'booking_id'     => $booking->id,
            'amount'         => $pricing['total'],
            'payment_method' => 'creditcard',
            'payment_status' => 'paid',
            'paid_at'        => now(),
        ]);

        return $booking;
    }

    /** A varied ledger covering every transaction type/status. */
    private function seedTransactions(User $guest, ?Booking $upcoming): void
    {
        if ($guest->walletTransactions()->exists()) {
            return;
        }

        $entries = [
            [
                'ref_code'    => 'PAY-2026-1042',
                'type'        => WalletTransaction::TYPE_PAYMENT,
                'amount'      => $upcoming ? -1 * $upcoming->total_amount : -1380.00,
                'description' => 'دفع قيمة حجز وحدة',
                'status'      => 'completed',
                'booking_id'  => $upcoming?->id,
                'occurred_at' => Carbon::today()->subDays(3),
            ],
            [
                'ref_code'    => 'REF-2026-0087',
                'type'        => WalletTransaction::TYPE_REFUND,
                'amount'      => 1200.00,
                'description' => 'استرداد من حجز ملغي',
                'status'      => 'completed',
                'occurred_at' => Carbon::today()->subDays(9),
            ],
            [
                'ref_code'    => 'RWD-2026-0031',
                'type'        => WalletTransaction::TYPE_REWARD,
                'amount'      => 150.00,
                'description' => 'مكافأة ولاء',
                'status'      => 'completed',
                'occurred_at' => Carbon::today()->subDays(14),
            ],
            [
                'ref_code'    => 'TOP-2026-0009',
                'type'        => WalletTransaction::TYPE_TOPUP,
                'amount'      => 500.00,
                'description' => 'شحن رصيد المحفظة',
                'status'      => 'pending',
                'occurred_at' => Carbon::today()->subDay(),
            ],
        ];

        foreach ($entries as $entry) {
            $guest->walletTransactions()->create($entry);
        }
    }

    /**
     * Mirror the booking price breakdown (config-driven fees).
     *
     * @return array{nightly_rate: float, subtotal: float, service_fee: float, cleaning_fee: float, taxes: float, total: float}
     */
    private function pricing(float $nightly, int $nights): array
    {
        $subtotal    = round($nightly * $nights, 2);
        $serviceFee  = round($subtotal * (float) config('booking.service_fee_rate'), 2);
        $cleaningFee = round((float) config('booking.cleaning_fee'), 2);
        $taxes       = round($subtotal * (float) config('booking.tax_rate'), 2);

        return [
            'nightly_rate' => $nightly,
            'subtotal'     => $subtotal,
            'service_fee'  => $serviceFee,
            'cleaning_fee' => $cleaningFee,
            'taxes'        => $taxes,
            'total'        => round($subtotal + $serviceFee + $cleaningFee + $taxes, 2),
        ];
    }
}
