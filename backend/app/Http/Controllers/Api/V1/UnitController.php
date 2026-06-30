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
        ['key' => 'villa',     'label' => 'فلل',      'icon' => 'villa',     'types' => ['villa']],
        ['key' => 'rest',      'label' => 'استراحات', 'icon' => 'cottage',   'types' => ['rest']],
        ['key' => 'chalet',    'label' => 'شاليهات',  'icon' => 'house',     'types' => ['chalet']],
        ['key' => 'resort',    'label' => 'منتجعات',  'icon' => 'pool',      'types' => ['resort']],
        ['key' => 'apartment', 'label' => 'شقق',      'icon' => 'apartment', 'types' => ['apartment', 'studio']],
        ['key' => 'camp',      'label' => 'مخيمات',   'icon' => 'camping',   'types' => ['camp']],
    ];

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
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->selectRaw('unit_type, COUNT(*) as total')
            ->groupBy('unit_type')
            ->pluck('total', 'unit_type');

        $data = array_map(static function (array $cat) use ($counts) {
            return [
                'key'   => $cat['key'],
                'label' => $cat['label'],
                'icon'  => $cat['icon'],
                'count' => collect($cat['types'])->sum(fn ($t) => (int) ($counts[$t] ?? 0)),
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
                ->where('approval_status', 'approved')
                ->where('status', 'available');

            if ($bucket['min'] !== null) {
                $query->where('price', '>=', $bucket['min']);
            }
            if ($bucket['max'] !== null) {
                $query->where('price', '<=', $bucket['max']);
            }

            return [
                'key'   => $bucket['key'],
                'label' => $bucket['label'],
                'min'   => $bucket['min'],
                'max'   => $bucket['max'],
                'count' => $query->count(),
            ];
        }, self::BUDGET_BUCKETS);

        return response()->json(['data' => $data]);
    }

    public function show(Unit $unit): UnitResource|JsonResponse
    {
        if ($unit->approval_status !== 'approved' || $unit->status !== 'available') {
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
}
