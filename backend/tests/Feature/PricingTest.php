<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pricing contract (2026-07-18): per-unit cleaning fee, DB-backed service fee %,
 * VAT on the full invoice, and checkout/payment parity — the availability
 * preview, the frozen booking row, and amount_halalas must all agree.
 */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // Pricing caches service_fee_percent forever

        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function unit(float $price = 1000, float $cleaningFee = 200): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name'       => 'وحدة تسعير',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => $price,
            'cleaning_fee'    => $cleaningFee,
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

    /* ---- formula: tax on subtotal + cleaning + service (15%), migration seeds 10% service ---- */

    public function test_availability_returns_contract_breakdown(): void
    {
        $unit = $this->unit(price: 1000, cleaningFee: 200);

        // 3 nights: subtotal 3000, service 300 (10%), tax 15% × 3500 = 525, total 4025.
        $this->postJson("/api/v1/units/{$unit->id}/availability", [
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date'   => now()->addDays(13)->toDateString(),
        ])->assertOk()->assertJson([
            'available' => true,
            'pricing'   => [
                'nights'              => 3,
                'subtotal'            => 3000.0,
                'service_fee'         => 300.0,
                'service_fee_percent' => 10.0,
                'cleaning_fee'        => 200.0,
                'taxes'               => 525.0,
                'tax_percent'         => 15.0,
                'total'               => 4025.0,
            ],
        ])->assertJsonMissingPath('pricing.commission_amount'); // partner-facing, never public
    }

    public function test_booking_freezes_same_breakdown_and_halalas_are_exact(): void
    {
        $unit = $this->unit(price: 1000, cleaningFee: 200);

        $this->actingAs($this->guest())->postJson('/api/v1/bookings', [
            'unit_id'    => $unit->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date'   => now()->addDays(13)->toDateString(),
            'guests'     => 2,
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'unit_id'      => $unit->id,
            'subtotal'     => 3000.00,
            'service_fee'  => 300.00,
            'cleaning_fee' => 200.00,
            'taxes'        => 525.00,
            'total_amount' => 4025.00,
        ]);

        // Halalas parity: every line pre-rounded to 2dp → total × 100 is exact
        // (this is the figure /payments/initiate sends to Moyasar).
        $total = (float) \App\Models\Booking::where('unit_id', $unit->id)->value('total_amount');
        $this->assertSame(402500, (int) round($total * 100));
    }

    public function test_cleaning_fee_defaults_to_zero_and_is_partner_editable(): void
    {
        $unit = $this->unit(cleaningFee: 0);
        $this->assertSame(0.0, $unit->fresh()->cleaning_fee);

        $this->actingAs($unit->owner)->putJson("/api/v1/partner/units/{$unit->id}", [
            'cleaning_fee' => 150,
        ])->assertOk();

        $this->assertSame(150.0, $unit->fresh()->cleaning_fee);
    }

    /* ---- platform settings endpoint ---- */

    private function admin(string $role = 'Admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_can_read_settings(): void
    {
        $this->actingAs($this->admin())->getJson('/api/v1/admin/platform-settings')
            ->assertOk()
            // Whole floats JSON-encode as ints (no PRESERVE_ZERO_FRACTION).
            ->assertJsonPath('data.service_fee_percent', 10)
            ->assertJsonPath('data.tax_percent', 15);
    }

    public function test_superadmin_patch_changes_live_pricing(): void
    {
        $this->actingAs($this->admin('SuperAdmin'))
            ->patchJson('/api/v1/admin/platform-settings', ['service_fee_percent' => 12.5])
            ->assertOk()
            ->assertJsonPath('data.service_fee_percent', 12.5);

        // New bookings pick the new rate up immediately (cache busted).
        $this->assertSame(12.5, Pricing::serviceFeePercent());
        $this->assertSame(375.0, Pricing::breakdown(1000, 3, 0)['service_fee']);
    }

    public function test_plain_admin_cannot_patch_settings(): void
    {
        $this->actingAs($this->admin('Admin'))
            ->patchJson('/api/v1/admin/platform-settings', ['service_fee_percent' => 50])
            ->assertStatus(403);
    }

    public function test_tax_percent_is_not_editable_by_anyone(): void
    {
        $this->actingAs($this->admin('SuperAdmin'))
            ->patchJson('/api/v1/admin/platform-settings', [
                'service_fee_percent' => 10,
                'tax_percent'         => 5,
            ])->assertStatus(422)->assertJsonValidationErrors('tax_percent');
    }
}
