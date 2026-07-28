<?php

declare(strict_types=1);

namespace App\Support\AdminPanel;

use App\Http\Controllers\AdminPanel\Concerns\MapsSpec;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Shared time-series / aggregate builders for the admin-panel dashboard (§5.3)
 * and reports (§5.10). All SQL is driver-aware (MapsSpec helpers) so it runs on
 * MySQL in production and sqlite under tests. Monetary series use the frozen
 * commission on each booking.
 */
class Analytics
{
    use MapsSpec;

    /** Last N months of revenue + commission (confirmed bookings), oldest → newest. */
    public function revenueSeries(int $months): array
    {
        $ym   = $this->ymSql('created_at');
        $rows = Booking::query()->revenue()
            ->where('created_at', '>=', now()->subMonths($months - 1)->startOfMonth())
            ->selectRaw("{$ym} as ym, SUM(total_amount) as revenue, SUM(".Booking::commissionExpr().") as commission")
            ->groupBy('ym')->get()->keyBy('ym');

        return $this->eachMonth($months, fn ($m) => [
            'label'      => $m->format('M'),
            'revenue'    => $this->money($rows[$m->format('Y-m')]->revenue ?? 0),
            'commission' => $this->money($rows[$m->format('Y-m')]->commission ?? 0),
        ]);
    }

    /** Last N months of booking counts, oldest → newest. */
    public function bookingVolume(int $months): array
    {
        $ym   = $this->ymSql('created_at');
        $rows = Booking::where('created_at', '>=', now()->subMonths($months - 1)->startOfMonth())
            ->selectRaw("{$ym} as ym, COUNT(*) as c")
            ->groupBy('ym')->get()->keyBy('ym');

        return $this->eachMonth($months, fn ($m) => [
            'label' => $m->format('M'),
            'value' => (int) ($rows[$m->format('Y-m')]->c ?? 0),
        ]);
    }

    /** Last N months of occupancy % (confirmed booked-nights / (approved units × days)). */
    public function occupancySeries(int $months): array
    {
        $approved = max(1, Unit::where('approval_status', 'approved')->count());
        $ym       = $this->ymSql('start_date');
        // Alias must NOT be `nights` — that collides with Booking's getNightsAttribute
        // accessor, which would dereference the (unselected) start_date and crash.
        $rows = Booking::query()->revenue()
            ->where('start_date', '>=', now()->subMonths($months - 1)->startOfMonth())
            ->selectRaw("{$ym} as ym, {$this->nightsSql()} as total_nights")
            ->groupBy('ym')->get()->keyBy('ym');

        return $this->eachMonth($months, function ($m) use ($rows, $approved) {
            $nights = (int) ($rows[$m->format('Y-m')]->total_nights ?? 0);

            return [
                'label' => $m->format('M'),
                'value' => min(100, (int) round(($nights / ($approved * $m->daysInMonth)) * 100)),
            ];
        });
    }

    /** Last 7 days of booking counts, oldest → newest (label = short day name). */
    public function weeklyBookings(): array
    {
        $rows = Booking::where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')->get()->keyBy('d');

        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day   = now()->subDays($i);
            $out[] = ['label' => $day->format('D'), 'value' => (int) ($rows[$day->format('Y-m-d')]->c ?? 0)];
        }

        return $out;
    }

    /** Revenue (paid stays) grouped by unit city, highest first. */
    public function revenueByCity(?CarbonInterface $since = null): array
    {
        return Booking::query()->revenue()
            ->when($since, fn ($q) => $q->where('bookings.created_at', '>=', $since))
            ->join('units', 'units.id', '=', 'bookings.unit_id')
            ->selectRaw('units.city as city, SUM(bookings.total_amount) as revenue')
            ->groupBy('units.city')->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['label' => $r->city ?: '—', 'value' => $this->money($r->revenue)])
            ->all();
    }

    /** One slice per BookingStatus (all four keys, spec order). */
    public function bookingStatusSlices(?CarbonInterface $since = null): array
    {
        $counts = Booking::when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $slices = ['pending_payment' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
        foreach ($counts as $status => $c) {
            $slices[$this->bookingStatus($status)] += (int) $c;
        }

        return array_map(fn ($status, $count) => ['status' => $status, 'count' => $count], array_keys($slices), array_values($slices));
    }

    /** Top partners by (range-scoped) revenue from paid stays. */
    public function topPartners(int $limit = 5, ?CarbonInterface $since = null): array
    {
        // Paid stays (confirmed + completed); commission = 2% of subtotal.
        $revenue = fn ($q) => $q->whereIn('bookings.status', Booking::REVENUE_STATUSES)
            ->when($since, fn ($b) => $b->where('bookings.created_at', '>=', $since));

        return User::role(['Individual', 'Company'], 'web')->whereHas('partnerDetail')->with('partnerDetail')
            ->withCount('units')
            ->withCount(['unitBookings as bookings_count'])
            ->withSum(['unitBookings as revenue' => $revenue], 'total_amount')
            ->withSum(['unitBookings as subtotal_sum' => $revenue], 'subtotal')
            ->addSelect(['city' => Unit::query()->select('city')->whereColumn('units.user_id', 'users.id')->latest()->limit(1)])
            ->orderByDesc('revenue')->limit($limit)->get()
            ->map(fn (User $u) => [
                'partnerId'  => (string) $u->id,
                'name'       => $u->name ?? '',
                'city'       => $u->city ?? '',
                'units'      => (int) $u->units_count,
                'bookings'   => (int) $u->bookings_count,
                'revenue'    => $this->money($u->revenue),
                'commission' => $this->money((float) $u->subtotal_sum * Booking::COMMISSION_RATE),
            ])->all();
    }

    /** Walk N months oldest → newest, applying $build to each Carbon month. */
    private function eachMonth(int $months, callable $build): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $out[] = $build(now()->subMonths($i));
        }

        return $out;
    }
}
