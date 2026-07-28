<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use App\Models\Unit;
use App\Support\AdminPanel\UnitPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Units (properties) — BACKEND_SPEC §5.6. Shapes live in UnitPresenter (shared
 * with the approvals queue). Occupancy = confirmed booked-nights / 90-day window.
 */
class UnitsController extends Controller
{
    private const SORT = [
        'pricePerNight'  => 'price',
        'rating'         => 'rating',
        'occupancyRate'  => 'booked_nights',
        'revenue'        => 'revenue',
        'bookingsCount'  => 'bookings_count',
        'name'           => 'unit_name',
        'createdAt'      => 'created_at',
    ];

    public function __construct(private readonly UnitPresenter $units) {}

    public function index(Request $request): JsonResponse
    {
        $args  = $this->listArgs($request);
        $query = $this->units->baseQuery();

        if ($status = $this->cleanParam($request->query('status'))) {
            $query->where('approval_status', $this->units->internalStatus($status));
        }
        if ($type = $this->cleanParam($request->query('type'))) {
            $query->where('unit_type', $type === 'hotel_room' ? 'hotel' : $type);
        }
        if ($city = $this->cleanParam($request->query('city'))) {
            $query->where('city', $city);
        }
        if ($partnerId = $this->cleanParam($request->query('partnerId'))) {
            $query->where('user_id', $partnerId);
        }

        $page = $this->queryList($query, $args, ['unit_name', 'code', 'city'], self::SORT, ['created_at', 'desc']);

        return $this->items($page, fn (Unit $u) => $this->units->card($u));
    }

    public function stats(): JsonResponse
    {
        $since        = now()->subDays(UnitPresenter::OCCUPANCY_WINDOW)->toDateString();
        $approved     = Unit::where('approval_status', 'approved')->count();
        $bookedNights = (int) Booking::query()->revenue()->where('start_date', '>=', $since)
            ->selectRaw($this->nightsSql().' as n')->value('n');

        return response()->json([
            'total'         => Unit::count(),
            'approved'      => $approved,
            'pendingReview' => Unit::where('approval_status', 'pending')->count(),
            'avgOccupancy'  => $approved > 0 ? min(100, (int) round(($bookedNights / ($approved * UnitPresenter::OCCUPANCY_WINDOW)) * 100)) : 0,
            'totalRevenue'  => $this->money(Booking::query()->revenue()->sum('total_amount')),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $u = $this->units->baseQuery()->with(['features', 'owner.partnerDetail'])->whereKey($id)->first();

        if (! $u) {
            $this->fail('NOT_FOUND', 'الوحدة غير موجودة', 404);
        }

        return response()->json($this->units->detail($u));
    }

    /* ---------- mutations §5.6 ---------- */

    /**
     * POST /admin/units — create a Mamsa-owned listing. It starts as a draft and
     * goes through the same review pipeline as partner units. Owner = the acting
     * admin (units.user_id is NOT NULL); mamsa_owned flags it as platform-owned.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'name'          => ['required', 'string', 'max:255'],
            'type'          => ['required', Rule::in(['apartment', 'villa', 'chalet', 'studio', 'hotel_room'])],
            'city'          => ['required', 'string', 'max:100'],
            'district'      => ['required', 'string', 'max:150'],
            'pricePerNight' => ['required', 'numeric', 'min:0'],
            'bedrooms'      => ['required', 'integer', 'min:0'],
            'bathrooms'     => ['required', 'integer', 'min:0'],
            'capacity'      => ['required', 'integer', 'min:1'],
            'sizeSqm'       => ['required', 'numeric', 'min:0'],
        ]);

        Unit::create([
            'user_id'         => $request->user()->getKey(),
            'mamsa_owned'     => true,
            'unit_name'       => $data['name'],
            'unit_type'       => $data['type'] === 'hotel_room' ? 'hotel' : $data['type'],
            'code'            => 'MRN'.now()->format('ymd').Str::upper(Str::random(4)),
            'price'           => $data['pricePerNight'],
            'bedrooms'        => $data['bedrooms'],
            'beds'            => $data['bedrooms'],
            'bathrooms'       => $data['bathrooms'],
            'capacity'        => $data['capacity'],
            'area'            => $data['sizeSqm'],
            'city'            => $data['city'],
            'district'        => $data['district'],
            'approval_status' => 'draft',
            'status'          => 'available',
            'calendar_token'  => Str::random(60),
        ]);

        return $this->ok(201);
    }

    /** POST /admin/units/:id/unpublish — { reason }, approved → rejected (off the public site). */
    public function unpublish(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'يجب إدخال سبب إلغاء النشر']);

        $unit = Unit::find($id);

        if (! $unit) {
            $this->fail('NOT_FOUND', 'الوحدة غير موجودة', 404);
        }
        if ($unit->approval_status !== 'approved') {
            $this->fail('CONFLICT', 'الوحدة ليست منشورة', 409);
        }

        $unit->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason']]);

        return $this->ok();
    }
}
