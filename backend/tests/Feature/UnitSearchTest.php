<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `GET /units` — the storefront's search.
 *
 * Three things were accepted and silently ignored: the availability window, the
 * sort key, and the page size. A parameter that is accepted and ignored is
 * worse than one that is rejected, because the client builds a promise on it —
 * the site was labelling results "available for your stay" while the endpoint
 * had never looked at the dates.
 */
class UnitSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    /* ---------- availability window ---------- */

    public function test_a_unit_booked_for_the_window_is_not_listed(): void
    {
        $free  = $this->unit('حرة');
        $taken = $this->unit('محجوزة');
        $this->booking($taken, 10, 15);

        $ids = $this->search(['start_date' => $this->day(11), 'end_date' => $this->day(13)]);

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($taken->id, $ids);
    }

    public function test_the_changeover_day_does_not_hide_a_unit(): void
    {
        // Same rule the booking endpoint enforces: a stay ending the 15th
        // leaves the 15th free, so the search must still offer it.
        $unit = $this->unit();
        $this->booking($unit, 10, 15);

        $this->assertContains($unit->id, $this->search([
            'start_date' => $this->day(15), 'end_date' => $this->day(18),
        ]));
    }

    public function test_a_partner_closure_also_hides_a_unit(): void
    {
        $unit = $this->unit();
        $unit->blockedDates()->create([
            'start_date' => $this->day(10), 'end_date' => $this->day(15), 'source' => 'manual',
        ]);

        $this->assertNotContains($unit->id, $this->search([
            'start_date' => $this->day(11), 'end_date' => $this->day(13),
        ]));
    }

    public function test_a_cancelled_booking_does_not_hide_a_unit(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CANCELLED);

        $this->assertContains($unit->id, $this->search([
            'start_date' => $this->day(11), 'end_date' => $this->day(13),
        ]));
    }

    public function test_dates_must_be_sent_as_a_pair(): void
    {
        // Half a window is a client bug; answering it with an unfiltered list
        // is how "available for your stay" ends up over the wrong results.
        $this->getJson('/api/v1/units?start_date='.$this->day(5))->assertStatus(422);
        $this->getJson('/api/v1/units?end_date='.$this->day(5))->assertStatus(422);
    }

    /* ---------- city ---------- */

    public function test_the_city_filter_accepts_every_spelling(): void
    {
        $this->unit(city: 'الرياض');
        $this->unit(city: 'جدة');

        foreach (['الرياض', 'riyadh', 'Riyadh'] as $spelling) {
            $this->assertCount(1, $this->search(['city' => $spelling]), "failed for {$spelling}");
        }
    }

    public function test_an_unknown_city_returns_nothing_rather_than_everything(): void
    {
        $this->unit(city: 'الرياض');

        $this->assertSame([], $this->search(['city' => 'atlantis']));
    }

    /* ---------- sort ---------- */

    public function test_price_sorts_both_ways(): void
    {
        $cheap = $this->unit(price: 100);
        $mid   = $this->unit(price: 500);
        $dear  = $this->unit(price: 900);

        $this->assertSame([$cheap->id, $mid->id, $dear->id], $this->search(['sort' => 'price_asc']));
        $this->assertSame([$dear->id, $mid->id, $cheap->id], $this->search(['sort' => 'price_desc']));
    }

    public function test_an_unknown_sort_key_falls_back_instead_of_failing(): void
    {
        $this->unit();
        $this->unit();

        $this->assertCount(2, $this->search(['sort' => 'whatever']));
    }

    public function test_the_order_is_deterministic_across_identical_units(): void
    {
        // Every unit here has the same price and no reviews, so only the
        // tiebreaker separates them. Without one the database may order equal
        // rows differently per query, which makes paging lose and repeat rows.
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->unit(price: 300)->id;
        }

        $first = $this->search(['sort' => 'price_asc']);

        for ($run = 0; $run < 3; $run++) {
            $this->assertSame($first, $this->search(['sort' => 'price_asc']));
        }

        $this->assertSame($ids, $first);
    }

    /* ---------- pagination ---------- */

    public function test_page_size_is_caller_controlled_and_capped(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->unit();
        }

        $this->assertCount(3, $this->search(['per_page' => 3]));
        $this->assertCount(8, $this->search(['per_page' => 999]), 'the cap must not shrink a small set');
    }

    public function test_paging_never_repeats_or_drops_a_unit(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->unit(price: 250);
        }

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            $seen = array_merge($seen, $this->search(['per_page' => 2, 'page' => $page]));
        }

        $this->assertCount(7, $seen);
        $this->assertSame($seen, array_unique($seen), 'a unit appeared on two pages');
    }

    public function test_the_meta_block_keeps_its_shape(): void
    {
        $this->unit();

        $this->getJson('/api/v1/units?per_page=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    /* ---------- resource fields ---------- */

    public function test_the_list_carries_created_at_and_the_owner(): void
    {
        $this->unit();

        $row = $this->getJson('/api/v1/units')->assertOk()->json('data.0');

        $this->assertNotNull($row['created_at'], 'newest sort is impossible without it');
        $this->assertNotNull($row['owner'] ?? null, 'cards showed a blank host name without it');
        $this->assertArrayHasKey('is_verified', $row['owner']);
    }

    /* ---------- helpers ---------- */

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    /** @param array<string, mixed> $params @return list<int> */
    private function search(array $params): array
    {
        return $this->getJson('/api/v1/units?'.http_build_query($params))
            ->assertOk()
            ->json('data.*.id');
    }

    private function unit(string $name = 'وحدة', float $price = 400, string $city = 'الرياض'): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name'       => $name,
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => $price,
            'capacity'        => 2,
            'bedrooms'        => 1,
            'city'            => $city,
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);
    }

    private function booking(Unit $unit, int $from, int $to, string $status = Booking::STATUS_CONFIRMED): Booking
    {
        return Booking::create([
            'unit_id'      => $unit->id,
            'user_id'      => User::factory()->create()->id,
            'start_date'   => $this->day($from),
            'end_date'     => $this->day($to),
            'guests'       => 2,
            'status'       => $status,
            'total_amount' => 100,
        ]);
    }
}
