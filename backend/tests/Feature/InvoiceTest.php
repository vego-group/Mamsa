<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Support\ZatcaQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Tax invoice — contract §7.1. */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['User', 'Individual'] as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function paidBooking(?User $guest = null, string $status = Booking::STATUS_CONFIRMED): Booking
    {
        $partner = User::factory()->create();
        $partner->assignRole('Individual');
        $unit = $partner->units()->create([
            'unit_name' => 'شقة مودرن', 'unit_type' => 'apartment',
            'code' => 'INV'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 2, 'bedrooms' => 1,
            'city' => 'الرياض', 'district' => 'النرجس',
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        $guest ??= User::factory()->create();

        return Booking::create([
            'unit_id' => $unit->id, 'user_id' => $guest->id,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-03',
            'guests' => 2, 'nightly_rate' => 500,
            'subtotal' => 869.57, 'taxes' => 130.43, 'tax_percent' => 15,
            'commission_rate' => 0.02, 'commission_amount' => 17.39,
            'partner_share' => 852.18, 'total_amount' => 1000.00,
            'status' => $status,
        ]);
    }

    public function test_invoice_returns_the_contract_shape(): void
    {
        $guest = User::factory()->create(['name' => 'محمد الأحمدي']);
        $booking = $this->paidBooking($guest);

        $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}/invoice")
            ->assertOk()
            ->assertJsonStructure([
                'invoiceNumber', 'issuedAt',
                'seller' => ['name', 'vatNumber', 'crNumber', 'address'],
                'buyerName',
                'lines' => [['description', 'checkIn', 'checkOut', 'nights', 'netBase', 'vatRate', 'vat', 'gross']],
                'totalNetBase', 'totalVat', 'totalGross', 'currency', 'qrCode',
            ])
            ->assertJsonPath('buyerName', 'محمد الأحمدي')
            ->assertJsonPath('currency', 'SAR')
            ->assertJsonPath('totalNetBase', 869.57)
            ->assertJsonPath('totalVat', 130.43)
            ->assertJsonPath('totalGross', fn ($v) => (float) $v === 1000.0)
            ->assertJsonPath('lines.0.nights', 2)
            ->assertJsonPath('lines.0.vatRate', 0.15);
    }

    public function test_totals_reconcile_to_the_gross(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        $body = $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}/invoice")
            ->assertOk()->json();

        $this->assertEqualsWithDelta(
            (float) $body['totalGross'],
            round((float) $body['totalNetBase'] + (float) $body['totalVat'], 2),
            0.001,
            'netBase + vat must equal gross on the invoice.'
        );
    }

    public function test_qr_is_null_until_a_real_vat_number_is_configured(): void
    {
        // This is what the client renders as "preparing" — it must be null,
        // never a placeholder string that would look like a valid code.
        config(['invoice.seller.vat_number' => '']);

        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}/invoice")
            ->assertOk()->assertJsonPath('qrCode', null);
    }

    public function test_qr_is_emitted_once_a_vat_number_exists(): void
    {
        config([
            'invoice.seller.vat_number' => '300000000000003',
            'invoice.seller.name'       => 'منصة ممسى',
        ]);

        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        $qr = $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}/invoice")
            ->assertOk()->json('qrCode');

        $this->assertIsString($qr);
        $decoded = base64_decode($qr, true);
        $this->assertNotFalse($decoded);
        // Tag 2 carries the VAT number; it must survive the TLV round-trip.
        $this->assertStringContainsString('300000000000003', $decoded);
    }

    public function test_tlv_lengths_count_bytes_not_characters(): void
    {
        // The seller name is Arabic. A character count would understate the
        // length and produce a code no ZATCA reader accepts.
        $qr = ZatcaQr::forInvoice('منصة ممسى', '300000000000003', now(), 1000.00, 130.43);
        $raw = base64_decode($qr, true);

        $nameLen = strlen('منصة ممسى');           // bytes, not chars
        $this->assertSame(chr(1).chr($nameLen), substr($raw, 0, 2));
    }

    public function test_unpaid_booking_has_no_invoice(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, Booking::STATUS_PENDING);

        $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}/invoice")
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVOICE_NOT_AVAILABLE');
    }

    public function test_another_guest_cannot_read_the_invoice(): void
    {
        $booking = $this->paidBooking();
        $other   = User::factory()->create();

        $this->actingAs($other)->getJson("/api/v1/bookings/{$booking->id}/invoice")
            ->assertForbidden();
    }

    public function test_invoice_number_is_stable_across_reprints(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        $first  = $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}/invoice")->json('invoiceNumber');
        $second = $this->actingAs($guest)->getJson("/api/v1/bookings/{$booking->id}/invoice")->json('invoiceNumber');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{2}-\d{6}$/', $first);
    }
}
