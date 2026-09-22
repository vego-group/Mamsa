<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permits\PermitExpiry;
use App\Support\Permits\PermitWriter;
use App\Support\Units\UnitCloner;
use App\Support\Units\UnitLicense;
use App\Support\Units\UnitWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2: the permit expiry caps the calendar.
 *
 * The failure this replaces is a reviewer reading the expiry date once, on
 * approval day, and nothing looking again — the listing kept selling stays
 * beginning after its permit had lapsed, and the first anyone knew was a guest
 * at a door. So the date is a cap, not an alarm: search does not offer those
 * dates, the probe refuses them, the create refuses them, and the calendar
 * returns them closed.
 */
class PermitExpiryTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        config()->set('units.multi_unit_enabled', true);

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->guest = User::factory()->create(['is_active' => true]);
        $this->guest->assignRole('User');
    }

    /* ---------- the rule itself ---------- */

    public function test_a_stay_may_end_on_the_expiry_day_but_not_after(): void
    {
        // The check-out day is allowed: a permit valid through the 30th covers
        // a guest who leaves on the 30th, because their last NIGHT is the 29th.
        $unit = $this->unit(expires: '2026-11-30');

        $this->assertTrue(PermitExpiry::coversStay($unit, '2026-11-29'));
        $this->assertTrue(PermitExpiry::coversStay($unit, '2026-11-30'), 'check-out on the expiry day is inside the permit');
        $this->assertFalse(PermitExpiry::coversStay($unit, '2026-12-01'));
    }

    public function test_no_date_caps_nothing(): void
    {
        // Every permit written before the column existed has no date, and
        // inventing one would take real listings off the market on a guess.
        $unit = $this->unit(expires: null);

        $this->assertTrue(PermitExpiry::coversStay($unit, '2030-01-01'));
        $this->assertFalse(PermitExpiry::lapsed($unit));
        $this->assertSame('unknown', PermitExpiry::status($unit));
        $this->assertNull(PermitExpiry::blockedRange($unit, now()->toDateString(), now()->addYear()->toDateString()));
    }

    public function test_the_status_the_consoles_render(): void
    {
        $this->assertSame('valid', PermitExpiry::status($this->unit(expires: now()->addDays(90)->toDateString())));
        $this->assertSame('expiring', PermitExpiry::status($this->unit(expires: now()->addDays(10)->toDateString())));
        $this->assertSame('expired', PermitExpiry::status($this->unit(expires: now()->subDay()->toDateString())));
    }

    /* ---------- the storefront ---------- */

    public function test_a_lapsed_listing_leaves_the_storefront(): void
    {
        $live = $this->unit(expires: now()->addYear()->toDateString());
        $lapsed = $this->unit(expires: now()->subDay()->toDateString());

        $ids = collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($lapsed->id, $ids, 'a listing whose permit ran out is still on the storefront');

        $this->getJson("/api/v1/units/{$lapsed->id}")->assertStatus(404);
        $this->getJson("/api/v1/units/{$live->id}")->assertOk();
    }

    public function test_a_dated_search_does_not_offer_a_stay_the_permit_cannot_host(): void
    {
        $unit = $this->unit(expires: now()->addDays(20)->toDateString());

        // Inside the permit: offered.
        $this->getJson('/api/v1/units?start_date='.now()->addDays(5)->toDateString().'&end_date='.now()->addDays(7)->toDateString())
            ->assertOk()
            ->assertJsonFragment(['id' => $unit->id]);

        // Ending after it: not offered — before this, the card was shown and
        // the booking was refused at the last step.
        $ids = collect($this->getJson('/api/v1/units?start_date='.now()->addDays(25)->toDateString().'&end_date='.now()->addDays(27)->toDateString())
            ->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($unit->id, $ids);
    }

    public function test_its_own_permit_decides_not_its_buildings(): void
    {
        // A door with a private permit of its own inside a building whose
        // facility permit has lapsed: the door trades under its own.
        $source = $this->unit(expires: now()->subDay()->toDateString(), extra: [
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 4,
        ]);
        UnitCloner::ensureTotal($source, 2);
        $this->approveGroup($source);
        $door = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->where('id', '!=', $source->id)->firstOrFail();

        PermitWriter::write(fn () => Permit::create([
            'scope_type' => Permit::SCOPE_UNIT, 'scope_id' => (string) $door->id,
            'number' => 'OWN-1', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY,
            'expires_at' => now()->addYear()->toDateString(), 'status' => Permit::STATUS_CURRENT,
        ]));

        $this->assertFalse(PermitExpiry::lapsed($door->fresh()), 'the group permit overrode the door’s own');

        $ids = collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($door->id, $ids);
        $this->assertNotContains($source->id, $ids);
    }

    /* ---------- probe, create, calendar ---------- */

    public function test_the_probe_refuses_what_the_create_would_refuse(): void
    {
        // A probe that disagreed with the create is how a guest loses a
        // booking at the last step.
        $unit = $this->unit(expires: now()->addDays(10)->toDateString());

        $this->postJson("/api/v1/units/{$unit->id}/availability", [
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(22)->toDateString(),
        ])->assertStatus(409)->assertJsonPath('code', PermitExpiry::CODE);

        $this->postJson("/api/v1/units/{$unit->id}/availability", [
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
        ])->assertOk()->assertJsonPath('available', true);
    }

    public function test_the_create_refuses_a_stay_past_the_permit(): void
    {
        $unit = $this->unit(expires: now()->addDays(10)->toDateString());

        $this->actingAs($this->guest)
            ->postJson('/api/v1/bookings', [
                'unit_id' => $unit->id,
                'start_date' => now()->addDays(20)->toDateString(),
                'end_date' => now()->addDays(22)->toDateString(),
                'guests' => 2,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', PermitExpiry::CODE)
            ->assertJsonPath('meta.permit_expires_at', now()->addDays(10)->toDateString());

        $this->assertSame(0, $unit->bookings()->count());
    }

    public function test_a_stay_inside_the_permit_still_books(): void
    {
        // The control: the cap must not have closed the calendar generally.
        $unit = $this->unit(expires: now()->addDays(30)->toDateString());

        $this->actingAs($this->guest)
            ->postJson('/api/v1/bookings', [
                'unit_id' => $unit->id,
                'start_date' => now()->addDays(3)->toDateString(),
                'end_date' => now()->addDays(5)->toDateString(),
                'guests' => 2,
            ])
            ->assertStatus(201);

        $this->assertSame(1, $unit->bookings()->count());
    }

    public function test_the_allocator_skips_a_door_whose_own_permit_ran_out(): void
    {
        // Three doors: the first is already booked for the dates, the second
        // carries a private permit that has lapsed, the third is free and
        // licensed. The guest must land on the third — and never on the second,
        // which could not legally host them.
        $source = $this->unit(expires: now()->addYear()->toDateString(), extra: [
            'license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 4,
        ]);
        UnitCloner::ensureTotal($source, 3);
        $this->approveGroup($source);

        $doors = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->orderBy('id')->get();
        [$booked, $lapsed, $free] = [$doors[0], $doors[1], $doors[2]];

        $start = now()->addDays(3)->toDateString();
        $end = now()->addDays(5)->toDateString();

        Booking::withoutEvents(fn () => Booking::create([
            'unit_id' => $booked->id, 'user_id' => $this->guest->id,
            'start_date' => $start, 'end_date' => $end, 'guests' => 2, 'nights' => 2,
            'subtotal' => 1000, 'taxes' => 150, 'tax_percent' => 15, 'total_amount' => 1150,
            'commission_rate' => 0.10, 'commission_amount' => 100, 'partner_share' => 900,
            'status' => Booking::STATUS_CONFIRMED,
        ]));

        PermitWriter::write(fn () => Permit::create([
            'scope_type' => Permit::SCOPE_UNIT, 'scope_id' => (string) $lapsed->id,
            'number' => 'LAPSED-1', 'license_type' => UnitLicense::PRIVATE_HOSPITALITY,
            'expires_at' => now()->subDay()->toDateString(), 'status' => Permit::STATUS_CURRENT,
        ]));

        $this->actingAs($this->guest)
            ->postJson('/api/v1/bookings', [
                'unit_id' => $source->id, 'start_date' => $start, 'end_date' => $end, 'guests' => 2,
            ])
            ->assertStatus(201);

        $this->assertSame(0, $lapsed->bookings()->where('start_date', $start)->count(), 'the guest was given the door whose permit had lapsed');
        $this->assertSame(1, $free->bookings()->count(), 'the free, licensed door should have taken it');
    }

    public function test_a_building_entirely_out_of_permit_says_so_rather_than_full(): void
    {
        // "Fully booked" would send the guest looking for other dates that do
        // not exist either.
        $unit = $this->unit(expires: now()->addDays(5)->toDateString());

        $this->actingAs($this->guest)
            ->postJson('/api/v1/bookings', [
                'unit_id' => $unit->id,
                'start_date' => now()->addDays(10)->toDateString(),
                'end_date' => now()->addDays(12)->toDateString(),
                'guests' => 2,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', PermitExpiry::CODE);
    }

    public function test_the_calendar_returns_the_days_after_expiry_as_closed(): void
    {
        $unit = $this->unit(expires: now()->addDays(10)->toDateString());

        $blocked = $this->getJson("/api/v1/units/{$unit->id}/blocked-dates?from=".now()->toDateString().'&to='.now()->addDays(40)->toDateString())
            ->assertOk()
            ->json('blocked');

        $permitRange = collect($blocked)->firstWhere('reason', 'permit_expiry');

        $this->assertNotNull($permitRange, 'the picker was given no reason to close the dates the create refuses');
        $this->assertSame(now()->addDays(10)->toDateString(), $permitRange['start']);
        $this->assertSame(now()->addDays(40)->toDateString(), $permitRange['end']);
    }

    public function test_a_permit_beyond_the_window_closes_nothing(): void
    {
        $unit = $this->unit(expires: now()->addYears(2)->toDateString());

        $blocked = $this->getJson("/api/v1/units/{$unit->id}/blocked-dates")->assertOk()->json('blocked');

        $this->assertNull(collect($blocked)->firstWhere('reason', 'permit_expiry'));
    }

    /* ---------- submit ---------- */

    public function test_a_lapsed_permit_blocks_submission_whatever_the_flag_says(): void
    {
        config()->set('permits.expiry_required', false);
        $unit = $this->unit(expires: now()->subDay()->toDateString(), extra: ['approval_status' => 'draft']);

        $this->assertArrayHasKey('permitExpiresAt', UnitWriter::submitErrors($unit));

        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$unit->id}/submit")
            ->assertStatus(400)
            ->assertJsonPath('error.fields.permitExpiresAt', 'تصريح الوحدة منتهي — جدّده قبل الإرسال للمراجعة');
    }

    public function test_a_missing_date_blocks_submission_only_once_the_flag_is_on(): void
    {
        $unit = $this->unit(expires: null, extra: ['approval_status' => 'draft']);

        config()->set('permits.expiry_required', false);
        $this->assertArrayNotHasKey('permitExpiresAt', UnitWriter::submitErrors($unit));

        config()->set('permits.expiry_required', true);
        $this->assertSame('تاريخ انتهاء التصريح مطلوب', UnitWriter::submitErrors($unit)['permitExpiresAt'] ?? null);
    }

    /* ---------- the contract surfaces ---------- */

    public function test_the_partner_writes_and_reads_the_date(): void
    {
        $unit = $this->unit(expires: null, extra: ['approval_status' => 'draft']);

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", ['permitExpiresAt' => '2027-03-15'])
            ->assertOk()
            ->assertJsonPath('permitExpiresAt', '2027-03-15')
            ->assertJsonPath('permitStatus', 'valid');

        $this->assertSame('2027-03-15', Permit::currentFor($unit->fresh())->expires_at->toDateString());
    }

    public function test_the_admin_console_reads_it_too(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('SuperAdmin');
        $unit = $this->unit(expires: now()->addDays(10)->toDateString());

        $this->actingAs($admin, 'admin-panel')
            ->getJson("/admin/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('permitExpiresAt', now()->addDays(10)->toDateString())
            ->assertJsonPath('permitStatus', 'expiring');
    }

    public function test_the_guest_api_never_carries_the_permit(): void
    {
        // A permit number and its expiry are the partner's compliance papers,
        // not part of a listing a guest reads.
        $unit = $this->unit(expires: now()->addYear()->toDateString());

        $body = $this->getJson("/api/v1/units/{$unit->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('permitExpiresAt', $body);
        $this->assertArrayNotHasKey('permitStatus', $body);
        $this->assertArrayNotHasKey('tourism_permit_no', $body);
    }

    /* ---------- fixtures ---------- */

    /**
     * Clones are created as drafts — they have never been reviewed. Every test
     * here is about a LIVE building, so they are published the way the review
     * queue would publish them.
     */
    private function approveGroup(Unit $source): void
    {
        Unit::where('unit_group_id', $source->fresh()->unit_group_id)
            ->update(['approval_status' => 'approved', 'status' => 'available']);
    }

    /** @param array<string, mixed> $extra */
    private function unit(?string $expires, array $extra = []): Unit
    {
        $unit = $this->partner->units()->create(array_merge([
            'unit_name' => 'شقة', 'unit_type' => 'apartment',
            'code' => 'PX'.fake()->unique()->numerify('######'),
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

        if ($expires !== null) {
            PermitWriter::apply($unit, ['expires_at' => $expires]);
        }

        return $unit->fresh();
    }
}
