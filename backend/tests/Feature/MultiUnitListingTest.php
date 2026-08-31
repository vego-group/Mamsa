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

    public function test_one_booked_apartment_does_not_grey_out_the_building(): void
    {
        // The bug as reported: booking apartment 401 for 31 Aug → 3 Sep greyed
        // those nights out in the picker, while four apartments were free and
        // the booking endpoint would have accepted them.
        $source = $this->building(5);
        $this->book($source, '2026-08-31', '2026-09-03');

        $blocked = $this->getJson("/api/v1/units/{$source->id}/blocked-dates?from=2026-08-25&to=2026-09-10")
            ->assertOk()->json('blocked');

        $this->assertSame([], $blocked, 'four apartments are free — nothing is closed');
    }

    public function test_the_building_closes_only_when_every_apartment_is_taken(): void
    {
        $source = $this->building(3);

        foreach (Unit::where('unit_group_id', $source->unit_group_id)->get() as $u) {
            $this->book($u, '2026-08-31', '2026-09-03');
        }

        $blocked = $this->getJson("/api/v1/units/{$source->id}/blocked-dates?from=2026-08-25&to=2026-09-10")
            ->assertOk()->json('blocked');

        // Nights of the 31st, 1st and 2nd. The 3rd is a checkout morning and
        // must stay selectable.
        $this->assertSame([['start' => '2026-08-31', 'end' => '2026-09-02']], $blocked);
    }

    public function test_the_availability_probe_counts_the_whole_building(): void
    {
        $source = $this->building(5);
        $this->book($source, '2026-08-31', '2026-09-03');

        $body = $this->postJson("/api/v1/units/{$source->id}/availability", [
            'start_date' => '2026-08-31', 'end_date' => '2026-09-03',
        ])->assertOk()->json();

        // A probe that said "unavailable" here would contradict the create
        // endpoint, which would happily allocate one of the other four.
        $this->assertTrue($body['available']);
        $this->assertSame(4, $body['available_count']);
    }

    private function book(Unit $unit, string $start, string $end): void
    {
        Booking::create([
            'unit_id' => $unit->id, 'user_id' => User::factory()->create()->id,
            'start_date' => $start, 'end_date' => $end,
            'guests' => 2, 'subtotal' => 1000, 'commission_rate' => 0.10,
            'commission_amount' => 100, 'partner_share' => 900,
            'total_amount' => 1150, 'status' => Booking::STATUS_CONFIRMED,
        ]);
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

    public function test_the_count_is_a_total_not_an_increment(): void
    {
        // The bug a partner hit within minutes: a building already numbered
        // 401-405, told "5", became TEN — because 1..5 all looked missing.
        $unit = $this->listing();
        UnitCloner::assign($unit, ['401', '402', '403', '404', '405']);

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", ['count' => 5])
            ->assertStatus(201);

        $this->assertSame(5, Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->count());
    }

    public function test_growing_a_building_continues_its_own_numbering(): void
    {
        $unit = $this->listing();
        UnitCloner::assign($unit, ['401', '402']);

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", ['count' => 4])
            ->assertStatus(201);

        // 403 and 404 — not 1 and 2. A door number the partner chose carries
        // meaning a generated sequence would talk over.
        $this->assertSame(
            ['401', '402', '403', '404'],
            Unit::where('unit_group_id', $unit->fresh()->unit_group_id)
                ->pluck('apartment_no')->sort()->values()->all()
        );
    }

    public function test_a_smaller_count_never_deletes_an_apartment(): void
    {
        $unit = $this->listing();
        UnitCloner::assign($unit, ['401', '402', '403']);

        $this->actingAs($this->partner, 'sanctum')
            ->postJson("/api/v1/partner/units/{$unit->id}/apartments", ['count' => 1])
            ->assertStatus(201);

        // An apartment may already hold a booking. Shrinking silently would
        // cancel a stay nobody asked to cancel.
        $this->assertSame(3, Unit::where('unit_group_id', $unit->fresh()->unit_group_id)->count());
    }
}
