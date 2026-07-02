<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Booking;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitController extends Controller
{
    /**
     * Home-page destination categories (اكتشف وجهتك). Single source of truth:
     * each maps a display label + icon to one or more stored `unit_type` values.
     * Order matches the design (first card = top-right in the RTL grid).
     */
    private const CATEGORIES = [
        ['key' => 'apartment', 'label' => 'شقق',    'icon' => 'apartment', 'types' => ['apartment']],
        ['key' => 'studio',    'label' => 'استديو', 'icon' => 'studio',    'types' => ['studio']],
        ['key' => 'villa',     'label' => 'فلل',    'icon' => 'villa',     'types' => ['villa']],
    ];

    // Category artwork (اكتشف وجهتك) falls back to the bundled default image when
    // no unit of that type has one — resolved at runtime in categories().

    /**
     * Budget ranges (حسب الميزانية) in SAR/night. `min`/`max` null = open-ended.
     * Order matches the design (first = right-most card in the RTL row).
     */
    private const BUDGET_BUCKETS = [
        ['key' => '2000_3000', 'label' => '2000 - 3000 ر.س', 'min' => 2000, 'max' => 3000],
        ['key' => '1000_2000', 'label' => '1000 - 2000 ر.س', 'min' => 1000, 'max' => 2000],
        ['key' => '500_1000',  'label' => '500 - 1000 ر.س',  'min' => 500,  'max' => 1000],
        ['key' => 'under_500', 'label' => 'أقل من 500 ر.س',   'min' => null, 'max' => 500],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Unit::with(['images', 'features'])
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES) // #3 — only apartment|studio|villa
            ->where('approval_status', 'approved')
            ->where('status', 'available');

        // Free-text search across name / city / district (hero search box + category chips).
        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $query->where(function ($sub) use ($term) {
                $sub->where('unit_name', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('district', 'like', $term);
            });
        }
        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }
        if ($request->filled('type')) {
            $query->where('unit_type', $request->type);
        }
        // Destination-category filter (maps a category key to its unit types).
        if ($request->filled('category')) {
            $category = collect(self::CATEGORIES)->firstWhere('key', $request->category);
            if ($category) {
                $query->whereIn('unit_type', $category['types']);
            }
        }
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }
        if ($request->filled('capacity')) {
            $query->where('capacity', '>=', $request->capacity);
        }
        if ($request->filled('bedrooms')) {
            $query->where('bedrooms', $request->bedrooms);
        }

        // Rating filter (التقييم): average review score ≥ threshold. Computed via a
        // correlated subquery so units with no reviews (avg → NULL/0) are excluded.
        if ($request->filled('min_rating')) {
            $query->whereRaw(
                '(select coalesce(avg(rating), 0) from reviews where reviews.unit_id = units.id) >= ?',
                [(float) $request->min_rating]
            );
        }

        // Amenities filter (المرافق): AND semantics — a unit must have EVERY
        // selected feature, so each value adds its own whereHas constraint.
        if ($request->filled('features')) {
            foreach ((array) $request->features as $feature) {
                $query->whereHas('features', fn ($q) => $q->where('name', $feature));
            }
        }

        return UnitResource::collection($query->paginate(12));
    }

    /**
     * Most-requested units (الأكثر طلباً) — ranked by confirmed booking volume,
     * then rating. Powers the curated home-page rail.
     */
    public function popular(Request $request): AnonymousResourceCollection
    {
        $limit = min((int) $request->input('limit', 8), 12);

        $units = Unit::with(['images', 'features'])
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES) // #3 — only apartment|studio|villa
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->withCount(['bookings' => fn ($q) => $q->where('status', 'confirmed')])
            ->orderByDesc('bookings_count')
            ->orderByDesc('id')
            ->take($limit)
            ->get();

        return UnitResource::collection($units);
    }

    /**
     * Destination categories with live unit counts (اكتشف وجهتك).
     */
    public function categories(): JsonResponse
    {
        $counts = Unit::query()
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES) // #3 — only apartment|studio|villa
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->selectRaw('unit_type, COUNT(*) as total')
            ->groupBy('unit_type')
            ->pluck('total', 'unit_type');

        // One representative main image per type (falls back to curated artwork).
        $typeImages = $this->representativeImages();

        $data = array_map(function (array $cat) use ($counts, $typeImages) {
            $type = $cat['types'][0];

            return [
                'key'       => $cat['key'],
                'label'     => $cat['label'],
                'icon'      => $cat['icon'],
                'count'     => collect($cat['types'])->sum(fn ($t) => (int) ($counts[$t] ?? 0)),
                'image_url' => $typeImages[$type] ?? \App\Support\Media::defaultImageUrl(),
            ];
        }, self::CATEGORIES);

        return response()->json(['data' => $data]);
    }

    /**
     * Cities with live unit counts (البحث حسب الموقع), most stocked first.
     */
    public function cities(): JsonResponse
    {
        $cities = Unit::query()
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES) // #3 — only apartment|studio|villa
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->whereNotNull('city')
            ->selectRaw('city, COUNT(*) as total')
            ->groupBy('city')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['city' => $row->city, 'count' => (int) $row->total]);

        return response()->json(['data' => $cities]);
    }

    /**
     * Budget ranges with live availability counts (حسب الميزانية). Each count
     * mirrors the min_price/max_price filter so it equals the filtered result.
     */
    public function budgets(): JsonResponse
    {
        $data = array_map(function (array $bucket) {
            $query = Unit::query()
                ->whereIn('unit_type', Unit::SUPPORTED_TYPES) // #3 — only apartment|studio|villa
                ->where('approval_status', 'approved')
                ->where('status', 'available');

            if ($bucket['min'] !== null) {
                $query->where('price', '>=', $bucket['min']);
            }
            if ($bucket['max'] !== null) {
                $query->where('price', '<=', $bucket['max']);
            }

            // Representative image = cheapest available unit's main image in the
            // bucket; falls back to the bundled default (same asset as elsewhere).
            $image = (clone $query)
                ->whereHas('mainImage')
                ->with('mainImage')
                ->orderBy('price')
                ->first()?->mainImage->first()?->url;

            return [
                'key'       => $bucket['key'],
                'label'     => $bucket['label'],
                'min'       => $bucket['min'],
                'max'       => $bucket['max'],
                'count'     => $query->count(),
                'image_url' => $image ?? \App\Support\Media::defaultImageUrl(),
            ];
        }, self::BUDGET_BUCKETS);

        return response()->json(['data' => $data]);
    }

    /**
     * One representative main-image URL per supported type, drawn from live
     * inventory. Keyed by unit_type; missing types fall back in categories().
     *
     * @return array<string, string>
     */
    private function representativeImages(): array
    {
        $units = Unit::query()
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES)
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->whereHas('mainImage')
            ->with('mainImage')
            ->latest('id')
            ->get(['id', 'unit_type']);

        $map = [];
        foreach ($units as $unit) {
            $type = $unit->unit_type;
            if (! isset($map[$type]) && ($img = $unit->mainImage->first())) {
                $map[$type] = $img->url;
            }
        }

        return $map;
    }

    public function show(Unit $unit): UnitResource|JsonResponse
    {
        if (! in_array($unit->unit_type, Unit::SUPPORTED_TYPES, true)
            || $unit->approval_status !== 'approved'
            || $unit->status !== 'available') {
            return response()->json(['message' => 'الوحدة غير متاحة'], 404);
        }

        $unit->load(['images', 'features', 'owner', 'reviews.user']);

        return new UnitResource($unit);
    }

    public function checkAvailability(Request $request, Unit $unit): JsonResponse
    {
        $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date'   => ['required', 'date', 'after:start_date'],
        ]);

        $conflict = Booking::where('unit_id', $unit->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($q) use ($request) {
                $q->whereBetween('start_date', [$request->start_date, $request->end_date])
                  ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                  ->orWhere(function ($inner) use ($request) {
                      $inner->where('start_date', '<=', $request->start_date)
                            ->where('end_date', '>=', $request->end_date);
                  });
            })
            ->exists();

        return response()->json(['available' => ! $conflict]);
    }

    /**
     * List a unit's reviews (newest first). Public — shown on the unit detail
     * page. Shape matches the review object the frontend adapter expects.
     */
    public function reviews(Unit $unit): JsonResponse
    {
        $reviews = $unit->reviews()
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(fn ($r) => [
                'id'         => (string) $r->id,
                'booking_id' => (string) $r->booking_id,
                'unit_id'    => (string) $r->unit_id,
                'user_id'    => (string) $r->user_id,
                'user_name'  => $r->user?->name,
                'rating'     => $r->rating,
                'comment'    => $r->comment,
                'created_at' => $r->created_at,
            ]);

        return response()->json($reviews);
    }
}
