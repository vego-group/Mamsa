<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PartnerDetail;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Support\Dashboard\UnitPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A public URL that names the BUILDING instead of a door.
 *
 * The storefront collapses a building to one card and the server allocates an
 * apartment at booking time, so the id in a URL is whichever door was the
 * representative that day. That door can close while the building keeps
 * selling, and every saved link and favourite pointing at it then 404s against
 * something still open. These tests pin the fix: `listing_id` works wherever
 * an id works, and resolves to the same card the search shows.
 */
class ListingKeyRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    public function test_a_building_key_opens_the_same_card_the_search_shows(): void
    {
        [$first] = $this->building(3);
        $key = $first->unit_group_id;

        $fromSearch = $this->getJson('/api/v1/units')->assertOk()->json('data.0');

        $this->assertSame($key, $fromSearch['listing_id']);

        $byKey = $this->getJson("/api/v1/units/{$key}")->assertOk()->json('data');

        $this->assertSame($fromSearch['id'], $byKey['id'], 'the key opened a different door than the card');
        $this->assertSame($key, $byKey['listing_id']);
    }

    public function test_the_key_survives_the_representative_closing(): void
    {
        // The whole point. The card's own door is withdrawn; the building keeps
        // selling; a link saved as the listing key must still open it.
        [$first, $second] = $this->building(2);
        $key = $first->unit_group_id;

        $this->getJson("/api/v1/units/{$first->id}")->assertOk();

        $first->forceFill(['status' => 'unavailable'])->saveQuietly();

        // The id link is now dead…
        $this->getJson("/api/v1/units/{$first->id}")->assertStatus(404);

        // …and the listing key has moved to the next open door by itself.
        $this->getJson("/api/v1/units/{$key}")
            ->assertOk()
            ->assertJsonPath('data.id', $second->id);
    }

    public function test_the_sub_routes_take_the_key_too(): void
    {
        [$first] = $this->building(2);
        $key = $first->unit_group_id;

        $this->getJson("/api/v1/units/{$key}/blocked-dates")->assertOk()->assertJsonStructure(['from', 'to', 'blocked']);
        $this->getJson("/api/v1/units/{$key}/reviews")->assertOk();
        $this->postJson("/api/v1/units/{$key}/availability", [
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
        ])->assertOk()->assertJsonPath('available', true);
    }

    public function test_a_standalone_listing_answers_to_its_u_prefixed_key(): void
    {
        $unit = $this->unit();

        $this->getJson("/api/v1/units/u{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $unit->id)
            ->assertJsonPath('data.listing_id', 'u'.$unit->id);
    }

    public function test_a_plain_id_still_resolves_exactly_as_before(): void
    {
        $unit = $this->unit();

        $this->getJson("/api/v1/units/{$unit->id}")->assertOk()->assertJsonPath('data.id', $unit->id);
        $this->getJson('/api/v1/units/999999')->assertStatus(404);
        $this->getJson('/api/v1/units/not-a-listing')->assertStatus(404);
    }

    public function test_a_building_whose_doors_are_all_closed_is_a_404_not_a_broken_page(): void
    {
        [$first, $second] = $this->building(2);
        $key = $first->unit_group_id;

        Unit::whereIn('id', [$first->id, $second->id])->update(['status' => 'unavailable']);

        $this->getJson("/api/v1/units/{$key}")->assertStatus(404);
    }

    public function test_a_door_with_its_own_lapsed_permit_is_not_chosen_as_the_representative(): void
    {
        [$first, $second] = $this->building(2);
        $key = $first->unit_group_id;

        // Per-unit mode: each door carries its own paper, so one can lapse
        // while its neighbour sells. The lowest id would normally win; a dead
        // permit takes it out of the running, the same way the search does.
        Permit::create([
            'scope_type' => Permit::SCOPE_UNIT, 'scope_id' => (string) $first->id,
            'number' => 'LAPSED-1', 'license_type' => 'private_hospitality',
            'expires_at' => now()->subDay()->toDateString(), 'status' => Permit::STATUS_CURRENT,
        ]);

        $this->getJson("/api/v1/units/{$key}")
            ->assertOk()
            ->assertJsonPath('data.id', $second->id);
    }

    public function test_a_building_whose_permit_lapsed_is_a_404(): void
    {
        // One facility permit over the whole building: when it runs out the
        // building is off the storefront, so its key has nothing to open.
        [$first] = $this->building(2);
        $key = $first->unit_group_id;

        Permit::create([
            'scope_type' => Permit::SCOPE_GROUP, 'scope_id' => (string) $key,
            'number' => 'LAPSED-GROUP', 'license_type' => 'tourist_facility',
            'licensed_units_count' => 2,
            'expires_at' => now()->subDay()->toDateString(), 'status' => Permit::STATUS_CURRENT,
        ]);

        $this->getJson("/api/v1/units/{$key}")->assertStatus(404);
    }

    public function test_a_favourited_building_survives_its_saved_door_closing(): void
    {
        // The favourite is stored against the building's lowest id, which is
        // stable — but stable is not open. When that door is withdrawn the
        // building keeps selling, and the heart must not go out by itself.
        [$first, $second] = $this->building(2);
        $guest = User::factory()->create();

        $this->actingAs($guest, 'sanctum')
            ->postJson("/api/v1/user/favorites/{$first->id}")->assertNoContent();

        $this->actingAs($guest, 'sanctum')->getJson('/api/v1/user/favorites')
            ->assertOk()->assertJsonCount(1);

        $first->forceFill(['status' => 'unavailable'])->saveQuietly();

        $this->actingAs($guest, 'sanctum')->getJson('/api/v1/user/favorites')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $second->id)
            ->assertJsonPath('0.listing_id', $first->unit_group_id);
    }

    public function test_a_favourite_whose_whole_building_closed_drops_out(): void
    {
        [$first, $second] = $this->building(2);
        $guest = User::factory()->create();

        $this->actingAs($guest, 'sanctum')
            ->postJson("/api/v1/user/favorites/{$first->id}")->assertNoContent();

        Unit::whereIn('id', [$first->id, $second->id])->update(['status' => 'unavailable']);

        $this->actingAs($guest, 'sanctum')->getJson('/api/v1/user/favorites')
            ->assertOk()->assertJsonCount(0);
    }

    public function test_a_building_can_be_favourited_by_its_listing_key(): void
    {
        [$first] = $this->building(2);
        $guest = User::factory()->create();

        $this->actingAs($guest, 'sanctum')
            ->postJson("/api/v1/user/favorites/{$first->unit_group_id}")->assertNoContent();

        $this->actingAs($guest, 'sanctum')->getJson('/api/v1/user/favorites')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.listing_id', $first->unit_group_id);
    }

    public function test_a_listing_code_still_opens_the_page(): void
    {
        // The partner dashboard published /units/{code} from 14/07 to 29/09 and
        // the API never resolved a code, so every link a partner copied and
        // shared was dead. Accepting it repairs those without a redirect table.
        $unit = $this->unit();

        $this->getJson("/api/v1/units/{$unit->code}")
            ->assertOk()
            ->assertJsonPath('data.id', $unit->id)
            ->assertJsonPath('data.listing_id', 'u'.$unit->id);
    }

    public function test_a_code_inside_a_building_opens_the_building(): void
    {
        // A shared link naming one door should land on the card, like every
        // other way of addressing a listing here.
        [$first, $second] = $this->building(2);

        $this->getJson("/api/v1/units/{$second->code}")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.listing_id', $first->unit_group_id);
    }

    public function test_the_partner_dashboard_publishes_the_key_not_the_code(): void
    {
        [$first, $second] = $this->building(2);

        $payload = UnitPresenter::make($second);

        $this->assertStringEndsWith('/units/'.$first->unit_group_id, (string) $payload['publicUrl']);
        $this->assertStringNotContainsString($second->code, (string) $payload['publicUrl']);

        $solo = $this->unit();
        $this->assertStringEndsWith('/units/u'.$solo->id, (string) UnitPresenter::make($solo)['publicUrl']);
    }

    /* ---------- fixtures ---------- */

    /** @return array<int, Unit> */
    private function building(int $doors): array
    {
        $first = $this->unit();
        $group = (string) str()->ulid();
        $first->forceFill(['unit_group_id' => $group, 'apartment_no' => '1'])->saveQuietly();

        $units = [$first->fresh()];

        for ($i = 2; $i <= $doors; $i++) {
            $units[] = $first->owner->units()->create([
                'unit_name' => 'برج — '.$i, 'unit_type' => 'apartment',
                'code' => 'MRN'.fake()->unique()->numerify('#####'),
                'price' => 500, 'capacity' => 4, 'bedrooms' => 1,
                'approval_status' => 'approved', 'status' => 'available',
                'calendar_token' => str()->random(60),
                'unit_group_id' => $group, 'apartment_no' => (string) $i,
            ])->fresh();
        }

        return $units;
    }

    private function unit(): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name' => 'وحدة رابط', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 4, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ])->fresh();
    }
}
