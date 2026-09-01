<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The guest's confirmation email carries a way to reach the tax invoice.
 *
 * The invoice itself is an authenticated JSON endpoint, so the email cannot
 * link to it directly — a Bearer token does not survive a mail client. The
 * link therefore points at the storefront's reservation page, which is where
 * the invoice is actually rendered.
 */
class BookingConfirmedInvoiceLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function booking(array $guestAttrs = []): Booking
    {
        $owner = User::factory()->create();
        $owner->assignRole('Individual');

        $guest = User::factory()->create($guestAttrs);
        $guest->assignRole('User');

        $unit = $owner->units()->create([
            'unit_name' => 'وحدة', 'unit_type' => 'apartment', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 300, 'capacity' => 4, 'bedrooms' => 2, 'bathrooms' => 1, 'area' => 90,
            'city' => 'الرياض', 'district' => 'النرجس', 'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        return $unit->bookings()->create([
            'user_id' => $guest->id, 'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(), 'guests' => 2, 'nightly_rate' => 300,
            'subtotal' => 600, 'total_amount' => 690, 'commission_amount' => 60,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    public function test_the_confirmation_email_links_to_the_invoice(): void
    {
        config(['app.frontend_url' => 'https://storefront.example']);

        $booking = $this->booking(['email_verified_at' => now()]);
        $mail = (new BookingConfirmed($booking))->toMail($booking->user);

        $expected = 'https://storefront.example/my-reservations/'.$booking->id;

        $this->assertSame($expected, $mail->viewData['invoiceUrl'] ?? null);
        $this->assertStringContainsString($expected, $mail->render());
    }

    /**
     * The host is read from config, not hard-coded: staging mail must land on
     * staging, and a build that pointed every guest at production would be
     * indistinguishable from a working one until someone clicked.
     */
    public function test_the_link_follows_the_configured_frontend(): void
    {
        config(['app.frontend_url' => 'https://staging.example/']);

        $booking = $this->booking(['email_verified_at' => now()]);
        $mail = (new BookingConfirmed($booking))->toMail($booking->user);

        // Trailing slash trimmed — '…example//my-reservations/1' is a 404 on
        // some hosts and an ugly link on all of them.
        $this->assertSame(
            'https://staging.example/my-reservations/'.$booking->id,
            $mail->viewData['invoiceUrl'] ?? null,
        );
    }

    /**
     * The gate that predates this link still holds. The link carries a booking
     * id, so sending it to an unverified address would hand a typo'd inbox a
     * pointer at someone's reservation.
     */
    public function test_an_unverified_address_still_gets_no_email(): void
    {
        $booking = $this->booking(['email_verified_at' => null]);

        $this->assertNotContains('mail', (new BookingConfirmed($booking))->via($booking->user));
    }
}
