<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\PaymentController;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A confirmed booking notifies whoever OWNS the unit — nobody else:
 *   - a partner listing        → the partner (unit owner) only;
 *   - a Mamsa-owned listing     → all super admins.
 * (Behaviour of PaymentController::confirmBooking — the single payment-success
 * entry point.) Guards against regressing to the old "email every admin on
 * every booking" fan-out.
 */
class BookingNotificationRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin', 'Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function user(string $role, array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        $u->assignRole($role);

        return $u;
    }

    private function unitOwnedBy(User $owner, bool $mamsaOwned = false): Unit
    {
        return $owner->units()->create([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 300, 'capacity' => 4, 'bedrooms' => 2, 'bathrooms' => 1, 'area' => 90,
            'city' => 'الرياض', 'district' => 'النرجس', 'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60), 'mamsa_owned' => $mamsaOwned,
        ]);
    }

    private function pendingBooking(Unit $unit, User $guest): Booking
    {
        return $unit->bookings()->create([
            'user_id' => $guest->id, 'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(), 'guests' => 2, 'nightly_rate' => 300,
            'subtotal' => 600, 'total_amount' => 600, 'commission_amount' => 12, 'status' => Booking::STATUS_PENDING,
        ]);
    }

    /** confirmBooking is private and is the one place NewBooking is dispatched. */
    private function confirm(Booking $booking): void
    {
        $method = new \ReflectionMethod(PaymentController::class, 'confirmBooking');
        $method->setAccessible(true);
        $method->invoke(app(PaymentController::class), $booking);
    }

    public function test_partner_listing_booking_emails_only_the_partner_owner(): void
    {
        $superA  = $this->user('SuperAdmin');
        $superB  = $this->user('SuperAdmin');
        $partner = $this->user('Individual', ['is_active' => true]);
        $guest   = $this->user('User');

        $booking = $this->pendingBooking($this->unitOwnedBy($partner), $guest);

        Notification::fake();
        $this->confirm($booking);

        Notification::assertSentTo($partner, NewBooking::class);
        Notification::assertNotSentTo($superA, NewBooking::class);
        Notification::assertNotSentTo($superB, NewBooking::class);
    }

    public function test_mamsa_owned_listing_booking_emails_all_super_admins_only(): void
    {
        $superA = $this->user('SuperAdmin');
        $superB = $this->user('SuperAdmin');
        // A non-super Admin owns the platform listing — must NOT be the target.
        $adminCreator = $this->user('Admin');
        $partner      = $this->user('Individual', ['is_active' => true]); // unrelated partner
        $guest        = $this->user('User');

        $booking = $this->pendingBooking($this->unitOwnedBy($adminCreator, mamsaOwned: true), $guest);

        Notification::fake();
        $this->confirm($booking);

        Notification::assertSentTo($superA, NewBooking::class);
        Notification::assertSentTo($superB, NewBooking::class);
        Notification::assertNotSentTo($adminCreator, NewBooking::class);
        Notification::assertNotSentTo($partner, NewBooking::class);
    }
}
