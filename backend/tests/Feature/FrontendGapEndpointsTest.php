<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Review;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The endpoints and fields the storefront was working around.
 *
 * Each of these had the client faking something: filtering a single page for
 * favourites, inventing a guest name, or simply never showing a review back to
 * the person who wrote it.
 */
class FrontendGapEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        config()->set('booking.require_verified_email', false);
    }

    /* ---------- GET /units?ids[]= ---------- */

    public function test_units_can_be_fetched_by_a_list_of_ids(): void
    {
        $wanted = [$this->unit()->id, $this->unit()->id];
        $this->unit();

        $got = $this->getJson('/api/v1/units?'.http_build_query(['ids' => $wanted]))
            ->assertOk()->json('data.*.id');

        sort($got);
        sort($wanted);
        $this->assertSame($wanted, $got);
    }

    public function test_an_id_list_still_hides_units_that_are_not_public(): void
    {
        // A favourite whose unit was later unpublished must not reappear just
        // because the client asked for it by id.
        $public = $this->unit();
        $hidden = $this->unit();
        $hidden->update(['approval_status' => 'rejected']);

        $this->assertSame(
            [$public->id],
            $this->getJson('/api/v1/units?'.http_build_query(['ids' => [$public->id, $hidden->id]]))
                ->assertOk()->json('data.*.id'),
        );
    }

    public function test_an_over_long_id_list_is_refused(): void
    {
        $this->getJson('/api/v1/units?'.http_build_query(['ids' => range(1, 51)]))->assertStatus(422);
    }

    /* ---------- GET /units/sitemap ---------- */

    public function test_the_sitemap_lists_every_public_unit_without_paging(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->unit();
        }
        $this->unit()->update(['approval_status' => 'draft']);

        $rows = $this->getJson('/api/v1/units/sitemap')->assertOk()->json();

        // 15 public, and NOT capped at the 12 the paginated list would return.
        $this->assertCount(15, $rows);
        $this->assertSame(['id', 'updated_at'], array_keys($rows[0]));
        $this->assertIsInt($rows[0]['id']);
    }

    public function test_the_sitemap_needs_no_authentication(): void
    {
        $this->getJson('/api/v1/units/sitemap')->assertOk();
    }

    /* ---------- GET /bookings/{id}/review ---------- */

    public function test_a_guest_can_read_back_their_own_review(): void
    {
        [$guest, $booking] = $this->bookingFor();
        $review = Review::create([
            'booking_id' => $booking->id, 'user_id' => $guest->id, 'unit_id' => $booking->unit_id,
            'rating' => 5, 'comment' => 'ممتاز',
        ]);

        $body = $this->actingAs($guest, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/review")
            ->assertOk()->json();

        $this->assertSame($review->id, $body['id']);
        $this->assertSame(5, $body['rating']);
        $this->assertSame('ممتاز', $body['comment']);
        $this->assertSame($guest->name, $body['user_name']);
        $this->assertNull($body['user_avatar_url']);
        $this->assertNotNull($body['created_at']);
    }

    public function test_an_unreviewed_booking_answers_null_rather_than_404(): void
    {
        // Not having reviewed yet is an ordinary state, not an error.
        [$guest, $booking] = $this->bookingFor();

        $this->actingAs($guest, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/review")
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_a_stranger_cannot_read_someone_elses_review(): void
    {
        [$guest, $booking] = $this->bookingFor();
        Review::create([
            'booking_id' => $booking->id, 'user_id' => $guest->id, 'unit_id' => $booking->unit_id,
            'rating' => 4, 'comment' => 'x',
        ]);

        $this->actingAs($this->guest(), 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/review")
            ->assertStatus(403);
    }

    public function test_the_partner_who_owns_the_unit_can_read_it(): void
    {
        [$guest, $booking] = $this->bookingFor();
        Review::create([
            'booking_id' => $booking->id, 'user_id' => $guest->id, 'unit_id' => $booking->unit_id,
            'rating' => 3, 'comment' => 'ok',
        ]);

        $this->actingAs($booking->unit->owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/review")
            ->assertOk()
            ->assertJsonPath('rating', 3);
    }

    /* ---------- booking resource fields ---------- */

    public function test_the_booking_carries_the_guest_name_and_the_adults_children_split(): void
    {
        [$guest, $booking] = $this->bookingFor(guests: 4, children: 2);

        $body = $this->actingAs($guest, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}")
            ->assertOk()->json('data');

        $this->assertSame($guest->id, $body['user_id']);
        $this->assertSame($guest->name, $body['guest_name'], 'was absent unless the user relation happened to be loaded');
        $this->assertSame(4, $body['guests'], 'the total stays a number — other consoles read it');
        $this->assertSame(['adults' => 2, 'children' => 2], $body['guests_detail']);
    }

    /* ---------- helpers ---------- */

    private function guest(): User
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        return $user;
    }

    private function unit(): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name'       => 'وحدة',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 400,
            'capacity'        => 4,
            'bedrooms'        => 1,
            'city'            => 'الرياض',
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);
    }

    /** @return array{0: User, 1: Booking} */
    private function bookingFor(int $guests = 2, int $children = 0): array
    {
        $guest = $this->guest();
        $unit  = $this->unit();

        $booking = Booking::create([
            'unit_id'      => $unit->id,
            'user_id'      => $guest->id,
            'start_date'   => now()->addDays(10)->toDateString(),
            'end_date'     => now()->addDays(12)->toDateString(),
            'guests'       => $guests,
            'children'     => $children,
            'status'       => Booking::STATUS_CONFIRMED,
            'total_amount' => 800,
        ]);

        return [$guest, $booking->load('unit')];
    }
}
