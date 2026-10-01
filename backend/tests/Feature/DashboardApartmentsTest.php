<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permits\PermitWriter;
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

    public function test_an_incomplete_source_is_refused_naming_the_source(): void
    {
        // A source missing its permit copies that gap into every apartment, and
        // auto-submit then fails on rows the partner never saw — an error about
        // `tourismLicenseFileId` on apartments that do not exist yet, which
        // reads as "the system lost my documents". The refusal has to name the
        // listing they actually have.
        $unit = $this->licensed(9);
        PermitWriter::apply($unit, ['file' => null]);

        $this->expand($unit, 4)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_UNIT_INCOMPLETE')
            ->assertJsonPath('error.fields.tourismLicenseFileId', 'ملف الرخصة مطلوب')
            ->assertJsonPath('error.meta.unit_id', 'u_'.$unit->id);

        $this->assertSame(1, Unit::where('user_id', $this->partner->id)->count(),
            'nothing may be written when the source cannot be copied');
    }

    public function test_an_expansion_that_cannot_be_filed_rolls_back_entirely(): void
    {
        // Atomicity, on a path the pre-flight cannot see.
        //
        // The pre-flight checks the SOURCE's own fields, so a complete source
        // gets past it — but submission also requires a COMPANY to have
        // finished its payout documents, and that is a property of the partner,
        // not the unit. A company with incomplete documents therefore reaches
        // the loop, fails inside it, and the whole expansion must unwind.
        //
        // This is the case the transaction exists for: the one the guard in
        // front of it was never going to catch.
        $unit = $this->licensed(9);

        $this->partner->partnerDetail()->update(['type' => 'company']);

        $this->expand($unit, 4)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'COMPANY_DOCS_INCOMPLETE');

        $this->assertSame(1, Unit::where('user_id', $this->partner->id)->count(),
            'no apartment may survive a rolled-back expansion');
        $this->assertNull($unit->fresh()->unit_group_id,
            'the source must not keep a group id for a building that was rolled back');
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

    public function test_the_partner_list_says_which_building_each_row_belongs_to(): void
    {
        // Without groupId the partner opens their list and sees five rows that
        // look like five listings they do not remember creating. The grouping
        // exists in the data and has to exist in the response.
        $source = $this->licensed(8);
        $this->expand($source, 3)->assertOk();

        $rows = $this->actingAs($this->partner, 'dashboard')
            ->getJson('/units?limit=50')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $rows);

        $groupId = $source->fresh()->unit_group_id;
        $this->assertNotNull($groupId);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('groupId', $row);
            $this->assertArrayHasKey('apartmentNo', $row);
            $this->assertSame($groupId, $row['groupId'], 'a door of the building did not name it');
            $this->assertNotNull($row['apartmentNo'], 'a door in a building has no number');
        }

        // Grouping by the key gives back one building of three, which is the
        // whole point: the client can fold the rows without asking anything.
        $this->assertSame([3], array_values(array_map(
            'count',
            collect($rows)->groupBy('groupId')->all(),
        )));
    }

    public function test_a_standalone_listing_names_no_building(): void
    {
        // Null, not a group of one: the client branches on presence instead of
        // comparing a size to 1, and a lone listing renders as a listing.
        $unit = $this->unit();

        $row = $this->actingAs($this->partner, 'dashboard')
            ->getJson("/units/u_{$unit->id}")
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('groupId', $row);
        $this->assertArrayHasKey('apartmentNo', $row);
        $this->assertNull($row['groupId']);
        $this->assertNull($row['apartmentNo']);
        $this->assertSame(1, $row['groupSize']);
    }

    /* ---------- a stable order for the list (2026-10-01) ---------- */

    public function test_rows_created_in_the_same_second_page_in_a_fixed_order(): void
    {
        // A building's doors are created in one second, so `latest()` alone
        // leaves their order to the database — and a page boundary inside a
        // building can then show a door twice or not at all. id breaks the tie.
        $older = $this->unit();
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $tie = now()->subHour()->startOfSecond();
        $same = collect(range(1, 5))->map(function () use ($tie) {
            $u = $this->unit();
            $u->forceFill(['created_at' => $tie])->save();

            return $u->id;
        });

        $newer = $this->unit();

        $seen = collect(range(1, 4))->flatMap(fn (int $page) => collect(
            $this->actingAs($this->partner, 'dashboard')->getJson("/units?limit=2&page={$page}")->assertOk()->json('data')
        )->pluck('id'))->all();

        $expected = collect([$newer->id])
            ->merge($same->sortDesc()->values())
            ->push($older->id)
            ->map(fn (int $id) => 'u_'.$id)
            ->all();

        $this->assertSame($expected, $seen, 'newest first, and the same-second rows by id descending, across every page');
    }

    /* ---------- any door, not just the original (asked 2026-09-30) ---------- */

    // The building card shows on every door's page, so "add apartments" is
    // pressed from whichever door the partner has open. These pin what that
    // means: any door works, the count is still the building's total, and the
    // new apartments copy THE DOOR THEY WERE ADDED FROM — there is no fixed
    // "source" in a building once it exists.

    public function test_any_door_in_the_building_can_add_apartments(): void
    {
        $original = $this->licensed(5);
        $this->expand($original, 2)->assertOk();
        $door2 = $this->door($original, 2);

        // Still under review from the first expansion — and that does not
        // stop it either: the lock is on editing a door, not on this.
        $this->assertSame('pending', $door2->approval_status);

        $this->expand($door2, 4)
            ->assertOk()
            ->assertJsonPath('groupId', $original->fresh()->unit_group_id)
            ->assertJsonPath('groupSize', 4)
            ->assertJsonPath('added', 2);
    }

    public function test_count_is_the_building_total_whichever_door_sends_it(): void
    {
        $original = $this->licensed(5);
        $this->expand($original, 3)->assertOk();

        $this->expand($this->door($original, 3), 3)
            ->assertOk()
            ->assertJsonPath('groupSize', 3)
            ->assertJsonPath('added', 0);
    }

    public function test_new_apartments_copy_the_door_they_were_added_from(): void
    {
        $original = $this->licensed(5);
        $this->expand($original, 2)->assertOk();
        $door2 = $this->door($original, 2);
        $door2->forceFill(['price' => 900])->save();

        $this->expand($door2, 3)->assertOk();

        $door3 = $this->door($original, 3);
        $this->assertEquals(900, (float) $door3->price, 'copied the original, not the door it was added from');
        $this->assertEquals(500, (float) $original->fresh()->price);
    }

    public function test_an_incomplete_door_is_the_one_named_in_the_refusal(): void
    {
        // The completeness check runs on the door that was sent, so the
        // refusal names THAT door — even when the original is complete.
        $original = $this->licensed(5);
        $this->expand($original, 2)->assertOk();
        $door2 = $this->door($original, 2);
        $door2->forceFill(['description' => null])->save();

        $this->expand($door2, 3)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_UNIT_INCOMPLETE')
            ->assertJsonPath('error.meta.unit_id', 'u_'.$door2->id);
    }

    /* ---------- fixtures ---------- */

    /** The n-th door of the original's building, by creation order. */
    private function door(Unit $original, int $n): Unit
    {
        return Unit::where('unit_group_id', $original->fresh()->unit_group_id)->orderBy('id')->skip($n - 1)->firstOrFail();
    }

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
