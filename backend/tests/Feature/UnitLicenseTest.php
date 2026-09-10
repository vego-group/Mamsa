<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Support\Units\UnitLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A permit is issued to a facility, and it says how many units it covers.
 *
 * A private-hospitality permit covers one home, by definition. A tourist-
 * facility permit covers a property and states a number. Only the second may
 * become a building, and never a larger one than the permit declares.
 *
 * That is a legal limit, so it is checked on every path that can grow a group —
 * including the admin console, which is the one place a human could wave it
 * through, and the one place the offending fact lives on a DIFFERENT ROW than
 * the one being looked at.
 */
class UnitLicenseTest extends TestCase
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
    }

    /* ---------- the pair has to make sense on its own ---------- */

    public function test_a_facility_licence_without_a_unit_count_is_refused(): void
    {
        $this->actingAs($this->partner, 'sanctum')
            ->postJson('/api/v1/partner/units', $this->unitBody([
                'license_type' => UnitLicense::TOURIST_FACILITY,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'LICENSED_UNITS_COUNT_REQUIRED');
    }

    public function test_a_private_licence_cannot_claim_several_units(): void
    {
        $this->actingAs($this->partner, 'sanctum')
            ->postJson('/api/v1/partner/units', $this->unitBody([
                'license_type' => UnitLicense::PRIVATE_HOSPITALITY,
                'licensed_units_count' => 6,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'LICENSED_UNITS_COUNT_NOT_APPLICABLE');
    }

    public function test_a_facility_licence_with_a_count_is_accepted(): void
    {
        $this->actingAs($this->partner, 'sanctum')
            ->postJson('/api/v1/partner/units', $this->unitBody([
                'license_type' => UnitLicense::TOURIST_FACILITY,
                'licensed_units_count' => 12,
            ]))
            ->assertStatus(201);
    }

    /* ---------- v4 §11و: every existing row stays valid ---------- */

    public function test_a_listing_with_no_licence_at_all_is_still_valid(): void
    {
        // Every unit on the platform today is exactly this: no licence type, no
        // count, a group of one. If this fails, the migration broke production.
        $unit = $this->listing();

        $this->assertNull($unit->license_type);
        $this->assertNull($unit->licensed_units_count);
        $this->assertSame(1, UnitLicense::groupSize($unit));

        $this->actingAs($this->partner, 'sanctum')
            ->getJson("/api/v1/partner/units/{$unit->id}")
            ->assertOk();
    }

    /* ---------- growing a group ---------- */

    public function test_a_private_licence_cannot_become_a_building(): void
    {
        $unit = $this->listing(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->expand($unit, ['count' => 4])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MULTI_UNIT_REQUIRES_FACILITY_LICENSE');

        $this->assertSame(1, Unit::where('user_id', $this->partner->id)->count(),
            'a refused expansion must leave no apartments behind');
    }

    public function test_an_unclassified_listing_cannot_become_a_building(): void
    {
        // NULL is not permission. It means nobody has checked yet.
        $this->expand($this->listing(), ['count' => 3])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MULTI_UNIT_REQUIRES_FACILITY_LICENSE');
    }

    public function test_a_group_larger_than_the_permit_is_refused(): void
    {
        $unit = $this->listing([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 8,
        ]);

        $this->expand($unit, ['count' => 10])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUANTITY_EXCEEDS_LICENSED_UNITS')
            ->assertJsonPath('meta.licensed_units_count', 8);
    }

    public function test_a_group_within_the_permit_is_allowed(): void
    {
        $unit = $this->listing([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 8,
        ]);

        $this->expand($unit, ['count' => 8])->assertSuccessful();

        $this->assertSame(8, Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->count());
    }

    public function test_the_feature_flag_stops_new_buildings(): void
    {
        config()->set('units.multi_unit_enabled', false);

        $unit = $this->listing([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 8,
        ]);

        $this->expand($unit, ['count' => 3])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MULTI_UNIT_DISABLED');
    }

    /* ---------- the licence belongs to the group, not the row ---------- */

    public function test_cloned_apartments_inherit_the_licence(): void
    {
        // Unlike the permit FILE, which is opt-in: a file may belong to one
        // apartment, a licence TYPE is a property of the whole facility.
        $unit = $this->listing([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 5,
        ]);

        $this->expand($unit, ['count' => 5])->assertSuccessful();

        $group = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->get();

        $this->assertCount(5, $group);
        $this->assertSame([UnitLicense::TOURIST_FACILITY], $group->pluck('license_type')->unique()->all());
        $this->assertSame([5], $group->pluck('licensed_units_count')->map(intval(...))->unique()->all());
    }

    public function test_editing_the_licence_writes_it_across_the_whole_group(): void
    {
        $unit = $this->listing([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 5,
        ]);
        $this->expand($unit, ['count' => 5])->assertSuccessful();

        $this->actingAs($this->partner, 'sanctum')
            ->putJson("/api/v1/partner/units/{$unit->id}", ['licensed_units_count' => 9])
            ->assertOk();

        // A group whose members disagree about their permit means an apartment
        // trading under a licence that does not cover it.
        $counts = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)
            ->pluck('licensed_units_count')->map(intval(...))->unique();

        $this->assertSame([9], $counts->all());
    }

    public function test_a_building_cannot_be_downgraded_to_a_private_licence(): void
    {
        $unit = $this->listing([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 5,
        ]);
        $this->expand($unit, ['count' => 5])->assertSuccessful();

        $this->actingAs($this->partner, 'sanctum')
            ->putJson("/api/v1/partner/units/{$unit->id}", [
                'license_type' => UnitLicense::PRIVATE_HOSPITALITY,
                'licensed_units_count' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'LICENSE_DOWNGRADE_BLOCKED_BY_QUANTITY');

        $this->assertSame(UnitLicense::TOURIST_FACILITY, $unit->fresh()->license_type);
    }

    public function test_the_permit_cannot_be_shrunk_below_the_building(): void
    {
        $unit = $this->listing([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 10,
        ]);
        $this->expand($unit, ['count' => 10])->assertSuccessful();

        $this->actingAs($this->partner, 'sanctum')
            ->putJson("/api/v1/partner/units/{$unit->id}", ['licensed_units_count' => 4])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUANTITY_EXCEEDS_LICENSED_UNITS');
    }

    /* ---------- v4 §11ج: the admin console cannot wave it through ---------- */

    public function test_an_admin_cannot_approve_an_apartment_of_an_unlicensed_building(): void
    {
        $unit = $this->listing([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 5,
        ]);
        $this->expand($unit, ['count' => 5])->assertSuccessful();

        // The permit is revoked at the database, behind every application rule —
        // the state a reviewer would actually be shown after someone edited it
        // out of band. The console still has to refuse.
        Unit::where('unit_group_id', $unit->fresh()->unit_group_id)
            ->update(['license_type' => UnitLicense::PRIVATE_HOSPITALITY, 'licensed_units_count' => null]);

        $pending = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)
            ->where('approval_status', 'draft')->firstOrFail();
        $pending->forceFill(['approval_status' => 'pending'])->save();

        $this->actingAs($this->admin(), 'admin-panel')
            ->postJson("/admin/approvals/{$pending->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'MULTI_UNIT_REQUIRES_FACILITY_LICENSE');

        $this->assertSame('pending', $pending->fresh()->approval_status);
    }

    public function test_an_admin_can_approve_an_ordinary_standalone_listing(): void
    {
        // The guard must not turn into "nothing can be approved".
        $unit = $this->listing();
        $unit->forceFill(['approval_status' => 'pending'])->save();

        $this->actingAs($this->admin(), 'admin-panel')
            ->postJson("/admin/approvals/{$unit->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $unit->fresh()->approval_status);
    }

    /* ---------- fixtures ---------- */

    /** @param array<string, mixed> $licence */
    private function listing(array $licence = []): Unit
    {
        return $this->partner->units()->create(array_merge([
            'unit_name' => 'برج تجريبي',
            'unit_type' => 'apartment',
            'code' => 'LIC'.fake()->unique()->numerify('#####'),
            'price' => 500,
            'capacity' => 2,
            'bedrooms' => 1,
            'city' => 'الرياض',
            'approval_status' => 'approved',
            'status' => 'available',
            'checkout_time' => '12:00',
            'calendar_token' => str()->random(60),
        ], $licence));
    }

    /** @param array<string, mixed> $overrides */
    private function unitBody(array $overrides = []): array
    {
        return array_merge([
            'unit_name' => 'وحدة جديدة',
            'unit_type' => 'apartment',
            'price' => 400,
            'capacity' => 2,
            'bedrooms' => 1,
            'city' => 'الرياض',
        ], $overrides);
    }

    /** @param array<string, mixed> $body */
    private function expand(Unit $unit, array $body): TestResponse
    {
        return $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", $body);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('SuperAdmin');

        return $admin;
    }
}
