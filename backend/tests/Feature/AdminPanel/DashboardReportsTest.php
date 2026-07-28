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
 * Dashboard (§5.3) + Reports (§5.10) summaries — verifies KPIs, the fixed-length
 * series (12-month revenue, 7-day bookings, 4 status slices), and the embedded
 * pending-requests / host-cancellations lists.
 */
class DashboardReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin', 'Individual', 'Company', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

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
        $guest = User::factory()->create();
        $guest->assignRole('User');

        $this->partner = User::factory()->create(['is_active' => true]);
        $this->partner->assignRole('Individual');
        $this->partner->partnerDetail()->create([
            'type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED, 'reviewed_at' => now(),
        ]);

        $unit = $this->partner->units()->create([
            'unit_name' => 'شقة', 'unit_type' => 'apartment', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 300, 'capacity' => 4, 'bedrooms' => 2, 'bathrooms' => 1, 'area' => 100,
            'city' => 'الرياض', 'district' => 'النرجس', 'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        // Confirmed booking this month → drives revenue/commission/series.
        $confirmed = $unit->bookings()->create([
            'user_id' => $guest->id, 'start_date' => now()->subDays(3)->toDateString(), 'end_date' => now()->toDateString(),
            'guests' => 2, 'nightly_rate' => 300, 'subtotal' => 900, 'total_amount' => 900,
            'commission_amount' => 18, 'status' => Booking::STATUS_CONFIRMED,
        ]);
        $confirmed->payment()->create([
            'amount' => 900, 'refunded_amount' => 0, 'payment_method' => 'mada', 'payment_status' => 'paid', 'paid_at' => now(),
        ]);

        // A pending unit → pendingRequests / latestPendingRequests.
        $this->partner->units()->create([
            'unit_name' => 'وحدة قيد المراجعة', 'unit_type' => 'villa', 'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 500, 'capacity' => 6, 'bedrooms' => 3, 'bathrooms' => 2, 'area' => 200,
            'city' => 'جدة', 'district' => 'الشاطئ', 'approval_status' => 'pending', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        // A host cancellation → recentHostCancellations.
        $hostCancel = $unit->bookings()->create([
            'user_id' => $guest->id, 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(12)->toDateString(),
            'guests' => 2, 'nightly_rate' => 300, 'subtotal' => 600, 'total_amount' => 600,
            'commission_amount' => 12, 'status' => Booking::STATUS_CANCELLED,
            'cancelled_at' => now()->subDay(), 'cancelled_by' => 'partner', 'cancellation_reason' => 'صيانة طارئة',
        ]);
        $hostCancel->payment()->create([
            'amount' => 600, 'refunded_amount' => 600, 'payment_method' => 'visa', 'payment_status' => 'paid', 'paid_at' => now()->subDays(2),
        ]);
    }

    public function test_dashboard_summary_shape_and_invariants(): void
    {
        $json = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonStructure([
                'totalUsers', 'platformCommission', 'totalBookings', 'activePartners', 'pendingRequests',
                'monthlyGrowth', 'avgBookingValue',
                'deltas' => ['totalUsers', 'platformCommission', 'totalBookings', 'activePartners'],
                'revenueSeries' => [['label', 'revenue', 'commission']],
                'bookingStatusSlices' => [['status', 'count']],
                'revenueByCity' => [['label', 'value']],
                'weeklyBookings' => [['label', 'value']],
                'latestPendingRequests' => [['id', 'code', 'unitName', 'requestType', 'submittedAt']],
                'recentHostCancellations' => [['id', 'bookingCode', 'cancelledBy', 'at']],
            ])
            ->assertJsonPath('pendingRequests', 1)
            ->assertJsonPath('activePartners', 1)
            ->json();

        $this->assertCount(12, $json['revenueSeries']);
        $this->assertCount(7, $json['weeklyBookings']);
        $this->assertCount(4, $json['bookingStatusSlices']);
        $this->assertSame(['pending_payment', 'confirmed', 'completed', 'cancelled'], array_column($json['bookingStatusSlices'], 'status'));
        $this->assertCount(1, $json['latestPendingRequests']);
        $this->assertCount(1, $json['recentHostCancellations']);
        $this->assertSame('host', $json['recentHostCancellations'][0]['cancelledBy']);
        $this->assertEqualsWithDelta(18.0, $json['platformCommission'], 0.01);
    }

    public function test_reports_summary_range_windows(): void
    {
        $json = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/reports/summary?range=6m')
            ->assertOk()
            ->assertJsonStructure([
                'totalRevenue', 'totalCommission', 'totalBookings', 'avgMonthlyRevenue',
                'revenueSeries', 'revenueByCity', 'bookingStatusSlices', 'bookingVolume',
                'occupancySeries', 'occupancyAverage',
                'topPartners' => [['partnerId', 'name', 'city', 'units', 'bookings', 'revenue', 'commission']],
            ])
            ->json();

        $this->assertCount(6, $json['revenueSeries']);
        $this->assertCount(6, $json['bookingVolume']);
        $this->assertCount(6, $json['occupancySeries']);
        $this->assertEqualsWithDelta(900.0, $json['totalRevenue'], 0.01);
        $this->assertSame($this->partner->id, (int) $json['topPartners'][0]['partnerId']);

        // 1y → 12-month series.
        $y = $this->actingAs($this->admin(), 'admin-panel')->getJson('/admin/reports/summary?range=1y')->assertOk()->json();
        $this->assertCount(12, $y['revenueSeries']);
    }
}
