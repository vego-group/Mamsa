<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use App\Models\Unit;
use App\Support\AdminPanel\UnitPresenter;
use App\Support\Units\UnitWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            \App\Support\City::filter($query, 'city', $city);
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
        $u = $this->units->baseQuery()->with(['features', 'owner.partnerDetail', 'cancellationPolicy'])->whereKey($id)->first();

        if (! $u) {
            $this->fail('NOT_FOUND', 'الوحدة غير موجودة', 404);
        }

        return response()->json($this->units->detail($u));
    }

    /* ---------- mutations §5.6 ---------- */

    /**
     * POST /admin/units — create a Mamsa-owned listing. It starts as a draft and
     * goes through the same review pipeline as partner units. Owner = the acting
     * admin (units.user_id is NOT NULL); mamsa_owned flags it as platform-owned,
     * which is what stops the booking engine paying a 98% share to an admin who
     * is not a partner ({@see \App\Support\Pricing::breakdown()}).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateUnit($request, required: true);
        $this->assertFilesOwned($request, $data);

        $unit = Unit::create(array_merge(UnitWriter::toColumns($data), [
            'user_id'         => $request->user()->getKey(),
            'mamsa_owned'     => true,
            'code'            => UnitWriter::uniqueCode(),
            'approval_status' => 'draft',
            'status'          => 'available',
            'calendar_token'  => Str::random(60),
            // A listing with one bedroom sleeps at least one; the console has no
            // separate "beds" input, so seed it from bedrooms and let an edit
            // correct it. Without this every admin unit fails the submit gate.
            'beds'            => $data['beds'] ?? max(1, (int) ($data['bedrooms'] ?? 1)),
        ]));

        UnitWriter::syncAmenities($unit, $data['amenities'] ?? null);
        UnitWriter::syncPhotos((int) $request->user()->id, $unit, $data);

        return response()->json($this->units->detail($this->reload($unit)), 201);
    }

    /**
     * PATCH /admin/units/:id — partial edit. An absent key means "unchanged",
     * never "blank it".
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $unit = $this->findUnit($id);

        // Mirrors the partner rule: a unit sitting in the review queue must not
        // change under the reviewer's feet.
        if ($unit->approval_status === 'pending') {
            $this->fail('CONFLICT', 'لا يمكن تعديل وحدة قيد المراجعة', 409);
        }

        $data = $this->validateUnit($request, required: false);
        $this->assertFilesOwned($request, $data);

        $columns = UnitWriter::toColumns($data);

        // An edited approved unit goes back for review and leaves the public
        // site — the same rule partner units follow, for the same reason: what
        // was approved is no longer what is published.
        $wasApproved = $unit->approval_status === 'approved';
        if ($wasApproved) {
            $columns['approval_status'] = 'pending';
        }

        $unit->update($columns);
        UnitWriter::syncAmenities($unit, $data['amenities'] ?? null);
        UnitWriter::syncPhotos((int) $unit->user_id, $unit, $data);

        return response()->json($this->units->detail($this->reload($unit)));
    }

    /** DELETE /admin/units/:id — drafts only; anything further along has history. */
    public function destroy(string $id): JsonResponse
    {
        $unit = $this->findUnit($id);

        if ($unit->approval_status !== 'draft') {
            $this->fail('CONFLICT', 'يمكن حذف المسودات فقط', 409);
        }

        $unit->delete();

        return $this->ok();
    }

    /**
     * POST /admin/units/:id/submit — draft → pending_review, into the same queue
     * partner units go through.
     *
     * Mamsa reviewing its own listing is not the point; the completeness gate
     * is. Photos, a permit and a description are what make a listing publishable
     * at all, and this is the single place that enforces them.
     */
    public function submit(string $id): JsonResponse
    {
        $unit = $this->findUnit($id);

        if (! in_array($unit->approval_status, ['draft', 'rejected'], true)) {
            $this->fail('CONFLICT', 'لا يمكن تقديم هذه الوحدة', 409);
        }

        if ($fields = UnitWriter::submitErrors($unit)) {
            $this->fail('VALIDATION_ERROR', 'بيانات غير مكتملة', 422, $fields);
        }

        $unit->update(['approval_status' => 'pending', 'rejection_reason' => null]);

        return response()->json($this->units->detail($this->reload($unit)));
    }

    /* ---- shared helpers ---- */

    /**
     * The nine fields the console has always sent stay required; everything the
     * listing wizard added is optional, so a half-finished draft can be saved.
     *
     * @return array<string, mixed>
     */
    private function validateUnit(Request $request, bool $required): array
    {
        $rules = UnitWriter::rules(required: false);

        if ($required) {
            foreach (['name', 'type', 'city', 'district', 'pricePerNight', 'bedrooms', 'bathrooms', 'capacity', 'sizeSqm'] as $key) {
                $rules[$key][0] = 'required';
            }
        }

        return $this->validate($request, $rules, [
            'name.required'          => 'اسم الوحدة مطلوب',
            'type.required'          => 'نوع الوحدة مطلوب',
            'type.in'                => 'نوع الوحدة غير صالح — المدعوم: شقة، استوديو، فيلا',
            'city.required'          => 'المدينة مطلوبة',
            'district.required'      => 'الحي مطلوب',
            'pricePerNight.required' => 'سعر الليلة مطلوب',
            'bedrooms.required'      => 'عدد غرف النوم مطلوب',
            'bathrooms.required'     => 'عدد دورات المياه مطلوب',
            'capacity.required'      => 'السعة مطلوبة',
            'sizeSqm.required'       => 'المساحة مطلوبة',
            'description.max'        => 'الوصف يجب ألا يتجاوز '.UnitWriter::MAX_DESCRIPTION.' حرف',
            'amenities.*.in'         => 'إحدى المرافق غير معروفة',
            'checkIn.date_format'    => 'صيغة وقت الدخول يجب أن تكون HH:mm',
            'checkOut.date_format'   => 'صيغة وقت الخروج يجب أن تكون HH:mm',
            'photoFileIds.max'       => 'الحد الأقصى 10 صور',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function assertFilesOwned(Request $request, array $data): void
    {
        if ($errors = UnitWriter::fileErrors((int) $request->user()->id, $data)) {
            $this->fail('VALIDATION_ERROR', 'ملفات غير صالحة', 422, $errors);
        }
    }

    private function findUnit(string $id): Unit
    {
        $unit = Unit::find(Str::startsWith($id, 'u_') ? Str::after($id, 'u_') : $id);

        if (! $unit) {
            $this->fail('NOT_FOUND', 'الوحدة غير موجودة', 404);
        }

        return $unit;
    }

    private function reload(Unit $unit): Unit
    {
        return $this->units->baseQuery()
            ->with(['features', 'owner.partnerDetail', 'cancellationPolicy'])
            ->whereKey($unit->getKey())
            ->first() ?? $unit;
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
