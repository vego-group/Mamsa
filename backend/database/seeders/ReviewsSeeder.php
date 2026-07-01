<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds ratings so the search "التقييم" filter (min_rating) has data.
 *
 * A review requires a unique backing booking (reviews.booking_id is unique +
 * FK), so each rating is created together with a past, completed booking by a
 * guest. Ratings are shaped per unit so the catalogue spans the full 1–5★
 * spread — including units that average a clean 5.0 for the "5★ فأكثر" option.
 */
class ReviewsSeeder extends Seeder
{
    /** Arabic review snippets keyed loosely by sentiment. */
    private const COMMENTS = [
        5 => ['تجربة رائعة، المكان نظيف والخدمة ممتازة.', 'فاق توقعاتنا، سنعود بالتأكيد!', 'موقع مميز واستقبال راقٍ.'],
        4 => ['مكان جميل ومريح، تجربة موفقة.', 'جيد جداً مع ملاحظات بسيطة.', 'يستحق الزيارة، أنصح به.'],
        3 => ['مقبول بشكل عام لكنه يحتاج بعض التحسين.', 'تجربة متوسطة.', 'لا بأس به مقابل السعر.'],
    ];

    public function run(): void
    {
        $guests = $this->guests();
        if ($guests->isEmpty()) {
            return; // DevUsersSeeder must run first
        }

        $units = Unit::where('approval_status', 'approved')
            ->where('status', 'available')
            ->orderBy('id')
            ->get();

        foreach ($units as $i => $unit) {
            // Idempotent: never double-seed a unit that already has ratings.
            if ($unit->reviews()->exists()) {
                continue;
            }

            foreach ($this->ratingsFor($i) as $n => $rating) {
                $guest   = $guests[($i + $n) % $guests->count()];
                $booking = $this->pastBooking($unit, $guest, $n);

                Review::create([
                    'booking_id' => $booking->id,
                    'user_id'    => $guest->id,
                    'unit_id'    => $unit->id,
                    'rating'     => $rating,
                    'comment'    => self::COMMENTS[max(3, $rating)][$n % 3],
                ]);
            }
        }
    }

    /**
     * Deterministic rating set per unit index so averages land across the
     * spectrum: every 3rd unit is a flawless 5.0, the rest cluster at 4.x/3.x.
     *
     * @return list<int>
     */
    private function ratingsFor(int $index): array
    {
        return match ($index % 3) {
            0 => [5, 5, 5, 5],        // avg 5.0  → matches "5★ فأكثر"
            1 => [5, 4, 5, 4, 5],     // avg 4.6  → matches "4★ فأكثر"
            default => [4, 3, 4, 3],  // avg 3.5  → matches "3★ فأكثر"
        };
    }

    /** A completed, fully-priced booking in the past to hang a review on. */
    private function pastBooking(Unit $unit, User $guest, int $n): Booking
    {
        $end   = Carbon::today()->subDays(10 + $n * 7);
        $start = $end->copy()->subDays(2 + ($n % 3));
        $nights = $start->diffInDays($end);

        $nightly     = (float) $unit->price;
        $subtotal    = round($nightly * $nights, 2);
        $serviceFee  = round($subtotal * (float) config('booking.service_fee_rate'), 2);
        $cleaningFee = round((float) config('booking.cleaning_fee'), 2);
        $taxes       = round($subtotal * (float) config('booking.tax_rate'), 2);

        return Booking::create([
            'unit_id'      => $unit->id,
            'user_id'      => $guest->id,
            'start_date'   => $start->toDateString(),
            'end_date'     => $end->toDateString(),
            'guests'       => min(2, $unit->capacity),
            'nightly_rate' => $nightly,
            'subtotal'     => $subtotal,
            'service_fee'  => $serviceFee,
            'cleaning_fee' => $cleaningFee,
            'taxes'        => $taxes,
            'total_amount' => round($subtotal + $serviceFee + $cleaningFee + $taxes, 2),
            'status'       => Booking::STATUS_COMPLETED,
        ]);
    }

    /** Guest accounts to author reviews; seeds a couple of extras for variety. */
    private function guests()
    {
        $guests = collect([User::where('phone', '+966500000004')->first()])->filter();

        foreach ([['+966500000010', 'سارة القحطاني'], ['+966500000011', 'فهد العتيبي']] as [$phone, $name]) {
            $guests->push(User::firstOrCreate(
                ['phone' => $phone],
                ['name' => $name, 'is_active' => true],
            ));
        }

        return $guests->values();
    }
}
