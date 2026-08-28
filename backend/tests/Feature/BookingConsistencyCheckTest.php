<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The consistency check is the only thing left that can notice a broken money
 * split, now that no read path imputes one. It has to actually catch things.
 */
class BookingConsistencyCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_consistent_booking_passes(): void
    {
        $this->booking(subtotal: 1000, rate: 0.10, commission: 100, share: 900);

        $this->artisan('bookings:check-consistency')->assertSuccessful();
    }

    public function test_a_zero_commission_booking_passes(): void
    {
        // The case that started all this: a legitimate zero must not be
        // treated as breakage.
        $this->booking(subtotal: 1000, rate: 0.0, commission: 0, share: 1000);

        $this->artisan('bookings:check-consistency')->assertSuccessful();
    }

    public function test_a_mamsa_owned_split_passes(): void
    {
        // Platform keeps the whole net base; the partner gets nothing.
        $this->booking(subtotal: 1000, rate: 1.0, commission: 1000, share: 0);

        $this->artisan('bookings:check-consistency')->assertSuccessful();
    }

    public function test_an_unfrozen_row_is_caught(): void
    {
        // Commission never written, so the share was backfilled as the whole
        // subtotal. Adds up to 1000 + 0 ≠ 1000? It does add up — which is why
        // the RATE check matters as well as the sum.
        $this->booking(subtotal: 1000, rate: 0.10, commission: 0, share: 1000);

        $this->artisan('bookings:check-consistency')->assertFailed();
    }

    public function test_a_share_computed_from_the_gross_is_caught(): void
    {
        // The real fault behind two of the six sites: commission taken on the
        // VAT-inclusive total (1150 × 10% = 115) rather than the subtotal.
        // 115 + 900 = 1015 ≠ 1000.
        $this->booking(subtotal: 1000, rate: 0.10, commission: 115, share: 900);

        $this->artisan('bookings:check-consistency')->assertFailed();
    }

    public function test_a_partial_write_is_caught(): void
    {
        // Commission written, share left at zero.
        $this->booking(subtotal: 1000, rate: 0.10, commission: 100, share: 0);

        $this->artisan('bookings:check-consistency')->assertFailed();
    }

    public function test_a_booking_with_no_subtotal_is_not_flagged(): void
    {
        // Predates the price breakdown entirely — no split to check, and
        // flagging it would be noise rather than a finding.
        $this->booking(subtotal: 0, rate: 0, commission: 0, share: 0);

        $this->artisan('bookings:check-consistency')->assertSuccessful();
    }

    public function test_rounding_drift_within_a_halala_is_tolerated(): void
    {
        $this->booking(subtotal: 1000, rate: 0.10, commission: 100.01, share: 899.99);

        $this->artisan('bookings:check-consistency')->assertSuccessful();
    }

    public function test_skipped_rows_are_counted_and_reported(): void
    {
        // A silent skip hides the same class of fault the command exists to
        // find: a clean result over half the table still reads as "all clear".
        $this->booking(subtotal: 1000, rate: 0.10, commission: 100, share: 900);
        $this->booking(subtotal: 0, rate: 0, commission: 0, share: 0);

        $this->artisan('bookings:check-consistency')
            ->expectsOutputToContain('checked 1 / 2 booking(s)   skipped 1')
            ->expectsOutputToContain('1 booking(s) skipped')
            ->assertSuccessful();
    }

    public function test_nothing_is_reported_as_skipped_when_nothing_is(): void
    {
        $this->booking(subtotal: 1000, rate: 0.10, commission: 100, share: 900);

        $this->artisan('bookings:check-consistency')
            ->expectsOutputToContain('checked 1 / 1 booking(s)   skipped 0')
            ->assertSuccessful();
    }

    private function booking(float $subtotal, float $rate, float $commission, float $share): Booking
    {
        $owner = User::factory()->create();

        $unit = $owner->units()->create([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        return Booking::create([
            'unit_id'           => $unit->id,
            'user_id'           => User::factory()->create()->id,
            'start_date'        => now()->addDays(5)->toDateString(),
            'end_date'          => now()->addDays(7)->toDateString(),
            'guests'            => 2,
            'subtotal'          => $subtotal,
            'commission_rate'   => $rate,
            'commission_amount' => $commission,
            'partner_share'     => $share,
            'total_amount'      => $subtotal * 1.15,
            'status'            => Booking::STATUS_CONFIRMED,
        ]);
    }
}
