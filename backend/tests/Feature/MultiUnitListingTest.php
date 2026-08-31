<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Support\Units\UnitCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A building of identical apartments is ONE card with a count beside it.
 *
 * The collapse is the easy half. The half that breaks is booking: the id on the
 * card is whichever apartment the listing happened to show, so booking it
 * literally would fail while its siblings sat empty — and two guests clicking
 * the same card would collide on one row.
 */
class MultiUnitListingTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
    }

    private function listing(string $name = 'برج الملقا'): Unit
    {
        $unit = $this->partner->units()->create([
            'unit_name' => $name, 'unit_type' => 'apartment',
            'code' => 'GRP'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1, 'beds' => 2,
            'bathrooms' => 1, 'city' => 'الرياض', 'address' => 'حي الملقا',
            'lat' => 24.7136, 'lng' => 46.6753,
            'description' => str_repeat('وصف كافٍ. ', 5),
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
        $unit->images()->create(['path' => 'units/g/p.jpg', 'is_main' => true]);

        return $unit->fresh();
    }

    /** Clones start as drafts; a listed building needs them approved. */
    private function building(int $count): Unit
    {
        $source = $this->listing();
        UnitCloner::assign($source, range(1, $count));
        Unit::where('unit_group_id', $source->fresh()->unit_group_id)
            ->update(['approval_status' => 'approved', 'status' => 'available']);

        return $source->fresh();
    }

    public function test_a_building_is_one_card_carrying_its_count(): void
    {
        $this->building(5);
        $this->listing('فيلا مستقلة');   // a standalone listing alongside it

        $body = $this->getJson('/api/v1/units')->assertOk()->json();
        $rows = $body['data'] ?? $body;

        // Six units exist; two cards.
        $this->assertSame(6, Unit::where('approval_status', 'approved')->count());
        $this->assertCount(2, $rows);

        $counts = collect($rows)->pluck('available_count', 'name');
        $this->assertSame(5, $counts['برج الملقا']);
        // A standalone listing is a building of one — never null, or a client
        // would have to special-case every existing unit.
        $this->assertSame(1, $counts['فيلا مستقلة']);
    }

    public function test_the_count_reflects_the_dates_being_searched(): void
    {
        $source = $this->building(3);
        $sibling = Unit::where('unit_group_id', $source->unit_group_id)->orderBy('id')->first();

        Booking::create([
            'unit_id' => $sibling->id, 'user_id' => User::factory()->create()->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-05',
            'guests' => 2, 'subtotal' => 1000, 'commission_rate' => 0.10,
            'commission_amount' => 100, 'partner_share' => 900,
            'total_amount' => 1150, 'status' => Booking::STATUS_CONFIRMED,
        ]);

        $rows = $this->getJson('/api/v1/units?start_date=2026-10-02&end_date=2026-10-04')
            ->assertOk()->json('data');

        // "3 available" for nights when only 2 are free is a number the booking
        // step then contradicts.
        $this->assertSame(2, $rows[0]['available_count']);
    }

    public function test_booking_the_card_allocates_a_free_apartment(): void
    {
        $source = $this->building(3);
        $guest  = User::factory()->create(['is_active' => true]);
        $guest->assignRole('User');

        // Occupy the apartment the card shows, so a literal booking would fail.
        Booking::create([
            'unit_id' => $source->id, 'user_id' => User::factory()->create()->id,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-10',
            'guests' => 2, 'subtotal' => 1000, 'commission_rate' => 0.10,
            'commission_amount' => 100, 'partner_share' => 900,
            'total_amount' => 1150, 'status' => Booking::STATUS_CONFIRMED,
        ]);

        $body = $this->actingAs($guest, 'sanctum')->postJson('/api/v1/bookings', [
            'unit_id' => $source->id,
            'start_date' => '2026-11-02', 'end_date' => '2026-11-05', 'guests' => 2,
        ])->assertStatus(201)->json();

        $allocated = (int) ($body['data']['unit']['id'] ?? $body['unit']['id'] ?? 0);

        $this->assertNotSame($source->id, $allocated, 'should not book the occupied apartment');
        $this->assertSame($source->unit_group_id, Unit::find($allocated)->unit_group_id);
    }

    public function test_a_fully_booked_building_is_refused(): void
    {
        $source = $this->building(2);
        $guest  = User::factory()->create(['is_active' => true]);
        $guest->assignRole('User');

        foreach (Unit::where('unit_group_id', $source->unit_group_id)->get() as $u) {
            Booking::create([
                'unit_id' => $u->id, 'user_id' => User::factory()->create()->id,
                'start_date' => '2026-12-01', 'end_date' => '2026-12-10',
                'guests' => 2, 'subtotal' => 1000, 'commission_rate' => 0.10,
                'commission_amount' => 100, 'partner_share' => 900,
                'total_amount' => 1150, 'status' => Booking::STATUS_CONFIRMED,
            ]);
        }

        $this->actingAs($guest, 'sanctum')->postJson('/api/v1/bookings', [
            'unit_id' => $source->id,
            'start_date' => '2026-12-02', 'end_date' => '2026-12-05', 'guests' => 2,
        ])->assertStatus(422);
    }

    public function test_two_guests_on_one_card_get_different_apartments(): void
    {
        $source = $this->building(2);

        $ids = [];
        foreach (range(1, 2) as $i) {
            $guest = User::factory()->create(['is_active' => true]);
            $guest->assignRole('User');

            $body = $this->actingAs($guest, 'sanctum')->postJson('/api/v1/bookings', [
                'unit_id' => $source->id,
                'start_date' => '2027-01-02', 'end_date' => '2027-01-05', 'guests' => 2,
            ])->assertStatus(201)->json();

            $ids[] = (int) ($body['data']['unit']['id'] ?? $body['unit']['id'] ?? 0);
        }

        // The whole point of real rows over a counter: two guests, two doors.
        $this->assertCount(2, array_unique($ids));
    }

    public function test_the_partner_can_ask_for_a_plain_count(): void
    {
        $unit = $this->listing();

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", ['count' => 4])
            ->assertStatus(201);

        $group = Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->get();

        $this->assertCount(4, $group);
        $this->assertSame(['1', '2', '3', '4'], $group->pluck('apartment_no')->sort()->values()->all());
    }

    public function test_raising_the_count_adds_only_the_difference(): void
    {
        $unit = $this->listing();

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", ['count' => 3])->assertStatus(201);
        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", ['count' => 5])->assertStatus(201);

        $this->assertSame(5, Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->count());
    }
}
