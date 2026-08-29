<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Services\PartnerWalletService;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A unit Mamsa owns has no partner to pay.
 *
 * `units.mamsa_owned` was set on create and then read by nothing: the pricing
 * engine split every booking 2%/98% regardless. On a Mamsa-owned listing
 * `units.user_id` is the ADMIN who created it, so that 98% accrued into the
 * admin's partner wallet and queued itself for a real bank transfer — money
 * owed to nobody, arriving quietly in the payout run.
 *
 * Nothing had been booked wrong yet (no Mamsa-owned unit existed on either
 * server), which is exactly why it needed a test rather than a fix alone.
 */
class MamsaOwnedSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_partner_unit_splits_at_the_live_rate(): void
    {
        // 10% since 2026-08-27. The guest still pays 1150 either way — the rate
        // moves money between the platform and the partner, it does not change
        // the price of a night.
        $p = Pricing::breakdown(1150.0, 1, mamsaOwned: false);

        $this->assertEqualsWithDelta(1000.00, $p['net_base'], 0.01);
        $this->assertEqualsWithDelta(100.00, $p['commission_amount'], 0.01);
        $this->assertEqualsWithDelta(900.00, $p['partner_share'], 0.01);
        $this->assertEqualsWithDelta(1150.00, $p['total'], 0.01, 'the guest price must not move');
    }

    public function test_the_configured_rate_is_what_gets_charged(): void
    {
        config()->set('booking.commission_rate', 0.25);

        $p = Pricing::breakdown(1150.0, 1, mamsaOwned: false);

        $this->assertEqualsWithDelta(250.00, $p['commission_amount'], 0.01);
        $this->assertEqualsWithDelta(0.25, $p['commission_rate'], 0.0001);
    }

    public function test_the_legacy_rate_is_not_the_live_rate(): void
    {
        // These two must never be collapsed into one constant: the live rate
        // prices new bookings, the legacy one reconstructs rows taken before
        // the frozen columns existed. Merging them would restate history.
        $this->assertNotSame(
            (float) config('booking.commission_rate'),
            \App\Models\Booking::LEGACY_COMMISSION_RATE,
        );
    }

    public function test_a_mamsa_owned_unit_keeps_the_whole_net_base(): void
    {
        $p = Pricing::breakdown(1150.0, 1, mamsaOwned: true);

        $this->assertEqualsWithDelta(1000.00, $p['net_base'], 0.01);
        $this->assertEqualsWithDelta(1000.00, $p['commission_amount'], 0.01);
        $this->assertEqualsWithDelta(0.00, $p['partner_share'], 0.01);

        // The RATE, not just the amounts. 1.0 — not 0.0, not null. A zero would
        // read as "no commission" on a row where the platform in fact keeps
        // everything, and null breaks `commission === rate × netBase`.
        $this->assertEqualsWithDelta(1.0, $p['commission_rate'], 0.0001);
    }

    public function test_the_money_invariant_holds_either_way(): void
    {
        foreach ([true, false] as $owned) {
            foreach ([[333.33, 3], [1150.0, 1], [99.99, 7]] as [$nightly, $nights]) {
                $p = Pricing::breakdown($nightly, $nights, mamsaOwned: $owned);

                $this->assertEqualsWithDelta(
                    $p['gross'],
                    round($p['commission_amount'] + $p['partner_share'] + $p['vat'], 2),
                    0.01,
                    "commission + partnerShare + vat drifted from gross (mamsaOwned: ".var_export($owned, true).")",
                );
                $this->assertEqualsWithDelta($p['gross'], round($p['net_base'] + $p['vat'], 2), 0.01);
            }
        }
    }

    public function test_a_mamsa_owned_booking_credits_no_wallet(): void
    {
        Role::findOrCreate('SuperAdmin', 'web');
        Role::findOrCreate('User', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('SuperAdmin');
        $guest = User::factory()->create();
        $guest->assignRole('User');

        $unit = $admin->units()->create([
            'unit_name' => 'وحدة ممسى', 'unit_type' => 'apartment', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 1150, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 1, 'bathrooms' => 1, 'area' => 90,
            'city' => 'الرياض', 'district' => 'العليا', 'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60), 'mamsa_owned' => true,
        ]);

        $pricing = Pricing::breakdown((float) $unit->price, 1, (bool) $unit->mamsa_owned);

        $booking = Booking::create([
            'unit_id'           => $unit->id,
            'user_id'           => $guest->id,
            'start_date'        => now()->addDays(3)->toDateString(),
            'end_date'          => now()->addDays(4)->toDateString(),
            'guests'            => 2,
            'nights'            => 1,
            'subtotal'          => $pricing['subtotal'],
            'taxes'             => $pricing['taxes'],
            'tax_percent'       => $pricing['tax_percent'],
            'total_amount'      => $pricing['total'],
            'commission_rate'   => $pricing['commission_rate'],
            'commission_amount' => $pricing['commission_amount'],
            'partner_share'     => $pricing['partner_share'],
            'status'            => Booking::STATUS_COMPLETED,
        ]);

        // BookingEarningObserver already ran on create — this is the real path,
        // so the ledger is what to assert on, not a second manual call.
        $this->assertSame(0, \App\Models\PartnerLedgerEntry::count(), 'A Mamsa-owned booking paid an earning to the admin who created the unit.');
        $this->assertSame(0, \App\Models\PartnerWallet::where('partner_user_id', $admin->id)->count());

        // And a re-run stays silent (idempotent, and still nothing to pay).
        $this->assertNull(app(PartnerWalletService::class)->recordEarning($booking->fresh('unit')));
    }

    public function test_a_partner_booking_still_credits_the_partner(): void
    {
        // The control: the guard above must not have switched earnings off.
        Role::findOrCreate('Individual', 'web');
        Role::findOrCreate('User', 'web');

        $partner = User::factory()->create();
        $partner->assignRole('Individual');
        $guest = User::factory()->create();
        $guest->assignRole('User');

        $unit = $partner->units()->create([
            'unit_name' => 'وحدة شريك', 'unit_type' => 'apartment', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 1150, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 1, 'bathrooms' => 1, 'area' => 90,
            'city' => 'الرياض', 'district' => 'العليا', 'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        $pricing = Pricing::breakdown((float) $unit->price, 1, (bool) $unit->mamsa_owned);

        $booking = Booking::create([
            'unit_id' => $unit->id, 'user_id' => $guest->id,
            'start_date' => now()->addDays(3)->toDateString(), 'end_date' => now()->addDays(4)->toDateString(),
            'guests' => 2, 'nights' => 1,
            'subtotal' => $pricing['subtotal'], 'taxes' => $pricing['taxes'], 'tax_percent' => $pricing['tax_percent'],
            'total_amount' => $pricing['total'], 'commission_rate' => $pricing['commission_rate'],
            'commission_amount' => $pricing['commission_amount'], 'partner_share' => $pricing['partner_share'],
            'status' => Booking::STATUS_COMPLETED,
        ]);

        $entry = \App\Models\PartnerLedgerEntry::where('ref_id', (string) $booking->id)->first();

        $this->assertNotNull($entry, 'The partner was not credited at all.');
        // Whatever the live rate is, the wallet must be credited the share that
        // was FROZEN on the booking — never recomputed from today's rate.
        $this->assertEqualsWithDelta($pricing['partner_share'], (float) $entry->amount, 0.01);
        $this->assertEqualsWithDelta(900.00, (float) $entry->amount, 0.01, 'net base 1000 less 10%');
        $this->assertSame($partner->id, $entry->partner_user_id);
    }
}
