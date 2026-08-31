<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Support\Booking\Availability;
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
            ->get()
            // One card per building, like the storefront. A guest who
            // favourited a tower wants it once, not once per apartment — and
            // rows predating the canonicalisation in store() can still be
            // spread across siblings.
            ->unique(fn (Unit $u) => $u->unit_group_id ?: 'u'.$u->id)
            ->values();

        Availability::attachCounts($units);

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

        // Stored against the building's FIRST apartment, never the one the card
        // happened to show. The card's unit changes as apartments get booked,
        // so favouriting the same building twice on different days would
        // otherwise leave two rows the guest sees as two listings.
        // firstOrCreate keeps it idempotent under the (user, unit) unique index.
        $request->user()->favorites()->firstOrCreate(['unit_id' => $this->canonical($unit)]);

        return response()->noContent();
    }

    /** DELETE /user/favorites/{unit} */
    public function destroy(Request $request, Unit $unit): Response
    {
        // Removes the building, whichever apartment the guest is looking at —
        // and sweeps any sibling rows left by favourites saved before store()
        // canonicalised. Deleting only this unit's row would leave the heart
        // lit with nothing the guest could click to clear it.
        $request->user()->favorites()
            ->whereIn('unit_id', $this->siblingIds($unit))
            ->delete();

        return response()->noContent();
    }

    /** The apartment a building is favourited against: its lowest id. */
    private function canonical(Unit $unit): int
    {
        return $unit->unit_group_id
            ? (int) Unit::where('unit_group_id', $unit->unit_group_id)->min('id')
            : (int) $unit->id;
    }

    /** @return list<int> */
    private function siblingIds(Unit $unit): array
    {
        return $unit->unit_group_id
            ? Unit::where('unit_group_id', $unit->unit_group_id)->pluck('id')->all()
            : [(int) $unit->id];
    }
}
