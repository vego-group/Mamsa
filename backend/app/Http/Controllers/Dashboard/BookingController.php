<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Bookings\HostCancelBookingAction;
use App\Models\Booking;
use App\Support\Dashboard\BookingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Bookings — read + host-cancel (contract §6). Bookings are created by the
 * user website via Moyasar; the partner dashboard never creates them.
 */
class BookingController extends DashboardController
{
    public function index(Request $request): JsonResponse
    {
        [$page, $limit] = $this->pageArgs($request);

        $unitIds = $request->user()->units()->pluck('id');

        $query = Booking::whereIn('unit_id', $unitIds)
            ->with(['unit.images', 'user', 'payment'])
            ->latest('start_date');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($unitId = $request->query('unitId')) {
            $query->where('unit_id', self::rawId($unitId, 'u_'));
        }

        if ($from = $request->query('from')) {
            $query->where('start_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('start_date', '<=', $to);
        }

        if ($q = $request->query('q')) {
            // Booking codes are "BK-<id>" — search by the numeric id.
            $query->where('id', 'like', '%'.preg_replace('/\D+/', '', $q).'%');
        }

        return $this->paginated(
            $query->paginate(perPage: $limit, page: $page),
            fn (Booking $b) => BookingPresenter::make($b),
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->ok(BookingPresenter::make($this->ownBooking($request, self::rawId($id, 'b_'))));
    }

    public function hostCancel(Request $request, string $id, HostCancelBookingAction $action): JsonResponse
    {
        $booking = $this->ownBooking($request, self::rawId($id, 'b_'));

        $data = $this->validated($request, [
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $updated = $action->execute(
            $booking,
            $request->user(),
            strip_tags($data['reason']),
            $request->header('Idempotency-Key'),
        );

        return $this->ok(BookingPresenter::make($updated));
    }

    private static function rawId(string $id, string $prefix = 'b_'): string
    {
        return Str::startsWith($id, $prefix) ? Str::after($id, $prefix) : $id;
    }
}
