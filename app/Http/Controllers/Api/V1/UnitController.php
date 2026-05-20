<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $units = Unit::with(['images', 'features', 'reviews'])
            ->where('status', 'available')
            ->where('approval_status', 'approved')
            ->when($request->city, fn ($q) => $q->where('city', $request->city))
            ->when($request->type, fn ($q) => $q->where('unit_type', $request->type))
            ->when($request->min_price, fn ($q) => $q->where('price', '>=', $request->min_price))
            ->when($request->max_price, fn ($q) => $q->where('price', '<=', $request->max_price))
            ->when($request->capacity, fn ($q) => $q->where('capacity', '>=', $request->capacity))
            ->when($request->bedrooms, fn ($q) => $q->where('bedrooms', '>=', $request->bedrooms))
            ->latest()
            ->paginate($request->integer('per_page', 12));

        return $this->success(UnitResource::collection($units)->response()->getData(true));
    }

    public function show(Unit $unit): JsonResponse
    {
        if ($unit->approval_status !== 'approved' || $unit->status !== 'available') {
            return $this->error('الوحدة غير متاحة', 404);
        }

        $unit->load(['images', 'features', 'reviews.user']);

        return $this->success(new UnitResource($unit));
    }

    public function checkAvailability(Request $request, Unit $unit): JsonResponse
    {
        $request->validate([
            'checkin'  => ['required', 'date', 'after_or_equal:today'],
            'checkout' => ['required', 'date', 'after:checkin'],
        ]);

        $conflict = $unit->bookings()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($q) use ($request) {
                $q->whereBetween('start_date', [$request->checkin, $request->checkout])
                  ->orWhereBetween('end_date', [$request->checkin, $request->checkout])
                  ->orWhere(function ($q) use ($request) {
                      $q->where('start_date', '<=', $request->checkin)
                        ->where('end_date', '>=', $request->checkout);
                  });
            })->exists();

        $nights = \Carbon\Carbon::parse($request->checkin)->diffInDays($request->checkout);

        return $this->success([
            'available'   => ! $conflict,
            'nights'      => $nights,
            'total_price' => $conflict ? null : round($unit->price * $nights, 2),
        ]);
    }
}
