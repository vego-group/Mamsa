<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\UnitBlockedDate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Double-booking protection, and the calendar feed that stops a guest finding
 * out about a clash on the last screen.
 *
 * The three surfaces — the probe, the create, and the calendar — must agree,
 * because a probe that says yes where the create says no is experienced as the
 * site losing a booking after the guest has filled in their details.
 */
class BookingAvailabilityTest extends TestCase
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

    /* ---------- the probe ---------- */

    public function test_a_confirmed_booking_makes_the_dates_unavailable(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->probe($unit, 12, 14)->assertOk()->assertJsonPath('available', false);
    }

    public function test_an_unpaid_booking_still_holds_the_dates(): void
    {
        // A guest partway through checkout has a claim on the nights, or two
        // people pay for the same room.
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_PENDING);

        $this->probe($unit, 12, 14)->assertOk()->assertJsonPath('available', false);
    }

    public function test_a_cancelled_booking_releases_the_dates(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CANCELLED);

        $this->probe($unit, 12, 14)->assertOk()->assertJsonPath('available', true);
    }

    public function test_a_partner_closure_makes_the_dates_unavailable(): void
    {
        $unit = $this->unit();
        $this->block($unit, 10, 15);

        $this->probe($unit, 12, 14)->assertOk()->assertJsonPath('available', false);
    }

    /* ---------- the create re-checks for itself ---------- */

    public function test_creating_a_booking_is_refused_when_the_dates_are_taken(): void
    {
        // No probe first: the create must not assume the client ran one, or
        // never running it would be a way past the check.
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 12, 14))
            // 409 with a CODE, not 422 with a sentence: the request was fine,
            // the nights were not. Asserting the code rather than the Arabic
            // means rewording the message cannot silently change the contract.
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_INVENTORY')
            ->assertJsonPath('meta.available_count', 0);
    }

    public function test_a_partner_closure_also_blocks_the_create(): void
    {
        $unit = $this->unit();
        $this->block($unit, 10, 15);

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 12, 14))
            // A closure and a clash are different answers, and the client is
            // told which by the code — they used to differ only in prose.
            ->assertStatus(409)
            ->assertJsonPath('code', 'UNIT_BLOCKED');
    }

    public function test_the_second_of_two_bookings_for_the_same_nights_loses(): void
    {
        // Sequential here — the lock is what makes this hold when the two
        // arrive together, and that is proven against a real server rather
        // than in-process, where one connection cannot race itself.
        $unit = $this->unit();

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 10, 15))
            ->assertStatus(201);

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 12, 14))
            ->assertStatus(409);

        $this->assertSame(1, Booking::where('unit_id', $unit->id)->count());
    }

    public function test_a_failed_booking_leaves_nothing_behind(): void
    {
        // The check runs inside the transaction, so a refusal must not commit
        // a half-written row.
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 12, 14))
            ->assertStatus(409);

        $this->assertSame(1, Booking::where('unit_id', $unit->id)->count());
    }

    public function test_a_different_unit_is_unaffected(): void
    {
        $taken = $this->unit();
        $free = $this->unit();
        $this->booking($taken, 10, 15, Booking::STATUS_CONFIRMED);

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($free, 12, 14))
            ->assertStatus(201);
    }

    /* ---------- current boundary behaviour, pinned ---------- */

    public function test_a_changeover_day_belongs_to_the_arriving_guest(): void
    {
        // A stay occupies nights, not calendar squares: 10→15 uses the nights
        // of the 10th to the 14th and the room is free on the 15th. Both
        // directions must agree — the predicate this replaced refused an
        // arrival on the 15th while allowing a departure on the 10th, which is
        // the same situation answered two ways.
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->probe($unit, 15, 18)->assertOk()->assertJsonPath('available', true);
        $this->probe($unit, 7, 10)->assertOk()->assertJsonPath('available', true);
    }

    public function test_a_changeover_booking_is_actually_creatable(): void
    {
        // The probe saying yes is worth nothing if the create still refuses.
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 15, 18))
            ->assertStatus(201);
    }

    public function test_one_night_of_overlap_is_still_a_clash(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->probe($unit, 14, 18)->assertOk()->assertJsonPath('available', false);
        $this->probe($unit, 7, 11)->assertOk()->assertJsonPath('available', false);
    }

    public function test_a_clear_gap_is_bookable(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->probe($unit, 16, 18)->assertOk()->assertJsonPath('available', true);
    }

    /* ---------- the calendar feed ---------- */

    public function test_blocked_dates_lists_bookings_and_closures_together(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);
        $this->block($unit, 40, 42);

        $blocked = $this->getJson("/api/v1/units/{$unit->id}/blocked-dates")
            ->assertOk()
            ->json('blocked');

        // Nights, not calendar squares: the 10→15 stay frees the 15th.
        $this->assertSame([
            ['start' => $this->day(10), 'end' => $this->day(14)],
            ['start' => $this->day(40), 'end' => $this->day(41)],
        ], $blocked);
    }

    public function test_touching_ranges_are_merged_into_one(): void
    {
        // Two closures that meet are one unbroken span on a calendar; returning
        // them separately would draw a selectable gap that isn't real.
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);
        $this->booking($unit, 15, 20, Booking::STATUS_CONFIRMED);

        $blocked = $this->getJson("/api/v1/units/{$unit->id}/blocked-dates")->assertOk()->json('blocked');

        $this->assertSame([['start' => $this->day(10), 'end' => $this->day(19)]], $blocked);
    }

    public function test_a_cancelled_booking_is_not_listed(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CANCELLED);

        $this->assertSame([], $this->getJson("/api/v1/units/{$unit->id}/blocked-dates")->assertOk()->json('blocked'));
    }

    public function test_ranges_are_clipped_to_the_window(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 40, Booking::STATUS_CONFIRMED);

        $blocked = $this->getJson(
            "/api/v1/units/{$unit->id}/blocked-dates?from={$this->day(20)}&to={$this->day(30)}"
        )->assertOk()->json('blocked');

        $this->assertSame([['start' => $this->day(20), 'end' => $this->day(30)]], $blocked);

        // And a date the calendar leaves open must really be bookable.
        $this->probe($unit, 40, 43)->assertOk()->assertJsonPath('available', true);
    }

    public function test_the_feed_needs_no_authentication(): void
    {
        // A guest browsing a unit page has no token yet.
        $this->getJson('/api/v1/units/'.$this->unit()->id.'/blocked-dates')->assertOk();
    }

    /* ---------- helpers ---------- */

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    /* ---------- the hold, and what it releases ---------- */

    public function test_an_expired_hold_stops_blocking_before_any_job_runs(): void
    {
        // The point of the column: no cleanup command is invoked here. If the
        // nights are still held, availability is waiting on a job to notice,
        // which is up to 75 minutes of a unit nobody can book.
        $unit = $this->unit();
        $booking = $this->booking($unit, 10, 15, Booking::STATUS_PENDING);
        $booking->forceFill(['hold_expires_at' => now()->subMinute()])->save();

        $this->probe($unit, 12, 14)->assertOk()->assertJsonPath('available', true);

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 12, 14))
            ->assertStatus(201);
    }

    public function test_a_live_hold_still_blocks(): void
    {
        $unit = $this->unit();
        $booking = $this->booking($unit, 10, 15, Booking::STATUS_PENDING);
        $booking->forceFill(['hold_expires_at' => now()->addMinutes(5)])->save();

        $this->probe($unit, 12, 14)->assertOk()->assertJsonPath('available', false);
    }

    public function test_a_booking_with_no_hold_keeps_blocking(): void
    {
        // Every booking made before the column existed has a null hold.
        // Treating null as expired would have released live checkouts on deploy.
        $unit = $this->unit();
        $booking = $this->booking($unit, 10, 15, Booking::STATUS_PENDING);
        $booking->forceFill(['hold_expires_at' => null])->save();

        $this->probe($unit, 12, 14)->assertOk()->assertJsonPath('available', false);
    }

    public function test_a_new_booking_is_stamped_with_a_hold(): void
    {
        config()->set('booking.hold_minutes', 60);

        $unit = $this->unit();

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 10, 15))
            ->assertStatus(201);

        $booking = Booking::where('unit_id', $unit->id)->firstOrFail();

        $this->assertNotNull($booking->hold_expires_at);
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($booking->hold_expires_at), 1);
        $this->assertSame(1, (int) $booking->units_count);
    }

    /* ---------- idempotency ---------- */

    public function test_the_same_idempotency_key_returns_the_first_booking(): void
    {
        $unit = $this->unit();
        $guest = $this->guest();
        $key = 'idem-'.str()->random(12);

        $first = $this->actingAs($guest, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/bookings', $this->body($unit, 10, 15))
            ->assertStatus(201);

        // Without the guard this is a 409 — the first booking now holds the
        // nights against the replay, which reads to the guest as the site
        // refusing a booking it just made for them.
        $this->actingAs($guest, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/bookings', $this->body($unit, 10, 15))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Booking::where('unit_id', $unit->id)->count());
    }

    public function test_another_guests_key_is_not_borrowed(): void
    {
        $unit = $this->unit();
        $key = 'shared-key';

        $this->actingAs($this->guest(), 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/bookings', $this->body($unit, 10, 15))
            ->assertStatus(201);

        // Same key, different guest: this must NOT hand over someone else's
        // booking. The scoped lookup misses, the unique index catches it, and
        // the caller is told plainly rather than getting a server error.
        $this->actingAs($this->guest(), 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/bookings', $this->body($unit, 20, 25))
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    /* ---------- units_count is present but shut ---------- */

    public function test_asking_for_more_than_one_unit_is_refused(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->body($unit, 10, 15) + ['units_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MULTI_UNIT_BOOKING_NOT_SUPPORTED');
    }

    /* ---------- available_count agrees with the probe ---------- */

    public function test_a_booked_standalone_unit_does_not_advertise_a_free_apartment(): void
    {
        // GET /units/{id} applies no availability filter of its own, so it used
        // to report `available_count: 1` for a unit the create endpoint would
        // refuse — the detail page offering a stay checkout then denies.
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        $this->getJson("/api/v1/units/{$unit->id}?start_date={$this->day(12)}&end_date={$this->day(14)}")
            ->assertOk()
            ->assertJsonPath('data.available_count', 0);

        $this->probe($unit, 12, 14)->assertJsonPath('available_count', 0);
    }

    public function test_a_free_standalone_unit_still_reports_one(): void
    {
        $unit = $this->unit();

        $this->getJson("/api/v1/units/{$unit->id}?start_date={$this->day(12)}&end_date={$this->day(14)}")
            ->assertOk()
            ->assertJsonPath('data.available_count', 1);
    }

    public function test_without_dates_a_listed_unit_reports_one(): void
    {
        $unit = $this->unit();
        $this->booking($unit, 10, 15, Booking::STATUS_CONFIRMED);

        // No window means nothing to be taken FOR. The count answers "is this
        // listed", which is what a card with no dates chosen can honestly say.
        $this->getJson("/api/v1/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.available_count', 1);
    }

    private function unit(): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name' => 'وحدة توفر',
            'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500,
            'capacity' => 4,
            'bedrooms' => 1,
            'approval_status' => 'approved',
            'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }

    private function guest(): User
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        return $user;
    }

    private function booking(Unit $unit, int $from, int $to, string $status): Booking
    {
        return Booking::create([
            'unit_id' => $unit->id,
            'user_id' => $this->guest()->id,
            'start_date' => $this->day($from),
            'end_date' => $this->day($to),
            'guests' => 2,
            'status' => $status,
            'total_amount' => 100,
        ]);
    }

    private function block(Unit $unit, int $from, int $to): UnitBlockedDate
    {
        return $unit->blockedDates()->create([
            'start_date' => $this->day($from),
            'end_date' => $this->day($to),
            'source' => UnitBlockedDate::SOURCE_MANUAL,
        ]);
    }

    private function probe(Unit $unit, int $from, int $to): TestResponse
    {
        return $this->postJson("/api/v1/units/{$unit->id}/availability", [
            'start_date' => $this->day($from),
            'end_date' => $this->day($to),
        ]);
    }

    /** @return array<string, mixed> */
    private function body(Unit $unit, int $from, int $to): array
    {
        return [
            'unit_id' => $unit->id,
            'start_date' => $this->day($from),
            'end_date' => $this->day($to),
            'guests' => 2,
        ];
    }
}
