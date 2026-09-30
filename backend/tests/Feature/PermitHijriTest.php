<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DashboardUpload;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permits\PermitRenewal;
use App\Support\Permits\PermitWriter;
use App\Support\Units\UnitLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The permit's expiry as typed in Hijri, kept beside the Gregorian date.
 *
 * Permits are printed in Hijri and the consoles convert. Umm al-Qura tables
 * disagree by a day after 2029-08-10 and the ministry's table is unknown, so
 * the source is kept: if the conversion turns out wrong, the dates can be
 * re-derived from what the partner typed instead of from the paper.
 *
 * The failure these tests exist to stop is the quiet one — an OLD Hijri text
 * surviving next to a NEW Gregorian date, which would make the one column
 * meant to repair bad dates into a source of them.
 */
class PermitHijriTest extends TestCase
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

    /* ---------- stored verbatim ---------- */

    public function test_the_hijri_text_is_stored_exactly_as_typed(): void
    {
        // Arabic digits, the era marker, and the spaces around it: every byte
        // survives, including the whitespace the framework would otherwise trim.
        $typed = ' ٢٢/٠٤/١٤٤٩ هـ ';
        $unit = $this->unit(['approval_status' => 'draft']);

        $this->edit($unit, ['permitExpiresAt' => '2027-09-23', 'permitExpiresAtHijri' => $typed])
            ->assertOk()
            ->assertJsonPath('permitExpiresAt', '2027-09-23')
            ->assertJsonPath('permitExpiresAtHijri', $typed);

        $this->assertSame($typed, $this->hijri($unit));
    }

    public function test_a_date_typed_in_gregorian_has_no_hijri_source(): void
    {
        $unit = $this->unit(['approval_status' => 'draft']);

        $this->edit($unit, ['permitExpiresAt' => '2027-09-23'])
            ->assertOk()
            ->assertJsonPath('permitExpiresAtHijri', null);

        $this->assertNull($this->hijri($unit));
    }

    public function test_hijri_without_the_gregorian_date_is_refused(): void
    {
        // The backend does not convert: a Hijri date alone would be a source
        // for nothing, and the next reader could not tell which date it meant.
        $unit = $this->unit(['approval_status' => 'draft']);

        $this->edit($unit, ['permitExpiresAtHijri' => '٢٢/٠٤/١٤٤٩'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonStructure(['error' => ['fields' => ['permitExpiresAtHijri']]]);

        $this->edit($unit, ['permitExpiresAt' => null, 'permitExpiresAtHijri' => '٢٢/٠٤/١٤٤٩'])
            ->assertStatus(400)
            ->assertJsonStructure(['error' => ['fields' => ['permitExpiresAtHijri']]]);
    }

    /* ---------- never stale ---------- */

    public function test_a_new_date_without_hijri_clears_the_old_text(): void
    {
        // THE case: the partner corrects the date by typing Gregorian. Keeping
        // the old Hijri would pair it with a date it was never converted to.
        $unit = $this->unit(['approval_status' => 'draft']);
        $this->edit($unit, ['permitExpiresAt' => '2027-09-23', 'permitExpiresAtHijri' => '٢٢/٠٤/١٤٤٩'])->assertOk();

        $this->edit($unit, ['permitExpiresAt' => '2027-10-01'])
            ->assertOk()
            ->assertJsonPath('permitExpiresAtHijri', null);

        $this->assertNull($this->hijri($unit), 'an old Hijri text survived next to a new date');
    }

    public function test_resending_the_same_date_keeps_the_text(): void
    {
        // A wizard re-saves every field on every step. Re-sending an unchanged
        // date must not wipe a source it did not replace.
        $unit = $this->unit(['approval_status' => 'draft']);
        $this->edit($unit, ['permitExpiresAt' => '2027-09-23', 'permitExpiresAtHijri' => '٢٢/٠٤/١٤٤٩'])->assertOk();

        $this->edit($unit, ['permitExpiresAt' => '2027-09-23', 'permitAddressCity' => 'الرياض'])->assertOk();

        $this->assertSame('٢٢/٠٤/١٤٤٩', $this->hijri($unit));
    }

    public function test_editing_another_permit_field_keeps_the_text(): void
    {
        $unit = $this->unit(['approval_status' => 'draft']);
        $this->edit($unit, ['permitExpiresAt' => '2027-09-23', 'permitExpiresAtHijri' => '٢٢/٠٤/١٤٤٩'])->assertOk();

        $this->edit($unit, ['permitAddressDistrict' => 'النرجس'])->assertOk();

        $this->assertSame('٢٢/٠٤/١٤٤٩', $this->hijri($unit));
    }

    public function test_an_explicit_null_clears_the_text_even_on_the_same_date(): void
    {
        // Null is an answer — "I typed this one in Gregorian" — not an absence.
        $unit = $this->unit(['approval_status' => 'draft']);
        $this->edit($unit, ['permitExpiresAt' => '2027-09-23', 'permitExpiresAtHijri' => '٢٢/٠٤/١٤٤٩'])->assertOk();

        $this->edit($unit, ['permitExpiresAt' => '2027-09-23', 'permitExpiresAtHijri' => null])->assertOk();

        $this->assertNull($this->hijri($unit));
    }

    public function test_the_admin_console_refuses_it_too_in_its_own_envelope(): void
    {
        $unit = $this->unit(['approval_status' => 'draft']);

        $this->actingAs($this->admin, 'admin-panel')
            ->patchJson("/admin/units/{$unit->id}", ['permitExpiresAtHijri' => '٢٢/٠٤/١٤٤٩'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['fields' => ['permitExpiresAtHijri']]);

        $this->actingAs($this->admin, 'admin-panel')
            ->patchJson("/admin/units/{$unit->id}", ['permitExpiresAt' => '2027-09-23', 'permitExpiresAtHijri' => ' ٢٢/٠٤/١٤٤٩ '])
            ->assertOk();

        $this->assertSame(' ٢٢/٠٤/١٤٤٩ ', $this->hijri($unit));
    }

    /* ---------- renewal ---------- */

    public function test_a_renewal_does_not_inherit_the_old_text_onto_a_new_date(): void
    {
        // A renewal inherits every field it does not name. The Hijri text is
        // the one field where inheriting is wrong: the old permit's text would
        // be sitting next to the NEW date.
        $unit = $this->unit();
        PermitWriter::apply($unit, ['expires_at' => now()->addMonth()->toDateString(), 'expires_at_hijri' => 'OLD']);

        $this->renew($unit, ['permitExpiresAt' => now()->addYear()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('permitExpiresAtHijri', null);

        $this->assertNull(PermitRenewal::pendingFor($unit)->expires_at_hijri);
    }

    public function test_a_renewal_carries_its_own_text_through_approval(): void
    {
        $unit = $this->unit();
        PermitWriter::apply($unit, ['expires_at' => now()->addMonth()->toDateString(), 'expires_at_hijri' => 'OLD']);

        $this->renew($unit, ['permitExpiresAt' => now()->addYear()->toDateString(), 'permitExpiresAtHijri' => ' ١٥/٠٣/١٤٤٩ هـ '])
            ->assertCreated()
            ->assertJsonPath('permitExpiresAtHijri', ' ١٥/٠٣/١٤٤٩ هـ ');

        $renewal = PermitRenewal::pendingFor($unit);
        $this->actingAs($this->admin, 'admin-panel')->postJson("/admin/permit-renewals/{$renewal->id}/approve")->assertOk();

        $this->assertSame(' ١٥/٠٣/١٤٤٩ هـ ', $this->hijri($unit));
        $this->actingAs($this->partner, 'dashboard')->getJson("/units/u_{$unit->id}")
            ->assertOk()->assertJsonPath('permitExpiresAtHijri', ' ١٥/٠٣/١٤٤٩ هـ ');
    }

    /* ---------- apartments ---------- */

    public function test_each_new_apartment_keeps_its_own_text(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY, 'tourism_permit_no' => 'SRC-1']);

        $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/apartments", [
            'count' => 3,
            'permits' => [
                ['number' => 'DOOR-2', 'fileId' => $this->licenceFile(), 'expiresAt' => '2027-09-23', 'expiresAtHijri' => ' ٢٢/٠٤/١٤٤٩ '],
                ['number' => 'DOOR-3', 'fileId' => $this->licenceFile(), 'expiresAt' => '2027-06-01'],
            ],
        ])->assertOk();

        $doors = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->orderBy('id')->get()->keyBy('tourism_permit_no');

        $this->assertSame(' ٢٢/٠٤/١٤٤٩ ', Permit::currentFor($doors['DOOR-2'])->expires_at_hijri, 'nested key was trimmed or dropped');
        $this->assertNull(Permit::currentFor($doors['DOOR-3'])->expires_at_hijri, 'a neighbour\'s text leaked across');
    }

    public function test_an_apartment_hijri_without_its_date_is_refused(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);

        $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$source->id}/apartments", [
            'count' => 2,
            'permits' => [['number' => 'DOOR-2', 'fileId' => $this->licenceFile(), 'expiresAtHijri' => '٢٢/٠٤/١٤٤٩']],
        ])->assertStatus(400)->assertJsonStructure(['error' => ['fields' => ['permits.0.expiresAtHijri']]]);

        $this->assertSame(1, Unit::count(), 'a refused expansion left apartments behind');
    }

    /* ---------- reading ---------- */

    public function test_both_keys_are_always_present_even_with_no_permit(): void
    {
        // Answers the frontend's question for the phase 5 renewal screen:
        // permitAddress is ALWAYS there, as four keys, each null when nothing
        // is recorded — never omitted, never a bare null. Same for the Hijri.
        $unit = $this->unit(['approval_status' => 'draft', 'tourism_permit_no' => null, 'tourism_permit_file' => null]);
        $this->assertNull(Permit::currentFor($unit), 'fixture should have no permit at all');

        $body = $this->actingAs($this->partner, 'dashboard')->getJson("/units/u_{$unit->id}")->assertOk()->json();

        $this->assertArrayHasKey('permitExpiresAtHijri', $body);
        $this->assertNull($body['permitExpiresAtHijri']);
        $this->assertSame(['city' => null, 'district' => null, 'building' => null, 'unitNo' => null], $body['permitAddress']);

        $list = $this->actingAs($this->partner, 'dashboard')->getJson('/units')->assertOk()->json();
        $row = collect($list['data'])->firstWhere('id', 'u_'.$unit->id);
        $this->assertNotNull($row, 'the unit is missing from the list');
        $this->assertSame(['city' => null, 'district' => null, 'building' => null, 'unitNo' => null], $row['permitAddress']);
    }

    public function test_the_admin_console_shows_the_text_to_the_reviewer(): void
    {
        $unit = $this->unit();
        PermitWriter::apply($unit, ['expires_at' => '2027-09-23', 'expires_at_hijri' => '٢٢/٠٤/١٤٤٩']);

        $this->actingAs($this->admin, 'admin-panel')->getJson("/admin/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('permitExpiresAt', '2027-09-23')
            ->assertJsonPath('permitExpiresAtHijri', '٢٢/٠٤/١٤٤٩');
    }

    /* ---------- helpers ---------- */

    private function edit(Unit $unit, array $body)
    {
        return $this->actingAs($this->partner, 'dashboard')->patchJson("/units/u_{$unit->id}", $body);
    }

    private function renew(Unit $unit, array $body)
    {
        return $this->actingAs($this->partner, 'dashboard')->postJson("/units/u_{$unit->id}/permit-renewals", $body);
    }

    private function hijri(Unit $unit): ?string
    {
        return Permit::currentFor($unit->fresh())?->expires_at_hijri;
    }

    private function licenceFile(): string
    {
        $id = 'file_'.strtolower((string) str()->ulid());

        DashboardUpload::create([
            'id' => $id, 'user_id' => $this->partner->id, 'kind' => 'license_pdf',
            'original_name' => 'permit.pdf', 'mime' => 'application/pdf', 'size' => 1024,
            'status' => 'stored', 'path' => "dashboard/license_pdf/{$id}.pdf",
        ]);

        return $id;
    }

    private function unit(array $extra = []): Unit
    {
        $unit = $this->partner->units()->create(array_merge([
            'unit_name' => 'شقة', 'unit_type' => 'apartment',
            'code' => 'HJ'.fake()->unique()->numerify('######'),
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
