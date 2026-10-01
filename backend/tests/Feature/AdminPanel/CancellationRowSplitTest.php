<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A cancellation row has to carry the frozen money split.
 *
 * It used to expose only `bookingTotal`, `refundAmount` and `impact`. A console
 * needing the partner's side had to derive it from `bookingTotal` — which is
 * VAT-INCLUSIVE — at today's rate. That is the same fault found in six backend
 * sites: commission charged on the VAT, 15% over, and against the wrong rate
 * for any booking frozen at another one.
 */
class CancellationRowSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');
    }

    public function test_the_row_carries_the_frozen_split_not_a_derivable_total(): void
    {
        // Frozen at 10%: net base 1000, commission 100, share 900, gross 1150.
        $this->cancelled(subtotal: 1000, commission: 100, share: 900, total: 1150);

        $row = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/cancellations')->assertOk()->json('items.0');

        $this->assertEqualsWithDelta(1000.0, $row['netBase'], 0.01);
        $this->assertEqualsWithDelta(100.0, $row['commission'], 0.01);
        $this->assertEqualsWithDelta(900.0, $row['partnerShare'], 0.01);

        // The trap the fields remove: 10% of the VAT-inclusive total is 115,
        // not 100. A client deriving from bookingTotal lands there.
        $this->assertEqualsWithDelta(1150.0, $row['bookingTotal'], 0.01);
        $this->assertNotEqualsWithDelta(115.0, $row['commission'], 0.01);
    }

    public function test_impact_stays_the_negative_of_commission(): void
    {
        $this->cancelled(subtotal: 1000, commission: 100, share: 900, total: 1150);

        $row = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/cancellations')->assertOk()->json('items.0');

        $this->assertEqualsWithDelta(-$row['commission'], $row['impact'], 0.01);
    }

    public function test_a_booking_frozen_at_the_old_rate_reports_that_rate_not_todays(): void
    {
        // Taken at 2%: the row must show 20, never 100.
        $this->cancelled(subtotal: 1000, commission: 20, share: 980, total: 1150);

        $row = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/cancellations')->assertOk()->json('items.0');

        $this->assertEqualsWithDelta(20.0, $row['commission'], 0.01);
        $this->assertEqualsWithDelta(980.0, $row['partnerShare'], 0.01);
    }

    public function test_the_row_carries_the_rate_it_was_frozen_at(): void
    {
        // Frozen at 2% while the live rate is 10%.
        $this->cancelled(subtotal: 1000, commission: 20, share: 980, total: 1150);

        $row = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/cancellations')->assertOk()->json('items.0');

        // Without this field a console shows 20 SAR beside a "(10%)" badge:
        // correct money, wrong label, and nothing on the row to catch it.
        $this->assertEqualsWithDelta(0.02, $row['commissionRate'], 0.0001);
        $this->assertNotEqualsWithDelta(
            (float) config('booking.commission_rate'),
            $row['commissionRate'],
            0.0001,
            'the row reported the live rate instead of the frozen one',
        );

        // The rate and the money agree, so a client may check one against the other.
        $this->assertEqualsWithDelta(
            $row['commission'],
            round($row['netBase'] * $row['commissionRate'], 2),
            0.01,
        );
    }

    public function test_a_mamsa_owned_cancellation_reports_a_whole_rate(): void
    {
        // No partner to pay: the platform keeps the entire net base.
        $this->cancelled(subtotal: 1000, commission: 1000, share: 0, total: 1150, mamsaOwned: true);

        $row = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/cancellations')->assertOk()->json('items.0');

        $this->assertTrue($row['mamsaOwned']);
        $this->assertEqualsWithDelta(1.0, $row['commissionRate'], 0.0001);
        $this->assertEqualsWithDelta(1000.0, $row['commission'], 0.01);
        $this->assertEqualsWithDelta(0.0, $row['partnerShare'], 0.01);
    }

    public function test_the_v1_admin_surface_carries_the_same_rate_in_snake_case(): void
    {
        $this->cancelled(subtotal: 1000, commission: 20, share: 980, total: 1150);

        $row = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/cancellations')->assertOk()->json('data.0');

        $this->assertEqualsWithDelta(0.02, $row['commission_rate'], 0.0001);
        $this->assertEqualsWithDelta(1000.0, $row['net_base'], 0.01);
        $this->assertEqualsWithDelta(20.0, $row['commission'], 0.01);
    }

    private function cancelled(float $subtotal, float $commission, float $share, float $total, bool $mamsaOwned = false): Booking
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        $unit = $owner->units()->create([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
            'mamsa_owned' => $mamsaOwned,
        ]);

        return Booking::create([
            'unit_id'           => $unit->id,
            'user_id'           => User::factory()->create()->id,
            'start_date'        => now()->addDays(5)->toDateString(),
            'end_date'          => now()->addDays(7)->toDateString(),
            'guests'            => 2,
            'subtotal'          => $subtotal,
            'commission_rate'   => round($commission / $subtotal, 4),
            'commission_amount' => $commission,
            'partner_share'     => $share,
            'total_amount'      => $total,
            'status'            => Booking::STATUS_CANCELLED,
            'cancelled_at'      => now(),
            'cancelled_by'      => 'host',
        ]);
    }
}
