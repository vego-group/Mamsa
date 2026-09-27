<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DashboardUpload;
use App\Models\Permit;
use App\Models\PermitReminder;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\PermitExpiring;
use App\Support\Permits\PermitMode;
use App\Support\Permits\PermitWriter;
use App\Support\Units\UnitCloner;
use App\Support\Units\UnitLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The things the consoles and the guest app were reading around, because the
 * API did not say them: which building an apartment belongs to, whether the
 * permit's address matches the listing's, how big a building is, and what the
 * server's flags are set to right now.
 */
class PhaseSixContractTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private User $admin;

    private User $guest;

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
        $this->guest = User::factory()->create(['is_active' => true]);
        $this->guest->assignRole('User');
    }

    /* ---------- runtime flags ---------- */

    public function test_the_flags_are_readable_at_runtime_on_every_surface(): void
    {
        // The apps bake these in at build time, so flipping one on the server
        // does nothing until three deploys happen. This is the fix.
        config()->set('units.multi_unit_enabled', false);
        config()->set('permits.expiry_required', true);
        config()->set('permits.warning_days', 45);

        foreach (['/api/v1/config', '/config', '/admin/config'] as $path) {
            $this->getJson($path)
                ->assertOk()
                ->assertJsonPath('flags.multiUnitEnabled', false)
                ->assertJsonPath('flags.permitExpiryRequired', true)
                ->assertJsonPath('flags.legacyUnitWritesEnabled', false)
                ->assertJsonPath('permitWarningDays', 45);
        }
    }

    public function test_the_flags_endpoint_needs_no_session(): void
    {
        // The login screen needs them too, and a console that has to
        // authenticate before it can know whether to show a button would show
        // the wrong one first.
        $this->getJson('/config')->assertOk();
        $this->getJson('/admin/config')->assertOk();
    }

    /* ---------- group context ---------- */

    public function test_the_public_card_says_how_big_the_building_is(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 6]);
        UnitCloner::ensureTotal($source, 4);
        Unit::where('unit_group_id', $source->fresh()->unit_group_id)->update(['approval_status' => 'approved']);

        $card = collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->firstWhere('listing_id', $source->fresh()->unit_group_id);

        $this->assertSame(4, $card['group_size'], '"4 available" leaves the guest to wonder four of what');
        $this->assertSame(4, $card['available_count']);
    }

    public function test_a_standalone_listing_reports_a_group_of_one(): void
    {
        // So a client reads the field unconditionally rather than branching.
        $unit = $this->unit();

        $card = collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->firstWhere('id', $unit->id);

        $this->assertSame(1, $card['group_size']);
    }

    public function test_the_reviewer_sees_the_whole_building(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::PRIVATE_HOSPITALITY]);
        $this->actingAs($this->partner, 'dashboard')
            ->postJson("/units/u_{$source->id}/apartments", [
                'count' => 3,
                'permits' => [
                    ['number' => 'G-2', 'fileId' => $this->licenceFile()],
                    ['number' => 'G-3', 'fileId' => $this->licenceFile()],
                ],
            ])->assertOk();

        $door = Unit::where('unit_group_id', $source->fresh()->unit_group_id)->where('approval_status', 'pending')->firstOrFail();

        $body = $this->actingAs($this->admin, 'admin-panel')->getJson("/admin/approvals/{$door->id}")->assertOk()->json();

        $this->assertSame($source->fresh()->unit_group_id, $body['group']['id']);
        $this->assertSame(3, $body['group']['size']);
        $this->assertSame(PermitMode::PER_UNIT, $body['group']['mode']);
        $this->assertCount(3, $body['group']['apartments']);

        // Each door's OWN permit number — the reviewer has to see that they
        // differ, because in this mode they must.
        $numbers = collect($body['group']['apartments'])->pluck('permitNumber')->all();
        $this->assertCount(3, array_unique($numbers));
    }

    public function test_a_standalone_listing_carries_no_group_object(): void
    {
        // Not a building of one — the screen should show nothing.
        $unit = $this->unit(['approval_status' => 'pending']);

        $this->actingAs($this->admin, 'admin-panel')
            ->getJson("/admin/approvals/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('group', null);
    }

    /* ---------- address comparison ---------- */

    public function test_the_city_is_compared_and_the_district_never_is(): void
    {
        $unit = $this->unit(['approval_status' => 'pending', 'city' => 'الرياض', 'district' => 'النرجس']);
        PermitWriter::apply($unit, ['addr_city' => 'riyadh', 'addr_district' => 'حي النرجس']);

        $body = $this->actingAs($this->admin, 'admin-panel')->getJson("/admin/approvals/{$unit->id}")->assertOk()->json();

        // `riyadh` and `الرياض` are the same city through one canonical map.
        $this->assertTrue($body['addressMatch']['city']);
        // `النرجس` and `حي النرجس` are the same place and different strings, so
        // the machine says nothing rather than saying something wrong.
        $this->assertNull($body['addressMatch']['district']);
        $this->assertSame('حي النرجس', $body['permit']['address']['district']);
    }

    public function test_a_city_that_does_not_match_says_so(): void
    {
        $unit = $this->unit(['approval_status' => 'pending', 'city' => 'الرياض']);
        PermitWriter::apply($unit, ['addr_city' => 'jeddah']);

        $this->actingAs($this->admin, 'admin-panel')
            ->getJson("/admin/approvals/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('addressMatch.city', false);
    }

    public function test_no_recorded_permit_address_is_not_a_match(): void
    {
        // null means "not compared" — never "matches".
        $unit = $this->unit(['approval_status' => 'pending']);

        $this->actingAs($this->admin, 'admin-panel')
            ->getJson("/admin/approvals/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('addressMatch.city', null);
    }

    /* ---------- permit address on the unit itself ---------- */

    public function test_the_partner_can_record_the_permit_address_without_renewing(): void
    {
        // It used to be writable only through a renewal, so a listing that
        // never renewed could never record it and the reviewer had nothing to
        // compare.
        $unit = $this->unit(['approval_status' => 'draft']);

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", [
                'permitAddressCity' => 'الرياض',
                'permitAddressDistrict' => 'النرجس',
                'permitAddressBuilding' => '12',
                'permitAddressUnitNo' => '3',
            ])
            ->assertOk()
            ->assertJsonPath('permitAddress.district', 'النرجس')
            ->assertJsonPath('permitAddress.building', '12');

        $this->assertSame('3', Permit::currentFor($unit->fresh())->addr_unit_no);
    }

    public function test_the_admin_console_reads_both_names_for_the_permit_number(): void
    {
        // The console has always WRITTEN tourismLicenseNumber and READ
        // tourismPermitNo — two names for one field on one screen.
        $unit = $this->unit(['tourism_permit_no' => 'A2-KEY']);

        $this->actingAs($this->admin, 'admin-panel')
            ->getJson("/admin/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('tourismPermitNo', 'A2-KEY')
            ->assertJsonPath('tourismLicenseNumber', 'A2-KEY');
    }

    /* ---------- the banner and the booking ---------- */

    public function test_the_expiry_notification_carries_the_listing_and_a_way_to_act(): void
    {
        Notification::fake();
        $unit = $this->unit();
        PermitWriter::apply($unit, ['expires_at' => now()->addDays(30)->toDateString()]);

        $this->artisan('permits:check-expiry')->assertSuccessful();

        Notification::assertSentTo($this->partner, PermitExpiring::class, function (PermitExpiring $n) use ($unit) {
            $data = $n->toArray($this->partner);

            return $data['unit_id'] === $unit->id
                && $data['href'] === "/units/{$unit->id}/permit/renew"
                && $data['body'] !== ''
                && $data['title'] !== '';
        });

        $this->assertSame(1, PermitReminder::count());
    }

    public function test_a_booking_says_which_apartment_the_guest_got(): void
    {
        $source = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 6]);
        UnitCloner::ensureTotal($source, 3);
        Unit::where('unit_group_id', $source->fresh()->unit_group_id)->update(['approval_status' => 'approved']);

        $body = $this->actingAs($this->guest)
            ->postJson('/api/v1/bookings', [
                'unit_id' => $source->id,
                'start_date' => now()->addDays(3)->toDateString(),
                'end_date' => now()->addDays(5)->toDateString(),
                'guests' => 2,
            ])
            ->assertStatus(201)
            ->json();

        $unit = $body['data']['unit'] ?? $body['unit'];

        $this->assertArrayHasKey('apartment_no', $unit, 'the guest cannot tell which door they were given');
        $this->assertSame(Booking::first()->unit->apartment_no, $unit['apartment_no']);
    }

    public function test_the_door_number_still_never_reaches_the_public_listing(): void
    {
        // The card is the building; a door number there is noise, and the
        // public payload is a pinned contract.
        $source = $this->unit(['license_type' => UnitLicense::TOURIST_FACILITY, 'licensed_units_count' => 6]);
        UnitCloner::ensureTotal($source, 2);
        Unit::where('unit_group_id', $source->fresh()->unit_group_id)->update(['approval_status' => 'approved']);

        $payload = $this->getJson("/api/v1/units/{$source->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('apartment_no', $payload);
    }

    /* ---------- fixtures ---------- */

    private function licenceFile(): string
    {
        $id = 'file_'.strtolower((string) str()->ulid());

        DashboardUpload::create([
            'id' => $id, 'user_id' => $this->partner->id, 'kind' => 'license_pdf',
            'original_name' => 'p.pdf', 'mime' => 'application/pdf', 'size' => 512,
            'status' => 'stored', 'path' => "dashboard/license_pdf/{$id}.pdf",
        ]);

        return $id;
    }

    /** @param array<string, mixed> $extra */
    private function unit(array $extra = []): Unit
    {
        $unit = $this->partner->units()->create(array_merge([
            'unit_name' => 'شقة', 'unit_type' => 'apartment',
            'code' => 'P6'.fake()->unique()->numerify('######'),
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
