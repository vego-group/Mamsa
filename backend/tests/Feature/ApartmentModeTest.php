<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DashboardUpload;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permits\PermitMode;
use App\Support\Permits\PermitUniqueness;
use App\Support\Permits\PermitWriter;
use App\Support\Units\UnitLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4: the two ways a building can be licensed.
 *
 *  - single_permit — one facility permit issued to the property, covering every
 *    door. Shipped in phases 1–3.
 *  - per_unit — every apartment carries its own permit. A private-hospitality
 *    permit names one specific unit, so a building of them is not one licence
 *    covering five doors; it is five licences that share an address.
 *
 * The mode is read from the licence the group already carries rather than
 * stored, and a group has exactly one. The failures these tests exist to stop
 * are apartments created covered by nothing, and one apartment's licence being
 * copied onto its neighbours.
 */
class ApartmentModeTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        config()->set('units.multi_unit_enabled', true);
        config()->set('permits.uniqueness_exceptions', []);

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');
    }

    /* ---------- the mode itself ---------- */

    public function test_the_mode_is_read_from_the_licence_not_stored(): void
    {
        $this->assertSame(PermitMode::SINGLE, PermitMode::of($this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 4])));
        $this->assertSame(PermitMode::PER_UNIT, PermitMode::of($this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY])));
        $this->assertNull(PermitMode::of($this->unit()), 'an unclassified listing is in neither mode');
    }

    public function test_an_unclassified_listing_still_cannot_become_a_building(): void
    {
        // NULL is not permission. It means nobody has checked yet.
        $this->expand($this->unit(), 3)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MULTI_UNIT_REQUIRES_FACILITY_LICENSE');

        $this->assertSame(1, Unit::count());
    }

    /* ---------- the shape must match the mode ---------- */

    public function test_a_facility_building_refuses_per_apartment_permits(): void
    {
        // Sending permits here says "each door has its own", which contradicts
        // the facility permit the building already trades under.
        $unit = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 6]);

        $this->expand($unit, 3, [['number' => 'A-1'], ['number' => 'A-2']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERMIT_MODE_MIXED')
            ->assertJsonPath('error.meta.group_mode', PermitMode::SINGLE);

        $this->assertSame(1, Unit::count());
    }

    public function test_a_per_unit_building_refuses_an_expansion_with_no_permits(): void
    {
        $unit = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->expand($unit, 3)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERMIT_MODE_MIXED')
            ->assertJsonPath('error.meta.group_mode', PermitMode::PER_UNIT);

        $this->assertSame(1, Unit::count(), 'apartments covered by nothing were created');
    }

    public function test_the_permits_must_number_exactly_the_new_apartments(): void
    {
        // Two doors becoming four owes TWO permits, not four — telling the
        // partner "2 of 4" would send them looking for documents they do not
        // need.
        $unit = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);
        $this->expand($unit, 2, [['number' => 'P-1']])->assertOk();

        $this->expand($unit->fresh(), 4, [['number' => 'P-2']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERMITS_COUNT_MISMATCH')
            ->assertJsonPath('error.meta.requested', 2)
            ->assertJsonPath('error.meta.permits_provided', 1);

        $this->assertSame(2, Unit::count(), 'a refused expansion left apartments behind');
    }

    /* ---------- per-unit expansion ---------- */

    public function test_each_new_apartment_gets_its_own_permit_and_nothing_is_copied(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY, 'tourism_permit_no' => 'SRC-1']);

        $this->expand($source, 3, [
            ['number' => 'DOOR-2', 'expiresAt' => now()->addYear()->toDateString()],
            ['number' => 'DOOR-3', 'expiresAt' => now()->addMonths(6)->toDateString()],
        ])->assertOk()->assertJsonPath('groupSize', 3)->assertJsonPath('added', 2);

        $group = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->orderBy('id')->get();
        $this->assertCount(3, $group);

        // Three doors, three DIFFERENT permits, each scoped to its own row.
        $numbers = $group->map(fn (Unit $u) => $u->tourism_permit_no)->all();
        $this->assertSame(['SRC-1', 'DOOR-2', 'DOOR-3'], $numbers, 'a permit was copied onto a neighbour');

        foreach ($group as $door) {
            $permit = Permit::currentFor($door);
            $this->assertNotNull($permit, "door {$door->apartment_no} is covered by nothing");
            $this->assertSame(Permit::SCOPE_UNIT, $permit->scope_type);
            $this->assertSame((string) $door->id, $permit->scope_id);
            $this->assertSame(UnitLicense::PRIVATE_HOSPITALITY, $permit->license_type);
        }

        $this->assertSame(3, Permit::count(), 'a per-unit building holds one permit per door');
    }

    public function test_a_permit_may_name_the_apartment_it_belongs_to(): void
    {
        // The partner filled a form per apartment; the door numbers are how
        // they think about them, so the order they arrived in must not decide.
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);
        $this->expand($source, 3, [['number' => 'X-1'], ['number' => 'X-2']])->assertOk();

        $doors = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->orderBy('apartment_no')->get();
        $third = $doors->last();

        $this->assertSame('X-2', $third->tourism_permit_no);

        // Now expand naming the door explicitly, out of order.
        $this->expand($source->fresh(), 5, [
            ['apartmentNo' => '5', 'number' => 'NAMED-5'],
            ['apartmentNo' => '4', 'number' => 'NAMED-4'],
        ])->assertOk();

        $this->assertSame('NAMED-4', Unit::where('unit_group_id', $source->fresh()->unit_group_id)->where('apartment_no', '4')->value('tourism_permit_no'));
        $this->assertSame('NAMED-5', Unit::where('unit_group_id', $source->fresh()->unit_group_id)->where('apartment_no', '5')->value('tourism_permit_no'));
    }

    public function test_a_facility_building_still_copies_the_one_permit(): void
    {
        // The control: mode B must be unchanged by all of this.
        $source = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 6, 'tourism_permit_no' => 'FAC-1']);

        $this->expand($source, 3)->assertOk();

        $group = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->get();
        foreach ($group as $door) {
            $this->assertSame('FAC-1', $door->tourism_permit_no);
        }

        $this->assertSame(1, Permit::count(), 'a facility building holds ONE permit');
        $this->assertSame(Permit::SCOPE_GROUP, Permit::first()->scope_type);
    }

    /* ---------- uniqueness ---------- */

    public function test_a_number_already_used_by_another_listing_is_refused(): void
    {
        $other = $this->unit(['tourism_permit_no' => 'TAKEN-1']);
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->expand($source, 2, [['number' => 'TAKEN-1']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DUPLICATE_PERMIT_NUMBER')
            ->assertJsonPath('error.meta.permit_number', 'TAKEN-1')
            ->assertJsonPath('error.meta.claimed_by_unit_id', $other->id);

        $this->assertSame(2, Unit::count(), 'the expansion was not rolled back');
    }

    public function test_the_same_number_twice_inside_one_request_is_refused(): void
    {
        // Refused either way — the per-apartment check would catch the second
        // one after the first was written, and the transaction would roll back.
        // What the early check buys is an honest answer: "duplicated inside
        // this request", with no `claimed_by_unit_id` pointing at a row that is
        // about to disappear. The spellings differ on purpose; they normalise
        // to the same number.
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $response = $this->expand($source, 3, [['number' => 'SAME-1'], ['number' => ' same-1 ']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DUPLICATE_PERMIT_NUMBER')
            ->assertJsonPath('error.meta.permit_number', 'SAME-1');

        $this->assertArrayNotHasKey(
            'claimed_by_unit_id',
            $response->json('error.meta'),
            'the refusal pointed at a row that only existed inside the rolled-back transaction',
        );

        $this->assertSame(1, Unit::count());
    }

    public function test_the_building_may_reuse_its_own_number_across_a_rewrite(): void
    {
        // A permit keeping its own number must not collide with itself.
        $unit = $this->unit(['tourism_permit_no' => 'MINE-1']);

        PermitWriter::apply($unit, ['number' => 'MINE-1', 'expires_at' => now()->addYear()->toDateString()]);

        $this->assertSame('MINE-1', Permit::currentFor($unit->fresh())->number);
    }

    public function test_a_recorded_exception_is_allowed_to_stay_duplicated(): void
    {
        // Production carries exactly one: the same private permit was attached
        // to two listings before anything checked. Refusing it would block
        // every future edit on both.
        config()->set('permits.uniqueness_exceptions', ['50047139']);

        $first = $this->unit(['tourism_permit_no' => '50047139']);
        $second = $this->unit(['tourism_permit_no' => null, 'tourism_permit_file' => null]);

        PermitWriter::apply($second, ['number' => '50047139']);

        $this->assertSame('50047139', Permit::currentFor($first->fresh())->number);
        $this->assertSame('50047139', Permit::currentFor($second->fresh())->number);
        $this->assertSame([], PermitUniqueness::duplicates(), 'a recorded exception must not be reported every morning');
    }

    public function test_an_unrecorded_duplicate_is_reported_by_the_daily_check(): void
    {
        config()->set('permits.uniqueness_exceptions', []);

        $first = $this->unit(['tourism_permit_no' => 'DUP-9']);
        $second = $this->unit(['tourism_permit_no' => 'DUP-9']);   // written at create, around the writer

        $duplicates = PermitUniqueness::duplicates();

        $this->assertCount(1, $duplicates);
        $this->assertSame('DUP-9', $duplicates[0]->number);
        $this->assertSame(2, (int) $duplicates[0]->scopes);
    }

    /* ---------- publishing a per-unit door ---------- */

    public function test_an_apartment_with_no_permit_of_its_own_cannot_be_approved(): void
    {
        // The door's neighbours' permits name other apartments; this one is
        // covered by nothing.
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);
        $this->expand($source, 2, [['number' => 'OK-2']])->assertOk();

        $door = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->where('id', '!=', $source->id)->firstOrFail();

        // Its permit is removed behind the application's back.
        Permit::where('scope_type', Permit::SCOPE_UNIT)->where('scope_id', (string) $door->id)->delete();
        $door->forceFill(['approval_status' => 'pending'])->save();

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/approvals/{$door->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERMIT_MODE_MIXED');

        $this->assertSame('pending', $door->fresh()->approval_status);
    }

    public function test_a_licensed_door_approves_normally(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);
        $this->expand($source, 2, [['number' => 'OK-2']])->assertOk();

        $door = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->where('approval_status', 'pending')->firstOrFail();

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/approvals/{$door->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $door->fresh()->approval_status);
    }

    /* ---------- the admin surface does the same ---------- */

    public function test_the_admin_console_expands_a_per_unit_platform_building(): void
    {
        $source = $this->unit([
            'license_type' => UnitLicense::PRIVATE_HOSPITALITY, 'mamsa_owned' => true,
        ], User::platform());

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/units/{$source->id}/apartments", [
                'count' => 3,
                'permits' => [
                    ['number' => 'MAMSA-2', 'fileId' => $this->licenceFile($this->admin)],
                    ['number' => 'MAMSA-3', 'fileId' => $this->licenceFile($this->admin)],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('groupSize', 3)
            ->assertJsonPath('added', 2);

        $group = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->get();
        $this->assertSame(3, Permit::count());
        foreach ($group as $door) {
            $this->assertSame((string) $door->id, Permit::currentFor($door)->scope_id);
        }
    }

    public function test_the_admin_console_refuses_the_wrong_shape_too(): void
    {
        $source = $this->unit([
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 4, 'mamsa_owned' => true,
        ], User::platform());

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/units/{$source->id}/apartments", ['count' => 2, 'permits' => [['number' => 'X', 'fileId' => $this->licenceFile($this->admin)]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERMIT_MODE_MIXED')
            ->assertJsonPath('meta.group_mode', PermitMode::SINGLE);
    }

    /* ---------- one step: submit that also expands ---------- */

    public function test_submit_can_create_the_building_and_file_it_in_one_call(): void
    {
        // The wizard asks for the apartment count on the same screen as
        // everything else. Two calls — /apartments then /submit — means a
        // failure on the second leaves the partner with pending apartments and
        // a draft they still have to find and file themselves.
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $body = $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$source->id}/submit", [
                'count' => 3,
                'permits' => [
                    ['number' => 'ONE-2', 'fileId' => $this->licenceFile($this->partner)],
                    ['number' => 'ONE-3', 'fileId' => $this->licenceFile($this->partner)],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('groupSize', 3)
            ->json();

        $this->assertCount(3, $body['units']);

        // EVERY door is filed, the source included — no draft left behind.
        $group = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->get();
        $this->assertCount(3, $group);
        foreach ($group as $door) {
            $this->assertSame('pending', $door->approval_status, "door {$door->apartment_no} was left out of the filing");
            $this->assertNotNull(Permit::currentFor($door), "door {$door->apartment_no} is covered by nothing");
        }

        $this->assertSame(3, Permit::count());
    }

    public function test_submit_without_a_count_is_exactly_the_old_behaviour(): void
    {
        $source = $this->unit(['approval_status' => 'draft']);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$source->id}/submit")
            ->assertOk()
            ->assertJsonPath('groupSize', 1)
            ->assertJsonPath('groupId', null);

        $this->assertSame('pending', $source->fresh()->approval_status);
        $this->assertNull($source->fresh()->unit_group_id, 'a plain submit made a building');
    }

    public function test_submit_expands_a_facility_building_without_permits(): void
    {
        $source = $this->unit([
            'approval_status' => 'draft',
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 6,
        ]);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$source->id}/submit", ['count' => 4])
            ->assertOk()
            ->assertJsonPath('groupSize', 4);

        $this->assertSame(4, Unit::where('unit_group_id', $source->fresh()->unit_group_id)->where('approval_status', 'pending')->count());
        $this->assertSame(1, Permit::count(), 'a facility building holds one permit');
    }

    public function test_submit_refuses_the_wrong_shape_and_writes_nothing(): void
    {
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$source->id}/submit", ['count' => 3])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERMIT_MODE_MIXED');

        $this->assertSame(1, Unit::count());
        $this->assertSame('draft', $source->fresh()->approval_status, 'a refused expansion still filed the source');
    }

    public function test_a_refused_expansion_at_submit_leaves_the_draft_a_draft(): void
    {
        // The whole point of one transaction: a duplicate number discovered
        // halfway must not leave the partner with half a building.
        $other = $this->unit(['tourism_permit_no' => 'TAKEN-9']);
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$source->id}/submit", [
                'count' => 3,
                'permits' => [
                    ['number' => 'FINE-2', 'fileId' => $this->licenceFile($this->partner)],
                    ['number' => 'TAKEN-9', 'fileId' => $this->licenceFile($this->partner)],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DUPLICATE_PERMIT_NUMBER');

        $this->assertSame(2, Unit::count(), 'apartments survived a refused expansion');
        $this->assertSame('draft', $source->fresh()->approval_status);
        $this->assertNull($source->fresh()->unit_group_id);
    }

    public function test_an_apartment_that_cannot_be_filed_rolls_the_whole_expansion_back(): void
    {
        // The source passes the submit gate and a NEW apartment does not — the
        // permit it arrived with has already lapsed. Checking only the source
        // would file that door under a dead permit and put it in the admin
        // queue, which is the one thing the expiry rule exists to stop.
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$source->id}/submit", [
                'count' => 3,
                'permits' => [
                    ['number' => 'LIVE-2', 'fileId' => $this->licenceFile($this->partner), 'expiresAt' => now()->addYear()->toDateString()],
                    ['number' => 'DEAD-3', 'fileId' => $this->licenceFile($this->partner), 'expiresAt' => now()->subDay()->toDateString()],
                ],
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonStructure(['error' => ['fields' => ['permitExpiresAt']]]);

        $this->assertSame(1, Unit::count(), 'an apartment that could not be filed survived the refusal');
        $this->assertSame('draft', $source->fresh()->approval_status);
        $this->assertNull($source->fresh()->unit_group_id);
        $this->assertSame(1, Permit::count(), 'a permit outlived the expansion that wrote it');
    }

    /* ---------- a card's apartmentNo NAMES its door (2026-10-01) ---------- */

    // Until 2026-10-01 the doors were numbered first and the cards matched to
    // them afterwards, so a name outside the automatic numbers matched nothing
    // and came back as PERMITS_COUNT_MISMATCH with EQUAL counts. A door number
    // is a name — "402", "7", "الدور الثالث" — not an index.

    public function test_the_frontends_exact_request_now_names_the_door_seven(): void
    {
        // Reported by the partner dashboard's staging pass on u_78.
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/submit", [
            'count' => 3,
            'permits' => [
                ['number' => 'CARD-11', 'fileId' => $this->licenceFile($this->partner)],
                ['number' => 'CARD-12', 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => '7'],
            ],
        ])->assertOk();

        $doors = $this->doorsOf($source);
        $this->assertSame(['1', '2', '7'], array_map('strval', array_keys($doors)), 'the automatic numbers should continue from the building, not from 7');
        $this->assertSame('CARD-12', $doors['7'], 'the named card did not land on the door it named');
        $this->assertSame('CARD-11', $doors['2']);
    }

    public function test_a_name_inside_the_automatic_range_still_works(): void
    {
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/submit", [
            'count' => 3,
            'permits' => [
                ['number' => 'IN-A', 'fileId' => $this->licenceFile($this->partner)],
                ['number' => 'IN-B', 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => '3'],
            ],
        ])->assertOk();

        $this->assertSame(['1' => 'TL-SRC', '2' => 'IN-A', '3' => 'IN-B'], array_map(
            fn ($n) => $n === $source->fresh()->tourism_permit_no ? 'TL-SRC' : $n,
            $this->doorsOf($source),
        ));
    }

    public function test_a_named_door_joins_an_existing_building_under_its_own_name(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);
        $this->expand($source, 2, [['number' => 'P-2']])->assertOk();

        $this->expand($source->fresh(), 4, [['number' => 'FLOOR-7', 'apartmentNo' => '7'], ['number' => 'NEXT']])->assertOk();

        $doors = $this->doorsOf($source);
        $this->assertSame(['1', '2', '3', '7'], array_map('strval', array_keys($doors)));
        $this->assertSame('FLOOR-7', $doors['7']);
        $this->assertSame('NEXT', $doors['3']);
    }

    public function test_a_door_can_be_named_in_words(): void
    {
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/submit", [
            'count' => 2,
            'permits' => [['number' => 'WORDS', 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => 'الدور الثالث']],
        ])->assertOk();

        $this->assertSame('WORDS', $this->doorsOf($source)['الدور الثالث']);
    }

    public function test_a_name_already_in_the_building_is_refused_on_its_card(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);
        $this->expand($source, 2, [['number' => 'P-2']])->assertOk();

        $this->expand($source->fresh(), 4, [['number' => 'OK-A'], ['number' => 'CLASH', 'apartmentNo' => '2']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonPath('error.fields', ['permits.1.apartmentNo' => 'رقم الشقة 2 موجود بالفعل في المبنى']);

        $this->assertSame(2, Unit::count(), 'a refused expansion wrote doors');
    }

    public function test_two_cards_with_one_name_are_refused_on_the_second(): void
    {
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/submit", [
            'count' => 3,
            'permits' => [
                ['number' => 'TWIN-A', 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => '7'],
                ['number' => 'TWIN-B', 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => ' 7 '],
            ],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.fields', ['permits.1.apartmentNo' => 'رقم الشقة 7 مكرر في نفس الطلب']);

        $this->assertSame(1, Unit::count());
        $this->assertSame('draft', $source->fresh()->approval_status);
    }

    public function test_the_admin_console_names_doors_and_refuses_clashes_in_its_envelope(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY, 'mamsa_owned' => true], User::platform());
        $admin = fn (array $permits, int $count) => $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/units/{$source->id}/apartments", ['count' => $count, 'permits' => array_map(
                fn (array $p) => $p + ['fileId' => $this->licenceFile($this->admin)], $permits)]);

        $admin([['number' => 'M-A'], ['number' => 'M-7', 'apartmentNo' => '7']], 3)->assertOk();
        $this->assertSame('M-7', $this->doorsOf($source)['7']);

        $admin([['number' => 'M-CLASH', 'apartmentNo' => '7']], 4)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('fields', ['permits.0.apartmentNo' => 'رقم الشقة 7 موجود بالفعل في المبنى']);
    }

    /* ---------- option (a) and "٧" = "7" (owner decisions 2026-10-01) ---------- */

    public function test_the_original_is_door_one_whatever_the_cards_are_named(): void
    {
        // Before (a), a card named "2" here was fine but a card named "1"
        // pushed the original to "2" — read live on guests' bookings.
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/submit", [
            'count' => 3,
            'permits' => [
                ['number' => 'NAMED-2', 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => '2'],
                ['number' => 'AUTO', 'fileId' => $this->licenceFile($this->partner)],
            ],
        ])->assertOk();

        $this->assertSame('1', $source->fresh()->apartment_no);
        $doors = $this->doorsOf($source);
        $this->assertSame('NAMED-2', $doors['2']);
        $this->assertSame('AUTO', $doors['3']);
    }

    public function test_a_card_named_one_is_refused_as_reserved_for_the_original(): void
    {
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        foreach (['1', '١'] as $name) {
            $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/submit", [
                'count' => 2,
                'permits' => [['number' => 'R-'.$name, 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => $name]],
            ])
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'VALIDATION')
                ->assertJsonPath('error.fields', ['permits.0.apartmentNo' => 'رقم الشقة 1 محجوز للوحدة الأصلية']);
        }

        $this->assertSame(1, Unit::count(), 'a refused expansion wrote doors');
        $this->assertNull($source->fresh()->apartment_no);
    }

    public function test_arabic_digits_are_stored_as_the_same_door(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);
        $this->expand($source, 2, [['number' => 'SEVEN', 'apartmentNo' => '٧']])->assertOk();

        $this->assertSame('SEVEN', $this->doorsOf($source)['7'], '"٧" was not stored as "7"');

        // …so "7" is now taken, and "٧" with "7" in one request is a repeat.
        $this->expand($source->fresh(), 3, [['number' => 'AGAIN', 'apartmentNo' => '7']])
            ->assertStatus(400)
            ->assertJsonPath('error.fields', ['permits.0.apartmentNo' => 'رقم الشقة 7 موجود بالفعل في المبنى']);

        $this->expand($source->fresh(), 4, [['number' => 'A', 'apartmentNo' => '٩'], ['number' => 'B', 'apartmentNo' => '9']])
            ->assertStatus(400)
            ->assertJsonPath('error.fields', ['permits.1.apartmentNo' => 'رقم الشقة 9 مكرر في نفس الطلب']);
    }

    public function test_the_response_lists_doors_in_the_one_agreed_order(): void
    {
        // orderBy('apartment_no') listed 1, 10, 2 — text order.
        $source = $this->unit(['approval_status' => 'draft', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $res = $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/submit", [
            'count' => 3,
            'permits' => [
                ['number' => 'TEN', 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => '10'],
                ['number' => 'TWO', 'fileId' => $this->licenceFile($this->partner), 'apartmentNo' => '2'],
            ],
        ])->assertOk();

        $this->assertSame(['1', '2', '10'], collect($res->json('units'))->pluck('apartmentNo')->all());
    }

    public function test_the_admin_console_reserves_door_one_in_its_envelope(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY, 'mamsa_owned' => true], User::platform());

        $this->actingAs($this->admin, 'admin-panel')->postJson("/admin/units/{$source->id}/apartments", [
            'count' => 2,
            'permits' => [['number' => 'ADM-1', 'fileId' => $this->licenceFile($this->admin), 'apartmentNo' => '1']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('fields', ['permits.0.apartmentNo' => 'رقم الشقة 1 محجوز للوحدة الأصلية']);
    }

    /** @return array<string, ?string> door number => permit number, in door order */
    private function doorsOf(Unit $source): array
    {
        return Unit::where('unit_group_id', $source->fresh()->unit_group_id)->get()
            ->mapWithKeys(fn (Unit $u) => [(string) $u->apartment_no => Permit::currentFor($u)?->number])
            ->sortKeys(SORT_NATURAL)->all();
    }

    /* ---------- fixtures ---------- */

    /**
     * Every per-unit permit needs its own licence FILE as well as its number —
     * nothing is copied in that mode, so a door without one cannot be
     * published. The fixture mints one per entry unless the test names its own.
     *
     * @param  array<int, array<string, mixed>>  $permits
     */
    private function expand(Unit $unit, int $count, array $permits = [], ?User $owner = null): TestResponse
    {
        $body = ['count' => $count];

        if ($permits !== []) {
            $body['permits'] = array_map(
                fn (array $p) => $p + ['fileId' => $this->licenceFile($owner ?? $this->partner)],
                $permits,
            );
        }

        return $this->actingAs($owner ?? $this->partner, 'dashboard')->postJson("/units/u_{$unit->id}/apartments", $body);
    }

    /** A stored licence upload owned by this user, as presign + PUT would leave it. */
    private function licenceFile(User $owner): string
    {
        $id = 'file_'.strtolower((string) str()->ulid());

        DashboardUpload::create([
            'id' => $id, 'user_id' => $owner->id, 'kind' => 'license_pdf',
            'original_name' => 'permit.pdf', 'mime' => 'application/pdf', 'size' => 1024,
            'status' => 'stored', 'path' => "dashboard/license_pdf/{$id}.pdf",
        ]);

        return $id;
    }

    /** @param array<string, mixed> $extra */
    private function unit(array $extra = [], ?User $owner = null): Unit
    {
        $unit = ($owner ?? $this->partner)->units()->create(array_merge([
            'unit_name' => 'شقة', 'unit_type' => 'apartment',
            'code' => 'AM'.fake()->unique()->numerify('######'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 2, 'bathrooms' => 1,
            'city' => 'الرياض', 'district' => 'الملقا', 'address' => 'حي الملقا',
            'lat' => 24.7136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ للوحدة. ', 5),
            'tourism_permit_no' => 'TL-'.fake()->unique()->numerify('######'),
            'tourism_permit_file' => 'dashboard/license_pdf/file_test.pdf',
            'approval_status' => 'approved', 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ], $extra));

        $unit->images()->create(['path' => 'units/'.$unit->id.'/photo.jpg', 'is_main' => true, 'sort_order' => 1]);

        return $unit->fresh();
    }
}
