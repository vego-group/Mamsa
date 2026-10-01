<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What `POST /api/v1/payments/initiate` says about the unit being paid for.
 *
 * In a building the server allocates a door, so the unit on the payment screen
 * is not always the listing the guest tapped. The response used to carry four
 * descriptive fields — name, city, district, image — and no identity at all,
 * so the screen could name the building but not say which apartment it was
 * charging for, and the client could not tie the payment back to the card the
 * guest came from.
 */
class PaymentInitiateUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        config()->set('booking.require_verified_email', false);
    }

    public function test_it_names_the_allocated_apartment_and_the_building_it_belongs_to(): void
    {
        [$card, $second] = $this->building();

        // The first booking takes the card's own door; the second must be given
        // the other one. That is the case the payment screen gets wrong.
        $this->book($card, 10, 12)->assertStatus(201);
        $booking = $this->book($card, 10, 12)->assertStatus(201)->json('data.id')
            ?? Booking::latest('id')->value('id');

        $unit = $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/payments/initiate', ['booking_id' => $booking])
            ->assertOk()
            ->json('data.booking.unit') ?? [];

        $this->assertArrayHasKey('id', $unit);
        $this->assertArrayHasKey('listing_id', $unit);
        $this->assertArrayHasKey('apartment_no', $unit);

        $this->assertSame($second->id, $unit['id'], 'the payment screen named the card, not the allocated door');
        $this->assertSame($card->unit_group_id, $unit['listing_id'], 'listing_id must be the building, shared by every door');
        $this->assertSame($second->apartment_no, $unit['apartment_no']);

        // And the descriptive fields are still there — this is purely additive.
        foreach (['name', 'city', 'district', 'image_url'] as $key) {
            $this->assertArrayHasKey($key, $unit);
        }
    }

    public function test_a_standalone_listing_reports_no_apartment_and_a_u_prefixed_listing_id(): void
    {
        $unit = $this->unit();

        $booking = $this->book($unit, 20, 22)->assertStatus(201)->json('data.id')
            ?? Booking::latest('id')->value('id');

        $payload = $this->actingAs($this->guest(), 'sanctum')
            ->postJson('/api/v1/payments/initiate', ['booking_id' => $booking])
            ->assertOk()
            ->json('data.booking.unit');

        $this->assertSame($unit->id, $payload['id']);
        $this->assertSame('u'.$unit->id, $payload['listing_id'], 'a standalone listing identifies itself as u<id>');
        // Present and null — so a client reads the key without branching.
        $this->assertArrayHasKey('apartment_no', $payload);
        $this->assertNull($payload['apartment_no']);
    }

    /* ---------- fixtures ---------- */

    /** @return array{0: Unit, 1: Unit} the card, and the other door */
    private function building(): array
    {
        $card = $this->unit();
        $group = (string) str()->ulid();

        $card->forceFill(['unit_group_id' => $group, 'apartment_no' => '1'])->saveQuietly();

        $second = $card->owner->units()->create(array_merge(
            $card->only(['unit_type', 'price', 'capacity', 'bedrooms', 'approval_status', 'status']),
            [
                'unit_name' => $card->unit_name.' — 2',
                'code' => 'MRN'.fake()->unique()->numerify('#####'),
                'calendar_token' => str()->random(60),
                'unit_group_id' => $group,
                'apartment_no' => '2',
            ],
        ));

        return [$card->fresh(), $second->fresh()];
    }

    private function unit(): Unit
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $owner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        return $owner->units()->create([
            'unit_name' => 'وحدة دفع', 'unit_type' => 'apartment',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 4, 'bedrooms' => 1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }

    private function guest(): User
    {
        return $this->guest ??= User::factory()->create();
    }

    private ?User $guest = null;

    private function book(Unit $unit, int $from, int $to): TestResponse
    {
        return $this->actingAs($this->guest(), 'sanctum')->postJson('/api/v1/bookings', [
            'unit_id' => $unit->id,
            'start_date' => now()->addDays($from)->toDateString(),
            'end_date' => now()->addDays($to)->toDateString(),
            'guests' => 2,
        ]);
    }
}
