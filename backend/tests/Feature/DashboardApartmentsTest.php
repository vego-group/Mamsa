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
            ->assertJsonPath('error.code', 'QUANTITY_EXCEEDS_LICENSED_UNITS')
            // The dashboard says "your permit covers 4 units" from these two
            // numbers. Without them it renders `undefined`, and the refusal
            // stops being machine-readable one argument short of the envelope.
            ->assertJsonPath('error.meta.licensed_units_count', 4)
            ->assertJsonPath('error.meta.requested', 9);

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

    public function test_a_refusal_with_nothing_to_report_carries_no_meta_key(): void
    {
        // An empty meta would make clients branch on a key that means nothing.
        $this->expand($this->unit(), 3)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MULTI_UNIT_REQUIRES_FACILITY_LICENSE')
            ->assertJsonPath('error.meta.max_units', 1);
    }

    public function test_new_apartments_are_filed_for_review_and_cannot_be_sold_unreviewed(): void
    {
        // Expanding does not let a partner add sellable apartments without
        // review. The new rows go straight into the queue as `pending`, and
        // pending is excluded from search, from the availability count, and
        // from the allocation the booking endpoint picks from. The declared
        // licence bounds how many CAN exist; an admin still approves each one
        // before it can be sold.
        $unit = $this->licensed(9);

        $body = $this->expand($unit, 4)->assertOk()->json();

        $new = collect($body['units'])->where('status', 'pending');
        $this->assertCount(3, $new, 'the three new apartments are filed for review, not left as drafts');
        $this->assertSame('approved', collect($body['units'])->firstWhere('id', 'u_'.$unit->id)['status']);

        // The building still advertises only what was already approved.
        $this->getJson("/api/v1/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.available_count', 1);
    }

    public function test_the_new_apartments_are_submitted_not_left_as_drafts(): void
    {
        // The decision: pressing "add apartments" IS the declaration, so the
        // expansion files them. Leaving drafts behind would hand back part of
        // the work the feature exists to remove.
        $unit = $this->licensed(9);

        $this->expand($unit, 4)->assertOk();

        $states = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)
            ->pluck('approval_status')->countBy();

        $this->assertSame(1, $states['approved'] ?? 0, 'the original keeps selling');
        $this->assertSame(3, $states['pending'] ?? 0, 'the new ones are in the queue');
        $this->assertSame(0, $states['draft'] ?? 0, 'nothing is left invisible');
    }

    public function test_the_permit_travels_with_the_new_apartments(): void
    {
        // Submission requires a permit file, and a clone has none unless the
        // documents are copied. Copying is justified by the licence: expansion
        // is refused unless it is tourist_facility, and a facility permit
        // covers the property. Without this, every auto-submit fails.
        $unit = $this->licensed(5);

        $this->expand($unit, 3)->assertOk();

        $clone = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)
            ->where('id', '!=', $unit->id)->firstOrFail();

        $this->assertSame($unit->tourism_permit_no, $clone->tourism_permit_no);
        $this->assertSame($unit->tourism_permit_file, $clone->tourism_permit_file);
    }

    public function test_an_expansion_that_cannot_be_filed_rolls_back_entirely(): void
    {
        // Atomicity is the whole point of doing this server-side. A building
        // half filed and half invisible is the failure the decision named.
        $unit = $this->licensed(9);
        $unit->forceFill(['address' => null])->save(); // fails the submit gate

        $this->expand($unit, 4)->assertStatus(400);

        $this->assertSame(1, Unit::where('user_id', $this->partner->id)->count(),
            'no apartment may survive a rolled-back expansion');
    }

    /* ---------- classifying a listing from the dashboard ---------- */

    public function test_a_partner_can_classify_a_listing_from_the_dashboard(): void
    {
        // Until this worked, no partner could ever reach tourist_facility from
        // the surface they use — so every expansion was refused for want of a
        // licence no screen could set. The whole feature was unreachable.
        $unit = $this->unit();

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", [
                'licenseType' => UnitLicense::TOURIST_FACILITY,
                'licensedUnitsCount' => 6,
            ])
            ->assertOk();

        $fresh = $unit->fresh();
        $this->assertSame(UnitLicense::TOURIST_FACILITY, $fresh->license_type);
        $this->assertSame(6, (int) $fresh->licensed_units_count);

        // And it is immediately usable: classify, then expand.
        $this->expand($fresh, 4)->assertOk()->assertJsonPath('groupSize', 4);
    }

    public function test_classifying_as_a_facility_without_a_count_is_refused(): void
    {
        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$this->unit()->id}", [
                'licenseType' => UnitLicense::TOURIST_FACILITY,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LICENSED_UNITS_COUNT_REQUIRED');
    }

    public function test_the_licence_edit_reaches_every_apartment_in_the_group(): void
    {
        $unit = $this->licensed(9);
        $this->expand($unit, 3)->assertOk();

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", ['licensedUnitsCount' => 7])
            ->assertOk();

        $counts = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)
            ->pluck('licensed_units_count')->map(intval(...))->unique();

        $this->assertSame([7], $counts->all(), 'the group must not disagree about its permit');
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
        $unit = $this->partner->units()->create(array_merge([
            'unit_name' => 'برج الشريك', 'unit_type' => 'apartment',
            'code' => 'PDU'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'city' => 'الرياض',
            'approval_status' => 'approved', 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
            // Everything submitErrors() demands — the clones inherit these, and
            // without them auto-submit would fail for a reason unrelated to
            // what each test is checking.
            'beds' => 2, 'bathrooms' => 1, 'address' => 'حي الملقا',
            'lat' => 24.7136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ لهذه الوحدة. ', 8),
            'tourism_permit_no' => 'TL-'.fake()->unique()->numerify('######'),
            'tourism_permit_file' => 'dashboard/license_pdf/file_test.pdf',
        ], $extra));

        // A listing cannot be submitted without at least one real photo, and
        // the clones inherit the source's image rows — so a source with none
        // would fail auto-submit for a reason none of these tests is about.
        $unit->images()->create([
            'path' => 'units/'.$unit->id.'/photo.jpg',
            'is_main' => true,
            'sort_order' => 1,
        ]);

        return $unit;
    }
}
