<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Booking;
use App\Models\Unit;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use \App\Traits\ApiResponse;

    private const OCCUPANCY_WINDOW_DAYS = 90;

    public function index(Request $request): JsonResponse
    {
        $since = now()->subDays(self::OCCUPANCY_WINDOW_DAYS)->toDateString();

        $query = Unit::query()->with(['images', 'owner'])
            ->withCount(['bookings as bookings_count'])
            ->withSum(['bookings as revenue' => fn ($q) => $q->whereIn('status', Booking::REVENUE_STATUSES)], 'total_amount')
            ->withAvg(['reviews as rating'], 'rating')
            ->withCount('reviews as reviews_count')
            // Booked nights (paid stays) in the trailing window → occupancy %.
            ->addSelect(['booked_nights' => Booking::query()
                ->selectRaw('COALESCE(SUM(DATEDIFF(end_date, start_date)), 0)')
                ->whereColumn('unit_id', 'units.id')
                ->whereIn('status', Booking::REVENUE_STATUSES)
                ->where('start_date', '>=', $since)]);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('unit_name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }
        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->approval_status);
        }
        if ($request->filled('type')) {
            $query->where('unit_type', $request->type);
        }
        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        $units = $query->latest()->paginate(20);

        return response()->json([
            'data'    => $units->getCollection()->map(fn (Unit $u) => $this->mapCard($u))->all(),
            'meta'    => [
                'current_page' => $units->currentPage(),
                'last_page'    => $units->lastPage(),
                'total'        => $units->total(),
            ],
            'summary' => $this->summary($since),
        ]);
    }

    /** @return array<string, mixed> */
    private function mapCard(Unit $u): array
    {
        $img = $u->images->firstWhere('is_main', true) ?? $u->images->first();
        $imageUrl = $img && filled($img->path) && $img->path !== Media::defaultImagePath()
            ? $img->url
            : Media::defaultImageUrl();

        return [
            'id'              => $u->id,
            'code'            => $u->code,
            'name'            => $u->unit_name,
            'city'            => $u->city,
            'type'            => $u->unit_type,
            'beds'            => (int) $u->beds,
            'capacity'        => (int) $u->capacity,
            'price'           => (float) $u->price,
            'rating'          => $u->rating !== null ? round((float) $u->rating, 1) : null,
            'reviews_count'   => (int) $u->reviews_count,
            'bookings_count'  => (int) $u->bookings_count,
            'revenue'         => round((float) ($u->revenue ?? 0), 2),
            'occupancy'       => min(100, (int) round(((int) $u->booked_nights / self::OCCUPANCY_WINDOW_DAYS) * 100)),
            'approval_status' => $u->approval_status,
            'publication'     => $this->publicationState($u),
            'is_featured'     => (bool) $u->is_featured,
            'image_url'       => $imageUrl,
        ];
    }

    /** published | under_review | unpublished — the top-left card badge. */
    private function publicationState(Unit $u): string
    {
        if ($u->approval_status === 'pending') {
            return 'under_review';
        }
        if ($u->approval_status === 'rejected') {
            return 'unpublished';
        }

        return $u->status === 'available' ? 'published' : 'unpublished';
    }

    /** @return array<string, int|float> */
    private function summary(string $since): array
    {
        $approved = Unit::where('approval_status', 'approved')->count();

        $bookedNights = (int) Booking::query()->revenue()
            ->where('start_date', '>=', $since)
            ->selectRaw('COALESCE(SUM(DATEDIFF(end_date, start_date)), 0) as n')
            ->value('n');

        $avgOccupancy = $approved > 0
            ? min(100, (int) round(($bookedNights / ($approved * self::OCCUPANCY_WINDOW_DAYS)) * 100))
            : 0;

        return [
            'total'         => Unit::count(),
            'published'     => Unit::where('approval_status', 'approved')->where('status', 'available')->count(),
            'avg_occupancy' => $avgOccupancy,
            'total_revenue' => round((float) Booking::query()->revenue()->sum('total_amount'), 2),
        ];
    }

    /**
     * Editorial "featured" toggle for the storefront home section (§2.1).
     * Admin-only — being featured is a platform decision, not the partner's.
     */
    public function setFeatured(Request $request, Unit $unit): JsonResponse
    {
        $data = $request->validate(['is_featured' => ['required', 'boolean']]);

        $unit->update(['is_featured' => $data['is_featured']]);

        return $this->success(
            new UnitResource($unit->load(['images', 'features', 'owner.partnerDetail'])),
            $data['is_featured'] ? 'تم تمييز الوحدة' : 'تم إلغاء تمييز الوحدة',
        );
    }
}
