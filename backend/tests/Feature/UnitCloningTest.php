<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permit;
use App\Models\Unit;
use App\Models\UnitImage;
use App\Models\User;
use App\Support\Units\UnitCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A hundred apartments in one tower are a hundred bookable things.
 *
 * The tempting shortcut — one row with a `quantity` — cannot say which door the
 * guest is behind, cannot close 402 for maintenance, and would mean rewriting
 * the double-booking lock. So cloning writes real rows, and these tests pin the
 * parts of that which are easy to get wrong: the two UNIQUE columns, the
 * approval history that must NOT come along, and the shared photo files.
 */
class UnitCloningTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
    }

    private function sourceUnit(array $overrides = []): Unit
    {
        // array_merge, not `+`: the union operator keeps the LEFT value on a
        // duplicate key, so an override would silently lose to the default.
        $unit = $this->partner->units()->create(array_merge([
            // A building needs a facility permit now — the licence rule is not
            // optional, so every fixture that expands one has to carry it.
            'license_type' => \App\Support\Units\UnitLicense::TOURIST_FACILITY,
            'licensed_units_count' => 100,

            'unit_name' => 'شقة برج الملقا', 'unit_type' => 'apartment',
            'code' => 'SRC'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 2,
            'bathrooms' => 1, 'city' => 'الرياض', 'address' => 'حي الملقا',
            'lat' => 24.7136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ للوحدة. ', 5),
            'tourism_permit_no' => 'TL-0001',
            'tourism_permit_file' => 'units/1/docs/licence.pdf',
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ], $overrides));

        $unit->images()->create(['path' => 'units/1/photo.jpg', 'is_main' => true]);

        return $unit->fresh();
    }

    public function test_one_listing_becomes_a_numbered_building(): void
    {
        $unit = $this->sourceUnit();

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", [
                'from' => 401, 'to' => 405,
            ])
            ->assertStatus(201);

        $group = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->get();

        // Five doors, five rows — the source is one of them, not a sixth.
        $this->assertCount(5, $group);
        $this->assertSame(
            ['401', '402', '403', '404', '405'],
            $group->pluck('apartment_no')->sort()->values()->all()
        );
        $this->assertSame('401', $unit->fresh()->apartment_no);
    }

    public function test_every_clone_gets_its_own_code_and_calendar_token(): void
    {
        $unit = $this->sourceUnit();

        UnitCloner::assign($unit, ['401', '402', '403']);

        $group = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->get();

        // Both columns are UNIQUE. Copying calendar_token would hand the whole
        // building one iCal feed, so an external sync on 402 would rewrite the
        // calendars of every other apartment.
        $this->assertCount(3, $group->pluck('code')->unique());
        $this->assertCount(3, $group->pluck('calendar_token')->unique());
        $this->assertEmpty($group->filter(fn ($u) => blank($u->calendar_token)));
    }

    public function test_every_apartment_shares_the_building_name_and_differs_by_door(): void
    {
        $unit = $this->sourceUnit(['unit_name' => 'شقة برج الملقا']);

        UnitCloner::assign($unit, ['401', '402', '403']);

        $group = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->get();

        // One name for the whole building. Suffixing it per door was the first
        // attempt and the storefront proved it wrong: the collapsed card IS the
        // building, so it ended up titled after one arbitrary apartment.
        $this->assertSame(['شقة برج الملقا'], $group->pluck('unit_name')->unique()->values()->all());

        // The door is a field, and it is what tells them apart.
        $this->assertSame(['401', '402', '403'], $group->pluck('apartment_no')->sort()->values()->all());
    }

    public function test_a_clone_of_an_approved_listing_has_not_itself_been_approved(): void
    {
        $unit = $this->sourceUnit(['approval_status' => 'approved']);

        UnitCloner::assign($unit, ['401', '402']);

        $clone = Unit::where('apartment_no', '402')->firstOrFail();

        // Review is per row. A clone inheriting `approved` would put an unseen
        // listing straight onto the storefront.
        $this->assertSame('draft', $clone->approval_status);
        $this->assertSame('approved', $unit->fresh()->approval_status);
    }

    public function test_a_facility_permit_covers_the_clones_whether_or_not_documents_are_copied(): void
    {
        // A facility permit is issued to the PROPERTY. The moment a listing
        // classified that way becomes a building, its permit becomes the
        // building's (PermitWriter::joinGroup) and every apartment mirrors it
        // — `copyDocuments` no longer decides that, the classification does.
        // The other documents are still opt-in: a title deed is the owner's
        // proof of right to list, and one row of it is enough.
        $unit = $this->sourceUnit(['ownership_doc_file' => 'units/1/docs/deed.pdf']);

        UnitCloner::assign($unit, ['401', '402']);
        $clone = Unit::where('apartment_no', '402')->firstOrFail();

        $this->assertSame('TL-0001', $clone->tourism_permit_no);
        $this->assertSame('units/1/docs/licence.pdf', $clone->tourism_permit_file);
        $this->assertNull($clone->ownership_doc_file, 'the title deed is not part of the permit');

        $permit = Permit::currentFor($clone);
        $this->assertNotNull($permit);
        $this->assertSame(Permit::SCOPE_GROUP, $permit->scope_type);
        $this->assertSame($unit->fresh()->unit_group_id, $permit->scope_id);
        $this->assertSame(1, Permit::count(), 'a building holds one permit row, not one per door');
    }

    public function test_an_unclassified_permit_stays_with_its_own_door(): void
    {
        // Nothing may be assumed about what an unclassified permit covers: it
        // stays on the source, and the clones carry one only if the caller
        // copied it onto them — the old opt-in rule, now scoped to exactly the
        // case where the system does not know better.
        $unit = $this->sourceUnit(['license_type' => null, 'licensed_units_count' => null]);

        UnitCloner::assign($unit, ['401', '402']);
        $clone = Unit::where('apartment_no', '402')->firstOrFail();

        $this->assertNull($clone->tourism_permit_no);
        $this->assertNull($clone->tourism_permit_file);
        $this->assertNull(Permit::currentFor($clone));
        $this->assertSame(Permit::SCOPE_UNIT, Permit::currentFor($unit->fresh())->scope_type);

        $unit->forceFill(['unit_group_id' => null, 'apartment_no' => null])->save();
        UnitCloner::assign($unit->fresh(), ['501', '502'], copyDocuments: true);
        $shared = Unit::where('apartment_no', '502')->firstOrFail();

        $this->assertSame('TL-0001', $shared->tourism_permit_no);
    }

    public function test_re_running_the_same_numbers_adds_nothing(): void
    {
        $unit = $this->sourceUnit();

        UnitCloner::assign($unit, ['401', '402', '403']);
        UnitCloner::assign($unit->fresh(), ['401', '402', '403', '404']);

        $group = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->get();

        // A run that dies at apartment 63 has to be safe to repeat.
        $this->assertCount(4, $group);
        $this->assertCount(4, $group->pluck('apartment_no')->unique());
    }

    public function test_deleting_a_photo_from_one_apartment_leaves_the_others_intact(): void
    {
        Storage::disk('public')->put('units/1/photo.jpg', 'binary');
        $unit = $this->sourceUnit();

        UnitCloner::assign($unit, ['401', '402']);
        $clone = Unit::where('apartment_no', '402')->firstOrFail();
        $cloneImage = $clone->images()->firstOrFail();

        $this->actingAs($this->partner, 'sanctum')
            ->deleteJson("/api/v1/partner/units/{$clone->id}/images/{$cloneImage->id}")
            ->assertOk();

        // The row goes; the file stays, because 401 still points at it.
        $this->assertSame(0, $clone->images()->count());
        Storage::disk('public')->assertExists('units/1/photo.jpg');

        // Last reference gone — now it may be unlinked.
        $last = $unit->fresh()->images()->firstOrFail();
        $this->actingAs($this->partner, 'sanctum')
            ->deleteJson("/api/v1/partner/units/{$unit->id}/images/{$last->id}")
            ->assertOk();

        Storage::disk('public')->assertMissing('units/1/photo.jpg');
    }

    public function test_a_building_larger_than_the_cap_is_refused(): void
    {
        $unit = $this->sourceUnit();

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", [
                'from' => 1, 'to' => 500,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'GROUP_TOO_LARGE');

        $this->assertNull($unit->fresh()->unit_group_id);
    }

    public function test_a_partner_cannot_clone_someone_elses_listing(): void
    {
        $unit = $this->sourceUnit();
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Individual');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", ['numbers' => ['401']])
            ->assertStatus(403);

        $this->assertSame(1, Unit::count());
    }

    public function test_the_door_number_never_reaches_the_public_payload(): void
    {
        $unit = $this->sourceUnit();
        UnitCloner::assign($unit, ['401', '402']);

        $body = $this->getJson("/api/v1/units/{$unit->id}")->assertOk()->json();
        $payload = $body['data'] ?? $body;

        // The guest card shows the building, not the door.
        $this->assertArrayNotHasKey('apartment_no', $payload);
        $this->assertArrayNotHasKey('unit_group_id', $payload);

        // 32, up from the original 30. Two keys were added on purpose:
        // `available_count`, so the card can say how many apartments are free,
        // and `listing_id`, the stable identity of a building — `id` is not
        // that, because the card shows whichever apartment happens to be free.
        // Pinned so the NEXT key here has to be a decision someone made.
        $this->assertArrayHasKey('available_count', $payload);
        $this->assertArrayHasKey('listing_id', $payload);
        $this->assertCount(32, $payload);

        // The group ULID IS the listing id — that is the point of it — but the
        // raw column stays gated so nothing else about the grouping leaks.
        $this->assertSame($unit->fresh()->unit_group_id, $payload['listing_id']);
    }
}
