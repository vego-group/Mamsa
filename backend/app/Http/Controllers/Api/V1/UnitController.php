<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Booking;
use App\Models\Unit;
use App\Support\Booking\Availability;
use App\Support\Pricing;
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
        // `owner.partnerDetail` is loaded here too: without it every unit card
        // rendered from this list showed a blank host and an unlit verification
        // badge, because UnitResource only emits `owner` when the relation is
        // present.
        $query = Unit::with(['images', 'features', 'cancellationPolicy.tiers', 'owner.partnerDetail'])
            ->withCount('reviews')
            ->withAvg('reviews as reviews_avg_rating', 'rating')
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
        // Slug (`riyadh`), English (`Riyadh`) or Arabic (`الرياض`) all resolve to
        // the stored Arabic value. An exact match on the raw input worked only
        // for clients that already spoke the stored spelling, and failed as
        // "no results" rather than as an error — indistinguishable, from the
        // outside, from a city with nothing listed in it.
        if ($request->filled('city')) {
            \App\Support\City::filter($query, 'city', (string) $request->city);
        }
        // Fetch a known set — the favourites page. Capped at the maximum page
        // size so one request with `per_page=50` always returns everything it
        // asked for; a longer list is the caller's to chunk.
        if ($request->filled('ids')) {
            $ids = $request->validate([
                'ids'   => ['array', 'max:50'],
                'ids.*' => ['integer'],
            ])['ids'];

            $query->whereIn('units.id', $ids);
        }
        if ($request->filled('type')) {
            $query->where('unit_type', $request->type);
        }
        // Home "وحدات مميزة" section (§2.1): ?featured=1.
        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
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
        // selected amenity. Accepts stable SLUGS (wifi, ac, …) and expands
        // each to all stored spellings (تكييف/مكيف) so slug filtering returns
        // the right set; raw labels still work as a fallback.
        if ($request->filled('features')) {
            foreach ((array) $request->features as $feature) {
                $labels = \App\Support\Dashboard\Maps::filterLabels((string) $feature);
                $query->whereHas('features', fn ($q) => $q->whereIn('name', $labels));
            }
        }

        // Availability window (§2.1). Previously accepted and ignored, so a
        // search could show a fully booked unit under a banner promising it was
        // free for those exact nights.
        // `nullable`, not `sometimes`: `sometimes` skips a field that is absent,
        // which skips `required_with` with it — so half a window passed
        // validation and was then silently ignored, which is exactly the
        // failure this section exists to remove.
        $dates = $request->validate([
            'start_date' => ['nullable', 'required_with:end_date', 'date'],
            'end_date'   => ['nullable', 'required_with:start_date', 'date', 'after:start_date'],
        ]);

        if (isset($dates['start_date'], $dates['end_date'])) {
            Availability::onlyFree($query, $dates['start_date'], $dates['end_date']);
        }

        self::applySort($query, (string) $request->query('sort', ''));

        // Caller-controlled page size, capped: an uncapped one is a way to ask
        // for the entire table in a single query.
        $perPage = min(max((int) $request->query('per_page', 12), 1), 50);

        return UnitResource::collection($query->paginate($perPage));
    }

    /**
     * Ordering for the search listing.
     *
     * Every branch ends with `id`, including the default. Without a unique
     * tiebreaker the database is free to return rows in any order it likes for
     * equal keys — and it need not pick the same order twice, so paging through
     * results could show one unit on two pages and never show another at all.
     */
    private static function applySort(\Illuminate\Database\Eloquent\Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'newest'     => $query->orderByDesc('created_at'),
            'rating'     => $query->orderByDesc(
                \Illuminate\Support\Facades\DB::raw('(select coalesce(avg(rating), 0) from reviews where reviews.unit_id = units.id)')
            ),
            // Unrecognised or absent → featured first, then newest. This is the
            // shape the storefront calls "موصى به".
            default      => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
        };

        $query->orderBy('units.id');
    }

    /**
     * Most-requested units (الأكثر طلباً) — ranked by confirmed booking volume,
     * then rating. Powers the curated home-page rail.
     */
    public function popular(Request $request): AnonymousResourceCollection
    {
        $limit = min((int) $request->input('limit', 8), 12);

        $units = Unit::with(['images', 'features', 'cancellationPolicy.tiers', 'owner.partnerDetail'])
            ->whereIn('unit_type', Unit::SUPPORTED_TYPES) // #3 — only apartment|studio|villa
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->withCount('reviews')
            ->withAvg('reviews as reviews_avg_rating', 'rating')
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

        $data = array_map(function (array $cat) use ($counts) {
            return [
                'key'       => $cat['key'],
                'label'     => $cat['label'],
                'icon'      => $cat['icon'],
                'count'     => collect($cat['types'])->sum(fn ($t) => (int) ($counts[$t] ?? 0)),
                // Single bundled default image for every category.
                'image_url' => \App\Support\Media::defaultImageUrl(),
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

            return [
                'key'       => $bucket['key'],
                'label'     => $bucket['label'],
                'min'       => $bucket['min'],
                'max'       => $bucket['max'],
                'count'     => $query->count(),
                // Single bundled default image for every budget bucket.
                'image_url' => \App\Support\Media::defaultImageUrl(),
            ];
        }, self::BUDGET_BUCKETS);

        return response()->json(['data' => $data]);
    }

    public function show(Unit $unit): UnitResource|JsonResponse
    {
        if (! in_array($unit->unit_type, Unit::SUPPORTED_TYPES, true)
            || $unit->approval_status !== 'approved'
            || $unit->status !== 'available') {
            return response()->json(['message' => 'الوحدة غير متاحة'], 404);
        }

        $unit->load(['images', 'features', 'owner.partnerDetail', 'reviews.user', 'cancellationPolicy.tiers']);

        return new UnitResource($unit);
    }

    public function checkAvailability(Request $request, Unit $unit): JsonResponse
    {
        $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date'   => ['required', 'date', 'after:start_date'],
        ]);

        // Same predicate POST /bookings enforces — see Availability. A probe
        // that disagreed with the create is how a guest loses a booking at the
        // last step, so there is one definition and both read it.
        $available = ! Availability::isTaken($unit, $request->start_date, $request->end_date);
        $payload   = ['available' => $available];

        // Server-computed breakdown for the checkout page — the exact same
        // math POST /bookings freezes, so the frontend never does money math.
        // Commission lines are partner-facing and stay out of this public payload.
        if ($available) {
            $nights = (int) now()->parse($request->start_date)->diffInDays($request->end_date);

            // Internal settlement figures stay out of this public payload
            // (contract §1.7, §7): a guest never sees the platform's margin.
            $payload['pricing'] = \Illuminate\Support\Arr::except(
                Pricing::breakdown((float) $unit->price, $nights),
                ['commission_rate', 'commission_amount', 'partner_share'],
            );
        }

        return response()->json($payload);
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
    /**
     * GET /units/sitemap
     *
     * Every publicly reachable unit, as `{ id, updated_at }`. No pagination and
     * no other field: a sitemap builder needs a complete list in one pass, and
     * paging it would mean the last page decides whether a unit gets indexed.
     */
    public function sitemap(): JsonResponse
    {
        return response()->json(
            Unit::query()
                ->whereIn('unit_type', Unit::SUPPORTED_TYPES)
                ->where('approval_status', 'approved')
                ->where('status', 'available')
                ->orderBy('id')
                ->get(['id', 'updated_at'])
                ->map(fn (Unit $u) => [
                    'id'         => (int) $u->id,
                    'updated_at' => $u->updated_at?->toIso8601ZuluString(),
                ])
                ->all(),
        );
    }

    /**
     * GET /units/{unit}/blocked-dates?from=&to=
     *
     * The dates a guest cannot pick, so the calendar can grey them out instead
     * of letting someone choose them, fill in their details, and be refused at
     * checkout. Ranges are INCLUSIVE of both ends and already merged.
     *
     * Bookings and partner closures are deliberately not distinguished: the
     * guest only needs to know a date is unavailable, and saying which would
     * publish how busy a partner's unit is to anyone who asks.
     */
    public function blockedDates(Request $request, Unit $unit): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        // Defaults cover the window a picker can realistically show; a wider
        // one is allowed but capped so a single call cannot scan years.
        $from = isset($data['from']) ? now()->parse($data['from']) : now();
        $to   = isset($data['to']) ? now()->parse($data['to']) : (clone $from)->addMonths(6);

        if ($to->diffInDays($from) > 400) {
            $to = (clone $from)->addDays(400);
        }

        // Flat, like the sibling /availability endpoint — two envelopes on
        // adjacent routes is a needless branch on the client.
        return response()->json([
            'from'    => $from->toDateString(),
            'to'      => $to->toDateString(),
            'blocked' => Availability::blockedRanges($unit, $from->toDateString(), $to->toDateString()),
        ]);
    }

}
