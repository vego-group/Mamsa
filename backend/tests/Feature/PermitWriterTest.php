<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permits\PermitBackfill;
use App\Support\Permits\PermitNumber;
use App\Support\Permits\PermitWriter;
use App\Support\Units\LicenseViolation;
use App\Support\Units\UnitCloner;
use App\Support\Units\UnitLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 1 of the permits change-set: a permit is a row of its own, with one
 * writer, and the four permit columns on `units` are read copies of it.
 *
 * What this protects against is the state staging was in on 22/09: one
 * building, eight approved doors, one under `TL-50000`, seven under
 * `TL-DEMO-8UNITS`, three with no permit file at all — written by paths that
 * each thought they were the only one.
 */
class PermitWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        config()->set('units.multi_unit_enabled', true);

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
    }

    /* ---------- normalisation ---------- */

    public function test_a_permit_number_has_one_spelling(): void
    {
        $this->assertSame('50047139', PermitNumber::normalize('50047139'));
        $this->assertSame('50047139', PermitNumber::normalize('٥٠٠٤٧١٣٩'), 'Arabic-Indic digits');
        $this->assertSame('50047139', PermitNumber::normalize('۵۰۰۴۷۱۳۹'), 'Extended Arabic-Indic digits');
        $this->assertSame('50047139', PermitNumber::normalize(' 5004 7139 '), 'spaces inside and around');
        $this->assertSame('50047139', PermitNumber::normalize("5\u{00A0}0047,139"), 'NBSP and a comma');
        $this->assertSame('50047139', PermitNumber::normalize('50047،139'), 'Arabic comma');
        $this->assertSame('TL-DEMO-8UNITS', PermitNumber::normalize('tl-demo-8units'), 'letters upper-cased, hyphens kept');
        $this->assertNull(PermitNumber::normalize('   '));
        $this->assertNull(PermitNumber::normalize(null));
    }

    public function test_the_writer_stores_the_normalised_number_and_mirrors_it(): void
    {
        $unit = $this->unit();

        PermitWriter::apply($unit, ['number' => ' ٥٠٠٤٧١٣٩ ']);

        $this->assertSame('50047139', Permit::currentFor($unit)->number);
        $this->assertSame('50047139', $unit->fresh()->tourism_permit_no, 'the mirror carries the normalised form');
    }

    /* ---------- one writer ---------- */

    public function test_a_direct_column_write_is_refused(): void
    {
        // The staging state: four columns, many writers. Any `update()` that
        // touches them outside the writer fails loudly.
        $unit = $this->unit();

        foreach (['tourism_permit_no' => 'X', 'tourism_permit_file' => 'f', 'license_type' => 'private_hospitality', 'licensed_units_count' => 1] as $column => $value) {
            try {
                $unit->fresh()->update([$column => $value]);
                $this->fail("$column was written outside PermitWriter");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('PermitWriter', $e->getMessage());
            }
        }
    }

    public function test_the_partner_dashboard_writes_the_permit_through_the_writer(): void
    {
        // Created without a permit, so the PATCH is what creates it — and the
        // row records who did.
        $unit = $this->unit(['approval_status' => 'draft', 'tourism_permit_no' => null, 'tourism_permit_file' => null, 'license_type' => null]);
        $this->assertNull(Permit::currentFor($unit));

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", ['tourismLicenseNumber' => '٤٠٠١'])
            ->assertOk();

        $permit = Permit::currentFor($unit->fresh());
        $this->assertNotNull($permit);
        $this->assertSame('4001', $permit->number);
        $this->assertSame('4001', $unit->fresh()->tourism_permit_no);
        $this->assertSame(Permit::SCOPE_UNIT, $permit->scope_type);
        $this->assertSame($this->partner->id, $permit->created_by);
    }

    public function test_the_admin_console_writes_the_permit_through_the_writer(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('SuperAdmin');
        $unit = $this->unit(['approval_status' => 'draft', 'mamsa_owned' => true], User::platform());

        $this->actingAs($admin, 'admin-panel')
            ->patchJson("/admin/units/{$unit->id}", ['tourismLicenseNumber' => 'TL 900', 'licenseType' => 'private_hospitality'])
            ->assertOk();

        $permit = Permit::currentFor($unit->fresh());
        $this->assertSame('TL900', $permit->number);
        $this->assertSame('private_hospitality', $permit->license_type);
        $this->assertSame('private_hospitality', $unit->fresh()->license_type);
    }

    public function test_the_v1_partner_surface_writes_the_permit_through_the_writer(): void
    {
        // Retired by default (RetiredEndpointsTest); enabled here because the
        // revert path must still write through the one writer if it is ever
        // turned back on.
        config()->set('units.legacy_unit_writes', true);

        $unit = $this->unit(['approval_status' => 'draft']);

        $this->actingAs($this->partner)
            ->putJson("/api/v1/partner/units/{$unit->id}", ['tourism_permit_no' => ' 7 7 7 '])
            ->assertOk();

        $this->assertSame('777', Permit::currentFor($unit->fresh())->number);
        $this->assertSame('777', $unit->fresh()->tourism_permit_no);
    }

    /* ---------- scope ---------- */

    public function test_a_building_holds_one_permit_and_every_door_mirrors_it(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8]);
        UnitCloner::ensureTotal($source, 4);

        $this->assertSame(1, Permit::count(), 'four doors, one permit');
        $permit = Permit::currentFor($source->fresh());
        $this->assertSame(Permit::SCOPE_GROUP, $permit->scope_type);

        // One write, four mirrors.
        PermitWriter::apply($source->fresh(), ['number' => 'NEW-1', 'file' => 'file_new']);

        $rows = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->get();
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame('NEW-1', $row->tourism_permit_no);
            $this->assertSame('file_new', $row->tourism_permit_file);
        }
        $this->assertSame(1, Permit::count());
    }

    public function test_a_door_created_into_a_building_with_a_different_permit_is_refused(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8]);
        UnitCloner::ensureTotal($source, 2);
        $groupId = $source->fresh()->unit_group_id;

        $this->expectException(\LogicException::class);

        $this->partner->units()->create($this->attributes([
            'unit_group_id' => $groupId, 'apartment_no' => '9',
            'tourism_permit_no' => 'SOMETHING-ELSE',
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8,
        ]));
    }

    public function test_a_door_created_into_a_building_with_no_permit_of_its_own_mirrors_the_buildings(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8]);
        UnitCloner::ensureTotal($source, 2);
        $groupId = $source->fresh()->unit_group_id;

        $door = $this->partner->units()->create($this->attributes([
            'unit_group_id' => $groupId, 'apartment_no' => '9',
            'tourism_permit_no' => null, 'tourism_permit_file' => null,
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8,
        ]));

        $this->assertSame('TL-0001', $door->fresh()->tourism_permit_no);
        $this->assertSame(1, Permit::count());
    }

    public function test_a_new_unit_created_with_permit_columns_gets_its_permit_row(): void
    {
        // Creation seeds — the wizard and every fixture create this way.
        $unit = $this->unit(['tourism_permit_no' => '٠٠٩']);

        $permit = Permit::currentFor($unit);
        $this->assertNotNull($permit);
        $this->assertSame('009', $permit->number);
        $this->assertSame('009', $unit->fresh()->tourism_permit_no, 'normalised on adoption too');
        $this->assertSame(Permit::SCOPE_UNIT, $permit->scope_type);
    }

    public function test_a_unit_with_no_permit_has_no_permit_row(): void
    {
        $unit = $this->unit(['tourism_permit_no' => null, 'tourism_permit_file' => null, 'license_type' => null]);

        $this->assertNull(Permit::currentFor($unit));
        $this->assertSame(0, Permit::count());
    }

    public function test_clearing_every_field_removes_the_permit_row(): void
    {
        $unit = $this->unit();
        $this->assertSame(1, Permit::count());

        PermitWriter::apply($unit, ['number' => null, 'file' => null, 'license_type' => null, 'licensed_units_count' => null]);

        $this->assertSame(0, Permit::count());
        $this->assertNull($unit->fresh()->tourism_permit_no);
        $this->assertNull($unit->fresh()->tourism_permit_file);
    }

    /* ---------- the licence rules still hold ---------- */

    public function test_the_licence_rules_run_before_anything_is_written(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8]);
        UnitCloner::ensureTotal($source, 3);
        $before = Permit::currentFor($source->fresh())->toArray();

        try {
            PermitWriter::apply($source->fresh(), ['license_type' => 'private_hospitality', 'licensed_units_count' => null, 'number' => 'CHANGED']);
            $this->fail('a downgrade under three doors was accepted');
        } catch (LicenseViolation $e) {
            $this->assertSame('LICENSE_DOWNGRADE_BLOCKED_BY_QUANTITY', $e->reason);
        }

        // Nothing moved — not the row, not the mirrors.
        $this->assertSame($before, Permit::currentFor($source->fresh())->toArray());
        $this->assertSame('TL-0001', $source->fresh()->tourism_permit_no);
    }

    public function test_apply_to_group_still_works_for_its_callers(): void
    {
        $unit = $this->unit();

        UnitLicense::applyToGroup($unit, ['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 5]);

        $this->assertSame(UnitLicense::TOURIST_FACILITY, $unit->fresh()->license_type);
        $this->assertSame(5, (int) $unit->fresh()->licensed_units_count);
        $this->assertSame(5, Permit::currentFor($unit)->licensed_units_count);
    }

    public function test_creating_a_listing_with_a_bad_licence_pair_is_a_named_refusal(): void
    {
        // Not a CHECK violation from the insert. `tourist_facility` with no
        // count satisfies no rule, and the DB says so with a 500 if the row is
        // written first — so the permit is written after the row, by its
        // writer, and the partner gets the code that names what is missing.
        $this->actingAs($this->partner, 'dashboard')
            ->postJson('/units', [
                'name' => 'شقة', 'type' => 'apartment', 'city' => 'riyadh',
                'licenseType' => UnitLicense::TOURIST_FACILITY,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LICENSED_UNITS_COUNT_REQUIRED');

        // And the half-made draft is not left behind.
        $this->assertSame(0, Unit::where('user_id', $this->partner->id)->where('unit_name', 'شقة')->count());
        $this->assertSame(0, Permit::count());
    }

    public function test_creating_a_listing_with_a_good_licence_pair_writes_the_permit(): void
    {
        $id = $this->actingAs($this->partner, 'dashboard')
            ->postJson('/units', [
                'name' => 'مبنى', 'type' => 'apartment', 'city' => 'riyadh',
                'licenseType' => UnitLicense::TOURIST_FACILITY, 'licensedUnitsCount' => 6,
                'tourismLicenseNumber' => '٥٥٥',
            ])
            ->assertStatus(201)
            ->json('id');

        $unit = Unit::findOrFail((int) str_replace('u_', '', (string) $id));
        $permit = Permit::currentFor($unit);

        $this->assertNotNull($permit);
        $this->assertSame(UnitLicense::TOURIST_FACILITY, $permit->license_type);
        $this->assertSame(6, $permit->licensed_units_count);
        $this->assertSame('555', $permit->number);
        $this->assertSame('555', $unit->tourism_permit_no);
    }

    /* ---------- the daily check ---------- */

    public function test_the_daily_check_sees_a_mirror_that_drifted(): void
    {
        $unit = $this->unit();
        $this->assertSame([], UnitLicense::mirrorMismatches());

        // The one write the model guard cannot see.
        DB::table('units')->where('id', $unit->id)->update(['tourism_permit_no' => 'DRIFTED']);

        $found = UnitLicense::mirrorMismatches();
        $this->assertCount(1, $found);
        $this->assertSame('tourism_permit_no', $found[0]->column);
        $this->assertSame('DRIFTED', $found[0]->unit_value);
        $this->assertSame('TL-0001', $found[0]->permit_value);
    }

    /* ---------- the backfill ---------- */

    public function test_the_backfill_reads_existing_columns_into_rows(): void
    {
        // Rows as the servers hold them today: written straight to the table,
        // no permits rows. Built with the guard lowered, the way SQL would.
        Permit::query()->delete();
        $ids = PermitWriter::write(function () {
            $g = (string) str()->ulid();
            $agreeA = $this->unit(['unit_group_id' => $g, 'apartment_no' => '1', 'tourism_permit_no' => 'TL-G', 'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 4]);
            $agreeB = $this->unit(['unit_group_id' => $g, 'apartment_no' => '2', 'tourism_permit_no' => 'tl-g', 'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 4]);

            $h = (str()->ulid())->toBase32();
            $splitA = $this->unit(['unit_group_id' => $h, 'apartment_no' => '1', 'tourism_permit_no' => 'TL-DEMO', 'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8]);
            $splitB = $this->unit(['unit_group_id' => $h, 'apartment_no' => '2', 'tourism_permit_no' => 'TL-50000', 'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8]);
            $splitC = $this->unit(['unit_group_id' => $h, 'apartment_no' => '3', 'tourism_permit_no' => null, 'tourism_permit_file' => null, 'license_type' => null, 'licensed_units_count' => null]);

            $alone = $this->unit(['tourism_permit_no' => '٥٠٠٤٥٣٢٤']);
            $bare = $this->unit(['tourism_permit_no' => null, 'tourism_permit_file' => null, 'license_type' => null]);

            return compact('g', 'h', 'agreeA', 'agreeB', 'splitA', 'splitB', 'splitC', 'alone', 'bare');
        });
        Permit::query()->delete(); // adoption ran on create; the servers have no rows

        $report = PermitBackfill::run();

        // The agreeing group: one row, both doors normalised to the same spelling.
        $this->assertSame(1, $report['groups']);
        $groupPermit = Permit::where('scope_type', 'group')->where('scope_id', $ids['g'])->current()->first();
        $this->assertNotNull($groupPermit);
        $this->assertSame('TL-G', $groupPermit->number);
        $this->assertSame('TL-G', $ids['agreeB']->fresh()->tourism_permit_no, 'tl-g became TL-G');

        // The disagreeing group: one row per carrying door, and it is reported.
        $this->assertSame([$ids['h']], $report['split_groups']);
        $this->assertSame(2, Permit::where('scope_type', 'unit')->whereIn('scope_id', [(string) $ids['splitA']->id, (string) $ids['splitB']->id])->count());
        $this->assertSame(0, Permit::where('scope_type', 'group')->where('scope_id', $ids['h'])->count());
        $this->assertSame(0, Permit::where('scope_id', (string) $ids['splitC']->id)->count(), 'a door with nothing gets nothing');

        // Standalone: normalised; bare: nothing.
        $this->assertSame('50045324', Permit::currentFor($ids['alone']->fresh())->number);
        $this->assertNull(Permit::currentFor($ids['bare']->fresh()));

        // Idempotent.
        $again = PermitBackfill::run();
        $this->assertSame(0, $again['rows']);
        $this->assertSame(Permit::count(), 1 + 2 + 1);
    }

    public function test_the_backfill_dry_run_writes_nothing(): void
    {
        $unit = $this->unit();
        Permit::query()->delete();

        $report = PermitBackfill::run(dryRun: true);

        $this->assertSame(1, $report['rows']);
        $this->assertSame(0, Permit::count());
    }

    /* ---------- fixtures ---------- */

    /** @param array<string, mixed> $extra */
    private function attributes(array $extra = []): array
    {
        return array_merge([
            'unit_name' => 'شقة', 'unit_type' => 'apartment',
            'code' => 'PW'.fake()->unique()->numerify('######'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 1, 'bathrooms' => 1,
            'city' => 'الرياض', 'district' => 'الملقا', 'address' => 'حي الملقا',
            'lat' => 24.7136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ للوحدة. ', 5),
            'tourism_permit_no' => 'TL-0001',
            'tourism_permit_file' => 'dashboard/license_pdf/file_test.pdf',
            'approval_status' => 'approved', 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ], $extra);
    }

    /** @param array<string, mixed> $extra */
    private function unit(array $extra = [], ?User $owner = null): Unit
    {
        $unit = ($owner ?? $this->partner)->units()->create($this->attributes($extra));
        $unit->images()->create(['path' => 'units/'.$unit->id.'/photo.jpg', 'is_main' => true, 'sort_order' => 1]);

        return $unit->fresh();
    }
}
