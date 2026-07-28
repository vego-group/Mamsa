<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin-panel read endpoints — BACKEND_SPEC §5.4–5.9. Verifies the list
 * envelope, camelCase shapes, and the spec's aggregate invariants.
 */
class ReadEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $guest;
    private User $partner;
    private Unit $unit;
    private Booking $confirmed;
    private Booking $cancelled;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin', 'Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        // Create the admin here, while the default guard is still 'web' — after
        // an actingAs(...,'admin-panel') Spatie resolves roles against that guard.
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('SuperAdmin');

        $this->seedScenario();
    }

    private function admin(): User
    {
        return $this->adminUser;
    }

    private function seedScenario(): void
    {
        $this->guest = User::factory()->create();
        $this->guest->assignRole('User');

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->partner->partnerDetail()->create([
            'type'        => 'individual',
            'status'      => PartnerDetail::STATUS_APPROVED,
            'national_id' => '1098765432',
            'iban'        => 'SA0380000000608010167519',
            'reviewed_at' => now(),
        ]);

        $this->unit = $this->partner->units()->create([
            'unit_name'       => 'شقة الاختبار',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 300,
            'capacity'        => 4,
            'bedrooms'        => 2,
            'beds'            => 3,
            'bathrooms'       => 2,
            'area'            => 120,
            'city'            => 'الرياض',
            'district'        => 'النرجس',
            'lat'             => 24.77,
            'lng'             => 46.73,
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);

        // Confirmed stay in the trailing 90-day window (drives revenue/occupancy).
        $this->confirmed = $this->unit->bookings()->create([
            'user_id'           => $this->guest->id,
            'start_date'        => now()->subDays(5)->toDateString(),
            'end_date'          => now()->subDays(2)->toDateString(),
            'guests'            => 2,
            'nightly_rate'      => 300,
            'subtotal'          => 900,
            'total_amount'      => 900,
            'commission_amount' => 18,
            'status'            => Booking::STATUS_CONFIRMED,
        ]);
        $this->confirmed->payment()->create([
            'amount'         => 900,
            'refunded_amount'=> 0,
            'payment_method' => 'mada',
            'payment_status' => 'paid',
            'moyasar_id'     => 'pay_test_1',
            'paid_at'        => now()->subDays(6),
        ]);
        $this->unit->reviews()->create([
            'booking_id' => $this->confirmed->id,
            'user_id'    => $this->guest->id,
            'unit_id'    => $this->unit->id,
            'rating'     => 5,
            'comment'    => 'ممتاز',
        ]);

        // A guest cancellation, fully refunded.
        $this->cancelled = $this->unit->bookings()->create([
            'user_id'             => $this->guest->id,
            'start_date'          => now()->addDays(20)->toDateString(),
            'end_date'            => now()->addDays(23)->toDateString(),
            'guests'              => 2,
            'nightly_rate'        => 300,
            'subtotal'            => 500,
            'total_amount'        => 500,
            'commission_amount'   => 10,
            'status'              => Booking::STATUS_CANCELLED,
            'cancelled_at'        => now()->subDay(),
            'cancelled_by'        => 'customer',
            'cancellation_reason' => 'تغيير الخطة',
        ]);
        // A refund is tracked by refunded_amount; payment_status stays 'paid'
        // (enum is pending|paid|failed — there is no 'refunded' status).
        $this->cancelled->payment()->create([
            'amount'          => 500,
            'refunded_amount' => 500,
            'payment_method'  => 'visa',
            'payment_status'  => 'paid',
            'moyasar_id'      => 'pay_test_2',
            'paid_at'         => now()->subDays(3),
        ]);
    }

    /* ---------- users §5.4 ---------- */

    public function test_users_list_stats_detail(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/users')
            ->assertOk()
            ->assertJsonStructure(['items' => [['id', 'code', 'name', 'email', 'phone', 'city', 'bookingsCount', 'totalSpent', 'joinedAt', 'status', 'hasActiveBookings']], 'total', 'page', 'pageSize'])
            ->assertJsonPath('pageSize', 10);

        $stats = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/users/stats')->assertOk()->json();
        $this->assertSame($stats['total'], $stats['active'] + $stats['pendingActivation'] + $stats['disabled']);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/users/'.$this->guest->id)
            ->assertOk()
            ->assertJsonStructure(['id', 'avgBookingValue', 'activity'])
            ->assertJsonPath('id', (string) $this->guest->id);
    }

    /* ---------- partners §5.5 ---------- */

    public function test_partners_list_stats_detail(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/partners')
            ->assertOk()
            ->assertJsonStructure(['items' => [['id', 'code', 'type', 'unitsCount', 'bookingsCount', 'revenue', 'rating', 'verified', 'status', 'cancellations12m', 'cancellationRate', 'flagged']], 'total', 'page', 'pageSize']);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/partners/stats')
            ->assertOk()
            ->assertJsonStructure(['total', 'individuals', 'companies', 'active', 'pending', 'verified', 'highRisk', 'totalRevenue']);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/partners/'.$this->partner->id)
            ->assertOk()
            ->assertJsonStructure(['documents' => [['id', 'kind', 'label', 'fileUrl', 'value', 'status']], 'documentsComplete', 'commissionPaid', 'partnerEarning', 'avgPerBooking', 'nationalId', 'iban'])
            ->assertJsonPath('documentsComplete', true);
    }

    /* ---------- units §5.6 (exercises driver-aware occupancy SQL) ---------- */

    public function test_units_list_stats_detail(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/units')
            ->assertOk()
            ->assertJsonStructure(['items' => [['id', 'code', 'name', 'partnerId', 'partnerName', 'type', 'status', 'pricePerNight', 'bedrooms', 'bathrooms', 'capacity', 'sizeSqm', 'rating', 'reviewsCount', 'occupancyRate', 'revenue', 'bookingsCount', 'coverImage', 'mamsaOwned']], 'total'])
            ->assertJsonPath('items.0.status', 'approved')
            ->assertJsonPath('items.0.type', 'apartment');

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/units/stats')
            ->assertOk()
            ->assertJsonStructure(['total', 'approved', 'pendingReview', 'avgOccupancy', 'totalRevenue']);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/units/'.$this->unit->id)
            ->assertOk()
            ->assertJsonStructure(['description', 'images', 'amenities', 'lat', 'lng', 'publicUrl', 'tourismPermitNo'])
            ->assertJsonPath('id', (string) $this->unit->id);
    }

    /* ---------- bookings §5.8 ---------- */

    public function test_bookings_list_counts_stats_detail(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/bookings')
            ->assertOk()
            ->assertJsonStructure(['items' => [['id', 'code', 'guestName', 'unitName', 'partnerName', 'checkIn', 'checkOut', 'nights', 'total', 'commission', 'partnerShare', 'paymentStatus', 'status']], 'total']);

        $counts = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/bookings/counts')->assertOk()->json();
        $this->assertSame($counts['all'], $counts['pending_payment'] + $counts['confirmed'] + $counts['completed'] + $counts['cancelled']);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/bookings/stats')
            ->assertOk()->assertJsonStructure(['totalRevenue', 'commission', 'avgBookingValue']);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/bookings/'.$this->confirmed->id)
            ->assertOk()
            ->assertJsonStructure(['policySnapshot' => ['name', 'capturedAt', 'tiers'], 'timeline' => [['id', 'label', 'at', 'state']]])
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('paymentStatus', 'paid');
    }

    /* ---------- cancellations §5.9 (exercises driver-aware trend SQL) ---------- */

    public function test_cancellations_list_stats_high_risk(): void
    {
        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/cancellations')
            ->assertOk()
            ->assertJsonStructure(['items' => [['id', 'bookingCode', 'guestName', 'cancelledBy', 'unitName', 'at', 'reason', 'bookingTotal', 'refundAmount', 'impact', 'refundStatus']], 'total'])
            ->assertJsonPath('items.0.cancelledBy', 'guest')
            ->assertJsonPath('items.0.refundStatus', 'refunded');

        $stats = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/cancellations/stats')->assertOk()->json();
        $this->assertSame($stats['total'], $stats['byGuest'] + $stats['byHost']);
        $this->assertSame($stats['total'], array_sum($stats['refundBreakdown']));
        $this->assertCount(6, $stats['trend']);

        $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/cancellations/high-risk-partners')
            ->assertOk();
    }
}
