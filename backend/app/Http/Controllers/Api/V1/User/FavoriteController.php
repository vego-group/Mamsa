<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cross-device favourites (backend gaps #7). Replaces the frontend's
 * localStorage-only wishlist with a server-synced list.
 */
class FavoriteController extends Controller
{
    /** GET /user/favorites — the user's favourited units (supported/available only). */
    public function index(Request $request): JsonResponse
    {
        $units = $request->user()->favoriteUnits()
            ->with(['images', 'features'])
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES)
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->latest('favorites.created_at')
            ->get();

        return response()->json(UnitResource::collection($units)->resolve($request));
    }

    /** POST /user/favorites/{unit} — idempotent add. */
    public function store(Request $request, Unit $unit): Response
    {
        abort_unless(
            in_array($unit->unit_type, Unit::SUPPORTED_TYPES, true),
            404,
            'الوحدة غير متاحة'
        );

        // firstOrCreate keeps it idempotent under the (user, unit) unique index.
        $request->user()->favorites()->firstOrCreate(['unit_id' => $unit->id]);

        return response()->noContent();
    }

    /** DELETE /user/favorites/{unit} */
    public function destroy(Request $request, Unit $unit): Response
    {
        $request->user()->favorites()->where('unit_id', $unit->id)->delete();

        return response()->noContent();
    }
}
