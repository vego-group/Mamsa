<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permits\PermitRenewal;
use App\Support\Permits\PermitWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two questions the partner dashboard asked before fixing its renewal screen
 * with tests of its own (2026-09-30). Each answer here is what the code did
 * already — these tests make it a contract instead of an accident.
 *
 *  1. A unit UNDER REVIEW whose permit has lapsed: the wizard is locked (409),
 *     so renewal is the partner's only way out. It must work, and the reviewer
 *     must be able to see it — otherwise they open the request, find a dead
 *     permit, and reject a partner who did everything right.
 *
 *  2. permitAddress in a renewal is inherited FIELD BY FIELD from the permit in
 *     force: absent → inherited; null → inherited, not cleared (unlike PATCH on
 *     the unit, where null clears); a value → replaces that one field.
 */
class PermitRenewalContractTest extends TestCase
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

    /* ---------- 1. a unit under review whose permit lapsed ---------- */

    public function test_a_unit_under_review_can_be_renewed_though_it_cannot_be_edited(): void
    {
        $unit = $this->unit('pending', expires: now()->subDay()->toDateString());

        // The wizard is locked while the unit is under review…
        $this->asPartner()->patchJson("/units/u_{$unit->id}", ['permitExpiresAt' => now()->addYear()->toDateString()])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'UNIT_LOCKED');

        // …but renewal is not, and it does not pull the unit out of review.
        $this->asPartner()->postJson("/units/u_{$unit->id}/permit-renewals", ['permitExpiresAt' => now()->addYear()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('status', 'pending');

        $this->assertSame('pending', $unit->fresh()->approval_status, 'filing a renewal changed the review state');
        $this->assertNotNull(PermitRenewal::pendingFor($unit));
    }

    public function test_the_reviewer_sees_the_waiting_renewal_on_the_approval_screen(): void
    {
        $unit = $this->unit('pending', expires: now()->subDay()->toDateString());
        $this->asPartner()->postJson("/units/u_{$unit->id}/permit-renewals", ['permitExpiresAt' => now()->addYear()->toDateString()])->assertCreated();
        $renewal = PermitRenewal::pendingFor($unit);

        // The permit block alone says "expired". The renewal is on the unit
        // block beside it — a screen that shows only the first will reject a
        // partner who already fixed it.
        $this->asAdmin()->getJson("/admin/approvals/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('permit.status', 'expired')
            ->assertJsonPath('unit.pendingRenewalId', (string) $renewal->id);
    }

    public function test_renewal_first_then_the_unit_ends_up_selling(): void
    {
        $unit = $this->unit('pending', expires: now()->subDay()->toDateString());
        $this->asPartner()->postJson("/units/u_{$unit->id}/permit-renewals", ['permitExpiresAt' => now()->addYear()->toDateString()])->assertCreated();

        $this->asAdmin()->postJson('/admin/permit-renewals/'.PermitRenewal::pendingFor($unit)->id.'/approve')->assertOk();
        $this->assertSame('pending', $unit->fresh()->approval_status, 'approving the renewal decided the unit too');

        $this->asAdmin()->postJson("/admin/approvals/{$unit->id}/approve")->assertOk();
        $this->getJson("/api/v1/units/{$unit->id}")->assertOk();
    }

    public function test_unit_first_then_it_sells_only_once_the_renewal_is_approved(): void
    {
        $unit = $this->unit('pending', expires: now()->subDay()->toDateString());
        $this->asPartner()->postJson("/units/u_{$unit->id}/permit-renewals", ['permitExpiresAt' => now()->addYear()->toDateString()])->assertCreated();

        // Approving the unit does not check the permit's date; expiry is
        // computed at sale time. Approved but lapsed = approved, not selling.
        $this->asAdmin()->postJson("/admin/approvals/{$unit->id}/approve")->assertOk();
        $this->getJson("/api/v1/units/{$unit->id}")->assertStatus(404);

        $this->asAdmin()->postJson('/admin/permit-renewals/'.PermitRenewal::pendingFor($unit)->id.'/approve')->assertOk();
        $this->getJson("/api/v1/units/{$unit->id}")->assertOk();
    }

    /* ---------- 2. permitAddress in a renewal ---------- */

    public function test_an_absent_address_is_inherited_whole(): void
    {
        $unit = $this->unitWithAddress();

        $this->renew($unit, [])->assertCreated();

        $this->assertSame($this->fullAddress(), $this->addressOf(PermitRenewal::pendingFor($unit)));
    }

    public function test_four_nulls_mean_inherit_not_clear(): void
    {
        // Deliberately unlike PATCH /units/{id}, where null clears: a renewal
        // form with an untouched blank must not wipe the permit's address.
        $unit = $this->unitWithAddress();

        $this->renew($unit, ['permitAddress' => ['city' => null, 'district' => null, 'building' => null, 'unitNo' => null]])
            ->assertCreated();

        $this->assertSame($this->fullAddress(), $this->addressOf(PermitRenewal::pendingFor($unit)));
    }

    public function test_an_empty_string_is_the_same_as_null(): void
    {
        $unit = $this->unitWithAddress();

        $this->renew($unit, ['permitAddress' => ['city' => '', 'building' => '']])->assertCreated();

        $this->assertSame($this->fullAddress(), $this->addressOf(PermitRenewal::pendingFor($unit)));
    }

    public function test_one_field_sent_replaces_that_field_only(): void
    {
        $unit = $this->unitWithAddress();

        $this->renew($unit, ['permitAddress' => ['district' => 'الملقا']])->assertCreated();

        $this->assertSame(
            ['city' => 'الرياض', 'district' => 'الملقا', 'building' => '12', 'unitNo' => '3'],
            $this->addressOf(PermitRenewal::pendingFor($unit)),
        );
    }

    public function test_the_inherited_address_is_what_the_partner_reads_after_approval(): void
    {
        $unit = $this->unitWithAddress();
        $this->renew($unit, [])->assertCreated();

        $this->asAdmin()->postJson('/admin/permit-renewals/'.PermitRenewal::pendingFor($unit)->id.'/approve')->assertOk();

        $this->asPartner()->getJson("/units/u_{$unit->id}")
            ->assertOk()
            ->assertJsonPath('permitAddress', $this->fullAddress());
    }

    /* ---------- helpers ---------- */

    private function renew(Unit $unit, array $extra)
    {
        return $this->asPartner()->postJson("/units/u_{$unit->id}/permit-renewals", array_merge(
            ['permitExpiresAt' => now()->addYear()->toDateString()],
            $extra,
        ));
    }

    private function unitWithAddress(): Unit
    {
        $unit = $this->unit('approved', expires: now()->addDays(20)->toDateString());
        PermitWriter::apply($unit, [
            'addr_city' => 'الرياض', 'addr_district' => 'النرجس', 'addr_building' => '12', 'addr_unit_no' => '3',
        ]);

        return $unit->fresh();
    }

    /** @return array<string, string> */
    private function fullAddress(): array
    {
        return ['city' => 'الرياض', 'district' => 'النرجس', 'building' => '12', 'unitNo' => '3'];
    }

    /** @return array<string, ?string> */
    private function addressOf(Permit $permit): array
    {
        return [
            'city' => $permit->addr_city, 'district' => $permit->addr_district,
            'building' => $permit->addr_building, 'unitNo' => $permit->addr_unit_no,
        ];
    }

    private function asPartner(): static
    {
        return $this->actingAs($this->partner, 'dashboard');
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin-panel');
    }

    private function unit(string $status, string $expires): Unit
    {
        $unit = $this->partner->units()->create([
            'unit_name' => 'شقة', 'unit_type' => 'apartment',
            'code' => 'RC'.fake()->unique()->numerify('######'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 2, 'bathrooms' => 1,
            'city' => 'الرياض', 'district' => 'الملقا', 'address' => 'حي الملقا',
            'lat' => 24.7136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ للوحدة. ', 5),
            'tourism_permit_no' => 'TL-'.fake()->unique()->numerify('######'),
            'tourism_permit_file' => 'dashboard/license_pdf/file_test.pdf',
            'approval_status' => $status, 'status' => 'available',
            'checkout_time' => '12:00', 'calendar_token' => str()->random(60),
        ]);

        $unit->images()->create(['path' => 'units/'.$unit->id.'/photo.jpg', 'is_main' => true, 'sort_order' => 1]);
        PermitWriter::apply($unit, ['expires_at' => $expires]);

        return $unit->fresh();
    }
}
