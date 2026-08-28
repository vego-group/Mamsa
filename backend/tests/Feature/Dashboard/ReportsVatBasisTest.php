<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * /reports/summary on the VAT-exclusive basis — the figures a partner reads
 * must be the ones the wallet pays, and the tiles must add up on screen.
 */
class ReportsVatBasisTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');

        $this->unit = $this->partner->units()->create([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 350, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'area' => 90, 'city' => 'جدة', 'district' => 'الشاطئ', 'lat' => 21.5, 'lng' => 39.1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }

    private function booking(array $money): Booking
    {
        return Booking::create(array_merge([
            'unit_id'    => $this->unit->id,
            'user_id'    => User::factory()->create()->id,
            'code'       => 'BK-'.fake()->unique()->numerify('####'),
            'start_date' => now()->subDays(5),
            'end_date'   => now()->subDays(2),
            'guests'     => 2,
            'status'     => Booking::STATUS_COMPLETED,
        ], $money));
    }

    private function summary(): array
    {
        $from = now()->subMonth()->toDateString();
        $to   = now()->addDay()->toDateString();

        return $this->actingAs($this->partner, 'dashboard')
            ->getJson("/reports/summary?from={$from}&to={$to}")->assertOk()->json();
    }

    public function test_commission_is_two_percent_of_the_vat_exclusive_base(): void
    {
        // Gross 3450 → netBase 3000, VAT 450, commission 60, share 2940.
        $this->booking([
            'subtotal' => 3000.00, 'taxes' => 450.00, 'commission_amount' => 60.00,
            'partner_share' => 2940.00, 'total_amount' => 3450.00,
        ]);

        $s = $this->summary();

        $this->assertEqualsWithDelta(3450.00, $s['grossRevenue'], 0.01);
        $this->assertEqualsWithDelta(3000.00, $s['netRevenue'], 0.01, 'the VAT-exclusive base');
        $this->assertEqualsWithDelta(450.00, $s['vat'], 0.01);
        $this->assertEqualsWithDelta(60.00, $s['commission'], 0.01, '2% of 3000, not of 3450');
        $this->assertEqualsWithDelta(2940.00, $s['netProfit'], 0.01);
    }

    /**
     * The partner can check this on screen: netRevenue × 2% must equal the
     * commission tile beside it. On the gross basis those never agreed, which
     * made the commission look like it was charged on the tax.
     */
    public function test_the_tiles_reconcile_on_screen(): void
    {
        $this->booking([
            'subtotal' => 3000.00, 'taxes' => 450.00, 'commission_amount' => 60.00,
            'partner_share' => 2940.00, 'total_amount' => 3450.00,
        ]);

        $s = $this->summary();

        $this->assertEqualsWithDelta($s['netRevenue'] * 0.02, $s['commission'], 0.01);
        $this->assertEqualsWithDelta($s['netRevenue'] - $s['commission'], $s['netProfit'], 0.01);
        $this->assertEqualsWithDelta(
            $s['grossRevenue'], $s['netRevenue'] + $s['vat'] + $s['fees'], 0.01,
            'netRevenue + vat + fees must equal gross, or the tiles do not add up',
        );
    }

    /**
     * Historical rows carry abolished service/cleaning fees, so gross is more
     * than base + VAT. Without the `fees` field the difference is unexplained
     * on screen — and reading it as tax implies a rate that is not 15%.
     */
    public function test_legacy_fee_bookings_are_reported_as_fees_not_tax(): void
    {
        $this->booking([
            'subtotal' => 1000.00, 'taxes' => 150.00,
            'service_fee' => 100.00, 'cleaning_fee' => 50.00,
            'commission_amount' => 20.00, 'partner_share' => 980.00,
            'total_amount' => 1300.00,
        ]);

        $s = $this->summary();

        $this->assertEqualsWithDelta(1300.00, $s['grossRevenue'], 0.01);
        $this->assertEqualsWithDelta(1000.00, $s['netRevenue'], 0.01);
        $this->assertEqualsWithDelta(150.00, $s['vat'], 0.01, 'VAT is the frozen column, not gross − base');
        $this->assertEqualsWithDelta(150.00, $s['fees'], 0.01, 'the rest is abolished fees');
        $this->assertEqualsWithDelta(
            $s['grossRevenue'], $s['netRevenue'] + $s['vat'] + $s['fees'], 0.01,
        );
    }

    public function test_a_zero_commission_is_reported_as_zero_not_imputed(): void
    {
        // Reports used to impute the legacy rate whenever the frozen amount was
        // not greater than zero. That could not tell a booking which owes NO
        // commission — a promotional partner, a waived fee — from one that was
        // never frozen, and it substituted a plausible wrong number for a
        // correct zero.
        //
        // The ambiguity is now removed at write time instead: the column
        // defaults are dropped, so an unfrozen row cannot be created, and a
        // zero here means zero. `bookings:freeze-commission` repairs any row
        // that predates that guarantee — see the test below.
        $this->booking([
            'subtotal' => 1000.00, 'taxes' => 150.00,
            'commission_amount' => 0, 'partner_share' => 1000.00,
            'total_amount' => 1150.00,
        ]);

        $this->assertEqualsWithDelta(0.00, $this->summary()['commission'], 0.01);
    }

    public function test_the_freeze_command_makes_a_legacy_row_self_consistent(): void
    {
        $booking = $this->booking([
            'subtotal' => 1000.00, 'taxes' => 150.00,
            'commission_amount' => 0, 'partner_share' => 1000.00,
            'total_amount' => 1150.00,
        ]);

        $this->artisan('bookings:freeze-commission')->assertSuccessful();

        $booking->refresh();
        $this->assertEqualsWithDelta(20.00, $booking->commission_amount, 0.01);
        $this->assertEqualsWithDelta(980.00, $booking->partner_share, 0.01);

        $s = $this->summary();
        $this->assertEqualsWithDelta($s['netRevenue'] - $s['commission'], $s['netProfit'], 0.01);
    }

    public function test_the_freeze_command_never_rewrites_money_already_paid(): void
    {
        $payout = \App\Models\Payout::create([
            'partner_user_id' => $this->partner->id, 'reference' => 'PO-TEST-0001',
            'period_month' => '2026-07', 'amount' => 1000.00, 'bookings_count' => 1,
            'iban_masked' => '••••7519', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $booking = $this->booking([
            'subtotal' => 1000.00, 'taxes' => 150.00,
            'commission_amount' => 0, 'partner_share' => 1000.00,
            'total_amount' => 1150.00, 'payout_id' => $payout->id,
        ]);

        $this->artisan('bookings:freeze-commission')->assertSuccessful();

        // That money moved; rewriting its share would falsify the transfer.
        $this->assertEqualsWithDelta(1000.00, $booking->refresh()->partner_share, 0.01);
    }
}
