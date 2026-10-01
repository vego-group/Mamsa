<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The confirmation SMS is the one message every guest gets, verified email or
 * not. It has to read like a sentence a person wrote.
 */
class BookingConfirmedSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function booking(string $checkin = '13:00:00'): Booking
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');
        $guest = User::factory()->create();
        $guest->assignRole('User');

        $unit = $owner->units()->create([
            'unit_name' => 'وحدة اختبار', 'unit_type' => 'apartment',
            'code' => 'SMS'.fake()->unique()->numerify('#####'),
            'price' => 5, 'capacity' => 2, 'bedrooms' => 1, 'bathrooms' => 1, 'area' => 75,
            'city' => 'الرياض', 'district' => 'النرجس', 'approval_status' => 'approved',
            'status' => 'available', 'calendar_token' => str()->random(60),
            'checkin_time' => $checkin,
        ]);

        return $unit->bookings()->create([
            'user_id' => $guest->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-02',
            'guests' => 2, 'nightly_rate' => 5, 'subtotal' => 5,
            'total_amount' => 5.75, 'commission_amount' => 0.5,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    /**
     * start_date/end_date are cast to `date`, so interpolating them into a
     * string printed "2026-10-01 00:00:00" — a midnight that is not a check-in
     * time, is not anything the guest chose, and reads as a bug in an SMS.
     */
    public function test_the_sms_carries_dates_without_a_meaningless_midnight(): void
    {
        $sms = (new BookingConfirmed($this->booking()))->toSms(new User());

        $this->assertStringNotContainsString('00:00:00', $sms);
        $this->assertStringContainsString('2026-10-01', $sms);
        $this->assertStringContainsString('2026-10-02', $sms);
    }

    public function test_the_sms_tells_the_guest_when_they_can_check_in(): void
    {
        $sms = (new BookingConfirmed($this->booking('16:00:00')))->toSms(new User());

        // The hour and minute only — seconds in a check-in time are noise.
        $this->assertStringContainsString('16:00', $sms);
        $this->assertStringNotContainsString('16:00:00', $sms);
    }

    /** A unit with no check-in time still has to produce a sentence. */
    public function test_a_missing_checkin_time_falls_back_rather_than_printing_nothing(): void
    {
        $b = $this->booking();
        $b->unit->update(['checkin_time' => null]);

        $sms = (new BookingConfirmed($b->fresh('unit')))->toSms(new User());

        $this->assertStringContainsString('15:00', $sms);
    }
}
