<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $unitIds = $request->user()->units()->pluck('id');

        $bookings = \App\Models\Booking::whereIn('unit_id', $unitIds)
            ->with(['unit.images', 'user', 'payment'])
            ->latest()
            ->paginate(15);

        return response()->json(BookingResource::collection($bookings));
    }
}
