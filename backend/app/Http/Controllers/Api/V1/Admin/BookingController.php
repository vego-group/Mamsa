<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Booking::with(['unit.images', 'user', 'payment']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                // Booking id, or by customer / unit
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                  ->orWhereHas('unit', fn ($u) => $u->where('unit_name', 'like', "%{$search}%"));
            });
        }

        $bookings = $query->latest()->paginate(20);

        return response()->json([
            'data'    => BookingResource::collection($bookings->items()),
            'meta'    => [
                'current_page' => $bookings->currentPage(),
                'last_page'    => $bookings->lastPage(),
                'total'        => $bookings->total(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    /** @return array<string, int|float> */
    private function summary(): array
    {
        $counts = Booking::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total'     => (int) $counts->sum(),
            'confirmed' => (int) ($counts['confirmed'] ?? 0),
            'pending'   => (int) ($counts['pending'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'revenue'   => round((float) Booking::where('status', 'confirmed')->sum('total_amount'), 2),
        ];
    }
}
