<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\Unit;
use App\Models\User;
use App\Support\Units\UnitLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Expanding a Mamsa-owned listing into a building, from the admin console.
 *
 * The platform's own duplicated studios were being added one at a time through
 * the wizard — production #34 and #35 are the same studio typed twice — which
 * is exactly the work the group model exists to remove, on the one surface
 * that had no entry point for it.
 *
 * The apartments are filed for REVIEW. The first draft created them approved —
 * "an admin filing units for an admin to approve is theatre" — which held only
 * while Mamsa was assumed to hold the permit. The first real permit to reach the
 * team (19/09) was a third party's, with an expiry, an address and a capacity:
 * things a reviewer checks and code does not. The protections that ARE code
 * (approved source, completeness gate, permit coverage) run before any write.
 */
class ApartmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin', 'Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');
    }

    /* ---------- who and what ---------- */

    public function test_a_partner_listing_cannot_be_expanded_from_the_console(): void
    {
        $partner = User::factory()->create();
        $partner->assignRole('Individual');
        $unit = $this->unit([
            'mamsa_owned' => false,
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 8,
        ], $partner);

        $this->expand($unit, 3)
            ->assertStatus(403)
            ->assertJsonPath('code', 'NOT_MAMSA_OWNED');

        $this->assertSame(1, Unit::count(), 'A partner building was expanded by an admin.');
    }

    public function test_the_source_must_already_be_approved(): void
    {
        // The copies inherit the source's standing; a draft has none to give.
        $unit = $this->licensed(8, ['approval_status' => 'draft']);

        $this->expand($unit, 3)
            ->assertStatus(409)
            ->assertJsonPath('code', 'SOURCE_NOT_APPROVED');

        $this->assertSame(1, Unit::count());
    }

    public function test_a_finance_admin_cannot_expand(): void
    {
        Role::findOrCreate('finance', 'web');
        $finance = User::factory()->create(['is_active' => true]);
        $finance->assignRole('finance');
        $unit = $this->licensed(8);

        $this->actingAs($finance, 'admin-panel')
            ->postJson("/admin/units/{$unit->id}/apartments", ['count' => 3])
            ->assertStatus(403);
    }

    public function test_the_count_is_validated_in_the_admin_envelope(): void
    {
        $unit = $this->licensed(8);

        $this->expand($unit, 0)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['fields' => ['count']]);

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/units/{$unit->id}/apartments", [])
            ->assertStatus(422)
            ->assertJsonPath('fields.count', 'عدد الوحدات مطلوب');
    }

    /* ---------- the permit ---------- */

    public function test_an_unclassified_listing_cannot_become_a_building(): void
    {
        // Real-data license_type is NULL everywhere; the admin classifies first.
        $unit = $this->unit();

        $this->expand($unit, 3)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MULTI_UNIT_REQUIRES_FACILITY_LICENSE')
            ->assertJsonPath('meta.max_units', 1);

        $this->assertSame(1, Unit::count(), 'Apartments were written before the permit was checked.');
    }

    public function test_a_count_over_the_permit_is_refused_with_the_numbers(): void
    {
        $unit = $this->licensed(4);

        $this->expand($unit, 6)
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUANTITY_EXCEEDS_LICENSED_UNITS')
            ->assertJsonPath('meta.requested', 6)
            ->assertJsonPath('meta.licensed_units_count', 4);

        $this->assertSame(1, Unit::count());
    }

    public function test_the_partner_rollout_flag_does_not_gate_the_platform(): void
    {
        // MULTI_UNIT_ENABLED=false on production until the partner UI ships.
        // The platform expanding its own inventory is not that rollout.
        config()->set('units.multi_unit_enabled', false);
        $unit = $this->licensed(8);

        $this->expand($unit, 3)->assertOk()->assertJsonPath('groupSize', 3);
    }

    public function test_an_incomplete_source_is_refused_naming_the_fields(): void
    {
        // Approved does not mean complete — #39 on production is approved with
        // no permit number, no file and submitted_at NULL. Copies of it would
        // go straight to the storefront in the same state.
        $unit = $this->licensed(8, ['tourism_permit_no' => null, 'description' => null]);

        $this->expand($unit, 3)
            ->assertStatus(422)
            ->assertJsonPath('code', 'SOURCE_UNIT_INCOMPLETE')
            ->assertJsonPath('meta.unitId', (string) $unit->id)
            ->assertJsonStructure(['fields' => ['tourismLicenseNumber', 'description']]);

        $this->assertSame(1, Unit::count());
    }

    /* ---------- the expansion ---------- */

    public function test_the_apartments_are_filed_for_review_and_owned_by_the_platform(): void
    {
        $unit = $this->licensed(8);

        $r = $this->expand($unit, 4)
            ->assertOk()
            ->assertJsonPath('groupSize', 4)
            ->assertJsonPath('added', 3)
            ->assertJsonCount(4, 'units')
            ->assertJsonPath('units.0.status', 'approved')   // the source
            ->assertJsonPath('units.1.status', 'pending')
            ->assertJsonPath('units.3.status', 'pending');

        $this->assertNotNull($r->json('groupId'));

        $copies = Unit::where('unit_group_id', $r->json('groupId'))->where('id', '!=', $unit->id)->get();
        $this->assertCount(3, $copies);

        foreach ($copies as $member) {
            $this->assertSame('pending', $member->approval_status);
            $this->assertNotNull($member->submitted_at, 'A filed apartment with submitted_at NULL — the SLA clock never started.');
            $this->assertTrue((bool) $member->mamsa_owned);
            $this->assertSame(User::platform()->id, $member->user_id);
            // The permit travels: a facility permit covers every door in it.
            $this->assertSame($unit->tourism_permit_no, $member->tourism_permit_no);
            $this->assertSame($unit->tourism_permit_file, $member->tourism_permit_file);
            $this->assertSame(UnitLicense::TOURIST_FACILITY, $member->license_type);
            $this->assertSame(8, (int) $member->licensed_units_count);
        }

        // The source is untouched — it keeps selling while the copies wait.
        $this->assertSame('approved', $unit->fresh()->approval_status);

        // And the reviewer knows: one feed entry per apartment, from the same
        // observer every other filing path goes through.
        $this->assertSame(3, $this->admin->fresh()->notifications()->count());
    }

    public function test_the_copies_stay_off_the_storefront_until_approved(): void
    {
        $unit = $this->licensed(8);
        $this->expand($unit, 5)->assertOk();

        // Before review: the source alone, as a single sellable door.
        $this->getJson('/api/v1/units')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available_count', 1);

        foreach (Unit::where('approval_status', 'pending')->get() as $copy) {
            $this->actingAs($this->admin, 'admin-panel')
                ->postJson("/admin/approvals/{$copy->id}/approve")->assertOk();
        }

        // After review: still ONE card — the guest sees a building with five
        // doors, not five identical studios (the exact thing #34/#35 are today).
        $this->getJson('/api/v1/units')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available_count', 5);
    }

    public function test_count_is_a_total_not_an_addition(): void
    {
        $unit = $this->licensed(8);

        $this->expand($unit, 3)->assertOk()->assertJsonPath('added', 2);
        // Resending the same number adds nothing — a console retry is safe.
        $this->expand($unit, 3)->assertOk()->assertJsonPath('added', 0)->assertJsonPath('groupSize', 3);
        // Growing goes from the current size, not from one.
        $this->expand($unit, 5)->assertOk()->assertJsonPath('added', 2)->assertJsonPath('groupSize', 5);
        // Shrinking is not performed: an apartment may hold a booking.
        $this->expand($unit, 2)->assertOk()->assertJsonPath('added', 0)->assertJsonPath('groupSize', 5);

        $this->assertSame(5, Unit::count());
    }

    public function test_a_reviewer_can_approve_a_filed_apartment_while_partner_expansion_is_off(): void
    {
        // With the rollout flag inside the approval check, every apartment this
        // route files would be un-approvable on production (flag off until the
        // partner UI ships) — and so would any apartment of a live building
        // sent back to review by an edit. Approval checks the permit, not the
        // rollout.
        $unit = $this->licensed(8);
        $this->expand($unit, 3)->assertOk();

        config()->set('units.multi_unit_enabled', false);

        $member = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->where('approval_status', 'pending')->firstOrFail();

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/approvals/{$member->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $member->fresh()->approval_status);
    }

    public function test_the_permit_is_still_enforced_at_re_approval(): void
    {
        // The control for the change above: taking the flag out of approve()
        // must not have taken the permit out with it.
        $unit = $this->licensed(3);
        $this->expand($unit, 3)->assertOk();

        // The permit shrinks underneath the building (written directly — the
        // application refuses this downgrade, which is the point of the guard).
        Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->update(['licensed_units_count' => 2]);

        $member = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->where('approval_status', 'pending')->firstOrFail();

        $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/approvals/{$member->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUANTITY_EXCEEDS_LICENSED_UNITS')
            ->assertJsonPath('meta.licensed_units_count', 2);
    }

    /* ---------- fixtures ---------- */

    private function expand(Unit $unit, int $count): TestResponse
    {
        return $this->actingAs($this->admin, 'admin-panel')
            ->postJson("/admin/units/{$unit->id}/apartments", ['count' => $count]);
    }

    /** @param array<string, mixed> $extra */
    private function licensed(int $licensedUnits, array $extra = []): Unit
    {
        return $this->unit(array_merge([
            'license_type' => UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => $licensedUnits,
        ], $extra));
    }

    /** @param array<string, mixed> $extra */
    private function unit(array $extra = [], ?User $owner = null): Unit
    {
        $owner ??= User::platform();

        $unit = $owner->units()->create(array_merge([
            'unit_name' => 'استوديو روش هوم', 'unit_type' => 'studio',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 450, 'capacity' => 2, 'bedrooms' => 1, 'city' => 'الرياض', 'district' => 'النرجس',
            'approval_status' => 'approved', 'status' => 'available', 'mamsa_owned' => true,
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
            // Everything submitErrors() demands — the copies inherit these.
            'beds' => 1, 'bathrooms' => 1, 'address' => 'حي النرجس',
            'lat' => 24.8136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ لهذه الوحدة. ', 8),
            'tourism_permit_no' => 'TL-'.fake()->unique()->numerify('######'),
            'tourism_permit_file' => 'dashboard/license_pdf/file_test.pdf',
        ], $extra));

        $unit->images()->create(['path' => 'units/'.$unit->id.'/photo.jpg', 'is_main' => true, 'sort_order' => 1]);

        return $unit;
    }
}
