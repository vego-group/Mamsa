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
        // The favourite is resolved to its BUILDING first, and the building to
        // whichever apartment is currently sellable — not to the exact row that
        // was saved.
        //
        // The row is stored against the building's lowest id, which is stable,
        // but stable is not the same as open: that one door can be withdrawn or
        // lose its permit while the building carries on selling. Filtering on
        // the saved row would then drop the building out of the guest's
        // favourites although it is still on the storefront — the heart goes
        // out by itself and nothing says why.
        $keys = $request->user()->favoriteUnits()
            ->latest('favorites.created_at')
            ->get(['units.id', 'units.unit_group_id'])
            ->map(fn (Unit $u) => $u->unit_group_id ?: 'u'.$u->id)
            ->unique()
            ->values();

        // Null when every door of that building is closed: then there is
        // genuinely nothing to show, and it drops out.
        $ids = $keys
            ->map(fn (string $key) => Unit::resolveListingKey($key)?->id)
            ->filter()
            ->values();

        // One query for the cards, then put them back in the order the guest
        // saved them — whereIn does not promise to.
        $order = $ids->flip();

        $units = Unit::query()
            ->with(['images', 'features'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Unit $u) => $order[$u->id])
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
