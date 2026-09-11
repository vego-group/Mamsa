<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Support\Units\UnitLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Expanding a listing into a building, from the surface partners actually use.
 *
 * The logic has been tested since 2026-08-30, but only through the Bearer
 * `/api/v1` route. This dashboard had none, so the feature built for the
 * partner with ten apartments had no entry point for them. These tests cover
 * the route itself and the two things a client can get wrong about it: that
 * `count` is a total rather than an addition, and that the licence is checked
 * before any apartment is written.
 */
class DashboardApartmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        config()->set('units.multi_unit_enabled', true);

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->partner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);
    }

    public function test_the_route_exists_on_the_dashboard_surface(): void
    {
        // The point of the whole change: this used to be a 404 here, and the
        // feature was unreachable from the dashboard entirely.
        $unit = $this->licensed(8);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$unit->id}/apartments", ['count' => 5])
            ->assertOk()
            ->assertJsonPath('groupSize', 5)
            ->assertJsonPath('added', 4);

        $this->assertSame(5, Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->count());
    }

    public function test_count_is_a_total_not_an_addition(): void
    {
        // "I have 8" on a building of 5 adds three. The difference between this
        // and "add 8" is a building of eight versus a building of thirteen.
        $unit = $this->licensed(10);

        $this->expand($unit, 5)->assertOk()->assertJsonPath('groupSize', 5);
        $this->expand($unit, 8)->assertOk()
            ->assertJsonPath('groupSize', 8)
            ->assertJsonPath('added', 3);

        $this->assertSame(8, Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->count());
    }

    public function test_resending_the_same_count_adds_nothing(): void
    {
        $unit = $this->licensed(10);

        $this->expand($unit, 6)->assertOk()->assertJsonPath('added', 6 - 1);
        $this->expand($unit, 6)->assertOk()->assertJsonPath('added', 0);

        $this->assertSame(6, Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->count());
    }

    public function test_a_count_over_the_permit_is_refused_before_anything_is_written(): void
    {
        $unit = $this->licensed(4);

        $this->expand($unit, 9)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'QUANTITY_EXCEEDS_LICENSED_UNITS');

        $this->assertSame(1, Unit::where('user_id', $this->partner->id)->count(),
            'a refused expansion must not leave apartments behind');
    }

    public function test_an_unlicensed_listing_cannot_expand_from_the_dashboard(): void
    {
        $unit = $this->unit(); // no licence at all

        $this->expand($unit, 3)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MULTI_UNIT_REQUIRES_FACILITY_LICENSE');
    }

    public function test_a_partner_cannot_expand_someone_elses_listing(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Individual');
        $stranger = $other->units()->create([
            'unit_name' => 'وحدة غريبة', 'unit_type' => 'apartment',
            'code' => 'OTH'.fake()->unique()->numerify('#####'),
            'price' => 400, 'capacity' => 2, 'bedrooms' => 1, 'city' => 'الرياض',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 9,
        ]);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$stranger->id}/apartments", ['count' => 4])
            ->assertStatus(404);

        $this->assertSame(1, Unit::where('user_id', $other->id)->count());
    }

    public function test_the_count_is_required(): void
    {
        // 400 VALIDATION, not 422 — that is this surface's convention for a
        // malformed body, and it differs from the licence refusals below, which
        // are 422 with their own codes. A client that treats every rejection as
        // one status would tell the partner the wrong thing.
        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$this->licensed(5)->id}/apartments", [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }

    /* ---------- fixtures ---------- */

    private function expand(Unit $unit, int $count): TestResponse
    {
        return $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$unit->id}/apartments", ['count' => $count]);
    }

    private function licensed(int $licensedUnits): Unit
    {
        return $this->unit([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => $licensedUnits,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function unit(array $extra = []): Unit
    {
        return $this->partner->units()->create(array_merge([
            'unit_name' => 'برج الشريك', 'unit_type' => 'apartment',
            'code' => 'PDU'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'city' => 'الرياض',
            'approval_status' => 'approved', 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ], $extra));
    }
}
