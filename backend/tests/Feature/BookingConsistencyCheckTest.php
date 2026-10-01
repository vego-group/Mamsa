<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerLedgerEntry;
use App\Notifications\LedgerCheckFailed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
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

    public function test_a_completed_booking_whose_share_never_reached_the_ledger_is_caught(): void
    {
        // The exact shape of staging bookings 66 and 67: the arithmetic is
        // perfect — 100 + 900 === 1000 — and the partner was still never paid.
        // Every existing check passed on this row for three days.
        $booking = $this->booking(1000, 0.10, 100, 900, Booking::STATUS_COMPLETED);

        // Whatever the observer credited on create, drop it: that is the state
        // a zero share at completion time leaves behind, and no later backfill
        // goes back to post it.
        PartnerLedgerEntry::where('ref_type', 'booking')
            ->where('ref_id', (string) $booking->id)->delete();

        $this->artisan('bookings:check-consistency')
            ->expectsOutputToContain('1 completed booking(s) owe a share that never reached the ledger')
            ->assertFailed();
    }

    public function test_a_credited_completed_booking_passes(): void
    {
        $this->booking(1000, 0.10, 100, 900, Booking::STATUS_COMPLETED);

        // The observer credits it on create, so coverage is satisfied.
        $this->artisan('bookings:check-consistency')
            ->expectsOutputToContain('every completed booking with a share has an earning entry')
            ->assertSuccessful();
    }

    public function test_a_completed_booking_with_nothing_to_credit_is_reported_not_failed(): void
    {
        // Mamsa-owned: the platform keeps the whole net base. Correctly
        // uncredited — but counted out loud, because a growing number of these
        // is its own smell.
        $this->booking(1000, 1.0, 1000, 0, Booking::STATUS_COMPLETED);

        $this->artisan('bookings:check-consistency')
            ->expectsOutputToContain('1 completed booking(s) carry no share to credit')
            ->assertSuccessful();
    }

    public function test_the_alert_flag_notifies_active_super_admins_of_a_finding(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_active' => true]);
        Role::findOrCreate('SuperAdmin', 'web');
        $admin->assignRole('SuperAdmin');

        $booking = $this->booking(1000, 0.10, 100, 900, Booking::STATUS_COMPLETED);
        PartnerLedgerEntry::where('ref_type', 'booking')
            ->where('ref_id', (string) $booking->id)->delete();

        $this->artisan('bookings:check-consistency --alert')->assertFailed();

        Notification::assertSentTo($admin, LedgerCheckFailed::class,
            fn (LedgerCheckFailed $n) => $n->uncredited === 1);
    }

    public function test_a_clean_run_alerts_nobody(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_active' => true]);
        Role::findOrCreate('SuperAdmin', 'web');
        $admin->assignRole('SuperAdmin');

        $this->booking(1000, 0.10, 100, 900, Booking::STATUS_COMPLETED);

        $this->artisan('bookings:check-consistency --alert')->assertSuccessful();

        // An alert that fires on a healthy ledger trains people to ignore it.
        Notification::assertNothingSent();
    }

    public function test_a_manual_run_stays_silent(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_active' => true]);
        Role::findOrCreate('SuperAdmin', 'web');
        $admin->assignRole('SuperAdmin');

        $booking = $this->booking(1000, 0.10, 100, 900, Booking::STATUS_COMPLETED);
        PartnerLedgerEntry::where('ref_type', 'booking')
            ->where('ref_id', (string) $booking->id)->delete();

        // Someone at a terminal is already reading the output.
        $this->artisan('bookings:check-consistency')->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_a_suspended_super_admin_is_not_alerted(): void
    {
        Notification::fake();
        Role::findOrCreate('SuperAdmin', 'web');
        $suspended = User::factory()->create(['is_active' => false]);
        $suspended->assignRole('SuperAdmin');

        $booking = $this->booking(1000, 0.10, 100, 900, Booking::STATUS_COMPLETED);
        PartnerLedgerEntry::where('ref_type', 'booking')
            ->where('ref_id', (string) $booking->id)->delete();

        $this->artisan('bookings:check-consistency --alert')
            ->expectsOutputToContain('no active super admin exists to notify')
            ->assertFailed();

        Notification::assertNothingSent();
    }

    private function booking(float $subtotal, float $rate, float $commission, float $share, string $status = Booking::STATUS_CONFIRMED): Booking
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
            'status'            => $status,
        ]);
    }
}
