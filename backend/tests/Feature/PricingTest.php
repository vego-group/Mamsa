<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pricing contract after the 2026-07-18 owner revert: the guest pays
 * subtotal + 15% VAT — no cleaning fee, no service fee. Historical fee-era
 * bookings keep their frozen lines (rendered only when non-zero, so old
 * invoices still sum to total). Quote, frozen booking row and
 * amount_halalas must all agree.
 */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function unit(float $price = 1000): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name'       => 'وحدة تسعير',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => $price,
            'capacity'        => 2,
            'bedrooms'        => 1,
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);
    }

    private function guest(): User
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        return $user;
    }

    /* ---- formula: subtotal + 15% VAT, nothing else ---- */

    public function test_availability_returns_subtotal_plus_vat_only(): void
    {
        $unit = $this->unit(price: 1000);

        // 3 nights: subtotal 3000, VAT 450, total 3450 — no fee lines at all.
        $this->postJson("/api/v1/units/{$unit->id}/availability", [
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date'   => now()->addDays(13)->toDateString(),
        ])->assertOk()->assertJson([
            'available' => true,
            'pricing'   => [
                'nights'       => 3,
                'nightly_rate' => 1000.0,
                'subtotal'     => 3000.0,
                'taxes'        => 450.0,
                'tax_percent'  => 15.0,
                'total'        => 3450.0,
            ],
        ])->assertJsonMissingPath('pricing.service_fee')
          ->assertJsonMissingPath('pricing.cleaning_fee')
          ->assertJsonMissingPath('pricing.service_fee_percent')
          ->assertJsonMissingPath('pricing.commission_amount');
    }

    public function test_booking_freezes_breakdown_and_halalas_are_exact(): void
    {
        $unit = $this->unit(price: 1000);

        $this->actingAs($this->guest())->postJson('/api/v1/bookings', [
            'unit_id'    => $unit->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date'   => now()->addDays(13)->toDateString(),
            'guests'     => 2,
        ])->assertCreated()
            ->assertJsonPath('pricing.taxes', 450)
            ->assertJsonPath('pricing.tax_percent', 15)
            ->assertJsonPath('pricing.total', 3450)
            ->assertJsonMissingPath('pricing.service_fee')
            ->assertJsonMissingPath('pricing.cleaning_fee');

        $this->assertDatabaseHas('bookings', [
            'unit_id'      => $unit->id,
            'subtotal'     => 3000.00,
            'service_fee'  => 0,
            'cleaning_fee' => 0,
            'taxes'        => 450.00,
            'tax_percent'  => 15.00,
            'total_amount' => 3450.00,
        ]);

        // Halalas parity: lines pre-rounded to 2dp → total × 100 is exact
        // (this is the figure /payments/initiate sends to Moyasar).
        $total = (float) Booking::where('unit_id', $unit->id)->value('total_amount');
        $this->assertSame(345000, (int) round($total * 100));
    }

    public function test_historical_fee_era_booking_still_shows_its_fee_lines(): void
    {
        $unit  = $this->unit(price: 450);
        $guest = $this->guest();

        // A frozen fee-era row (like the 62 real prod bookings from Jun 30 – Jul 6):
        // 1350 + 135 service (10%) + 100 cleaning + 237.75 VAT (15% of 1585) = 1822.75.
        $booking = Booking::create([
            'unit_id' => $unit->id, 'user_id' => $guest->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date'   => now()->addDays(8)->toDateString(),
            'guests' => 2, 'status' => 'confirmed',
            'nightly_rate' => 450, 'subtotal' => 1350,
            'service_fee' => 135, 'service_fee_percent' => 10,
            'cleaning_fee' => 100,
            'taxes' => 237.75, 'tax_percent' => 15,
            'total_amount' => 1822.75,
        ]);

        $res = $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}")
            ->assertOk() // resource-wrapped: data.*
            ->assertJsonPath('data.pricing.service_fee', 135)
            ->assertJsonPath('data.pricing.service_fee_percent', 10)
            ->assertJsonPath('data.pricing.cleaning_fee', 100)
            ->assertJsonPath('data.pricing.taxes', 237.75);

        // The rendered lines must sum to the frozen total — that's WHY
        // historical fee lines are kept.
        $p = $res->json('data.pricing');
        $this->assertSame(
            round($p['subtotal'] + $p['service_fee'] + $p['cleaning_fee'] + $p['taxes'], 2),
            $p['total'],
        );
    }

    public function test_cleaning_fee_in_unit_payloads_is_silently_ignored(): void
    {
        $unit = $this->unit();

        // Old clients still sending the abolished field must not break (422)
        // nor persist anything — the column is gone.
        $this->actingAs($unit->owner)->putJson("/api/v1/partner/units/{$unit->id}", [
            'price'        => 500,
            'cleaning_fee' => 150,
        ])->assertOk();

        $this->assertSame(500.0, $unit->fresh()->price);
        $this->assertArrayNotHasKey('cleaning_fee', $unit->fresh()->getAttributes());
    }

    /* ---- platform settings: read-only, VAT only ---- */

    public function test_settings_endpoint_is_read_only_tax_only(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)->getJson('/api/v1/admin/platform-settings')
            ->assertOk()
            ->assertJsonPath('data.tax_percent', 15)
            ->assertJsonMissingPath('data.service_fee_percent');

        // The PATCH surface is gone entirely — even for SuperAdmin.
        $super = User::factory()->create();
        $super->assignRole('SuperAdmin');
        $this->actingAs($super)
            ->patchJson('/api/v1/admin/platform-settings', ['service_fee_percent' => 10])
            ->assertStatus(405);
    }
}
