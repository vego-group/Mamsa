<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Support\AdminPanel\UnitPresenter;
use App\Support\City;
use App\Support\Pricing;
use App\Exceptions\AdminPanelException;
use App\Support\Units\LicenseViolation;
use App\Support\Units\UnitCloner;
use App\Support\Units\UnitLicense;
use App\Support\Units\UnitWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Units (properties) — BACKEND_SPEC §5.6. Shapes live in UnitPresenter (shared
 * with the approvals queue). Occupancy = confirmed booked-nights / 90-day window.
 */
class UnitsController extends Controller
{
    private const SORT = [
        'pricePerNight' => 'price',
        'rating' => 'rating',
        'occupancyRate' => 'booked_nights',
        'revenue' => 'revenue',
        'bookingsCount' => 'bookings_count',
        'name' => 'unit_name',
        'createdAt' => 'created_at',
    ];

    public function __construct(private readonly UnitPresenter $units) {}

    public function index(Request $request): JsonResponse
    {
        $args = $this->listArgs($request);
        $query = $this->units->baseQuery();

        if ($status = $this->cleanParam($request->query('status'))) {
            $query->where('approval_status', $this->units->internalStatus($status));
        }
        if ($type = $this->cleanParam($request->query('type'))) {
            $query->where('unit_type', $type === 'hotel_room' ? 'hotel' : $type);
        }
        if ($city = $this->cleanParam($request->query('city'))) {
            City::filter($query, 'city', $city);
        }
        if ($partnerId = $this->cleanParam($request->query('partnerId'))) {
            $query->where('user_id', $partnerId);
        }

        $page = $this->queryList($query, $args, ['unit_name', 'code', 'city'], self::SORT, ['created_at', 'desc']);

        return $this->items($page, fn (Unit $u) => $this->units->card($u));
    }

    public function stats(): JsonResponse
    {
        $since = now()->subDays(UnitPresenter::OCCUPANCY_WINDOW)->toDateString();
        $approved = Unit::where('approval_status', 'approved')->count();
        $bookedNights = (int) Booking::query()->revenue()->where('start_date', '>=', $since)
            ->selectRaw($this->nightsSql().' as n')->value('n');

        return response()->json([
            'total' => Unit::count(),
            'approved' => $approved,
            'pendingReview' => Unit::where('approval_status', 'pending')->count(),
            'avgOccupancy' => $approved > 0 ? min(100, (int) round(($bookedNights / ($approved * UnitPresenter::OCCUPANCY_WINDOW)) * 100)) : 0,
            'totalRevenue' => $this->money(Booking::query()->revenue()->sum('total_amount')),
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
     * goes through the same review pipeline as partner units. Owner = the
     * platform account ({@see User::platform()}; units.user_id is NOT NULL);
     * mamsa_owned flags it as platform-owned, which is what stops the booking
     * engine paying a partner share on it ({@see Pricing::breakdown()},
     * {@see \App\Services\PartnerWalletService::recordEarning()}).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateUnit($request, required: true);
        $this->assertFilesOwned($request, $data);

        $unit = Unit::create(array_merge(UnitWriter::toColumns($data), [
            // Owned by the platform account, not by whoever is typing. The
            // admin's own id here put an employee's name on the storefront as
            // host, and would have attributed platform revenue to them in any
            // query joining on the owner. Who created it is the audit trail's
            // business; who owns it is Mamsa.
            'user_id' => User::platform()->getKey(),
            'mamsa_owned' => true,
            'code' => UnitWriter::uniqueCode(),
            'approval_status' => 'draft',
            'status' => 'available',
            'calendar_token' => Str::random(60),
            // A listing with one bedroom sleeps at least one; the console has no
            // separate "beds" input, so seed it from bedrooms and let an edit
            // correct it. Without this every admin unit fails the submit gate.
            'beds' => $data['beds'] ?? max(1, (int) ($data['bedrooms'] ?? 1)),
        ]));

        UnitWriter::syncAmenities($unit, $data);
        UnitWriter::syncPhotos((int) $request->user()->id, $unit, $data);

        // Same as update(): the licence goes through its single writer rather
        // than being silently discarded by toColumns().
        $license = [];

        foreach (['licenseType' => 'license_type', 'licensedUnitsCount' => 'licensed_units_count'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $license[$column] = $data[$input];
            }
        }

        if ($license !== []) {
            try {
                UnitLicense::applyToGroup($unit, $license);
            } catch (LicenseViolation $e) {
                $this->fail($e->reason, $e->getMessage(), 422);
            }
        }

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

        // Licence columns have one writer and are group-wide. Until this, the
        // admin console ACCEPTED licenseType/licensedUnitsCount (the shared
        // rules validate them) and then dropped them, because toColumns()
        // deliberately does not map them — a 200 with nothing saved. For a
        // Mamsa-owned listing this was the only surface that could ever set a
        // licence at all, so no platform building could be classified.
        $license = [];

        foreach (['licenseType' => 'license_type', 'licensedUnitsCount' => 'licensed_units_count'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $license[$column] = $data[$input];
            }
        }

        if ($license !== []) {
            try {
                UnitLicense::applyToGroup($unit, $license);
            } catch (LicenseViolation $e) {
                $this->fail($e->reason, $e->getMessage(), 422);
            }
        }
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
        UnitWriter::syncAmenities($unit, $data);
        // Uploads belong to whoever presigned them — the acting admin — not to
        // the unit's owner. With the owner id here, assertFilesOwned() passed
        // (it checks the acting admin) and syncPhotos() then found nothing it
        // recognised: the gallery was cleared and nothing re-attached, behind
        // a 200. It only ever worked because owner and editor were the same
        // person; a second admin editing, or the platform account owning,
        // breaks that coincidence.
        UnitWriter::syncPhotos((int) $request->user()->id, $unit, $data);

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
            'name.required' => 'اسم الوحدة مطلوب',
            'type.required' => 'نوع الوحدة مطلوب',
            'type.in' => 'نوع الوحدة غير صالح — المدعوم: شقة، استوديو، فيلا',
            'city.required' => 'المدينة مطلوبة',
            'district.required' => 'الحي مطلوب',
            'pricePerNight.required' => 'سعر الليلة مطلوب',
            'bedrooms.required' => 'عدد غرف النوم مطلوب',
            'bathrooms.required' => 'عدد دورات المياه مطلوب',
            'capacity.required' => 'السعة مطلوبة',
            'sizeSqm.required' => 'المساحة مطلوبة',
            'description.max' => 'الوصف يجب ألا يتجاوز '.UnitWriter::MAX_DESCRIPTION.' حرف',
            'amenities.*.in' => 'إحدى المرافق غير معروفة',
            'checkIn.date_format' => 'صيغة وقت الدخول يجب أن تكون HH:mm',
            'checkOut.date_format' => 'صيغة وقت الخروج يجب أن تكون HH:mm',
            'photoFileIds.max' => 'الحد الأقصى 10 صور',
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
    /**
     * POST /admin/units/:id/apartments — turn a Mamsa-owned listing into a
     * building of `count` apartments.
     *
     * The platform's own duplicated studios were being added one by one through
     * the wizard — the exact work the group model exists to remove, on the one
     * surface that had no entry point for it.
     *
     * The apartments are FILED FOR REVIEW, not published. The first draft of
     * this route created them approved — "an admin filing units for an admin
     * to approve is theatre" — and that held only while Mamsa was assumed to be
     * the permit holder. The first real permit to reach the team (19/09) was a
     * third party's: a natural person's private-hospitality licence, with an
     * expiry date, a specific address and a stated capacity. Those are facts a
     * reviewer checks against the listing, and none of them is checked by code.
     * So the copies go through the same queue partner units do. If management
     * later exempts platform listings, `pending` below becomes `approved` and
     * nothing else moves; the reverse — apartments sold under an expired permit
     * — is not undone by a one-line change.
     *
     * What IS enforced here, before anything is written: the source must itself
     * be approved (the copies are of a published listing, not of a draft), must
     * pass the same completeness gate a submission passes, and the permit must
     * cover the building.
     *
     * `count` is the TOTAL the building should hold, not an addition — the same
     * meaning as the partner surface, so a console reusing that component does
     * not double a building by resending a number. Shrinking is refused by the
     * cloner: an apartment may already hold a booking.
     *
     * Restricted to `mamsa_owned`. A partner's building is theirs to expand,
     * from their dashboard, under their permit; an admin doing it for them
     * would file apartments the partner never declared.
     *
     * Not behind `units.multi_unit_enabled`: that flag gates the PARTNER
     * rollout ({@see \App\Support\Units\UnitLicense::guardLicenceCovers()}).
     */
    public function apartments(Request $request, string $id): JsonResponse
    {
        $unit = $this->findUnit($id);

        if (! $unit->mamsa_owned) {
            $this->fail('NOT_MAMSA_OWNED', 'هذا المسار لوحدات ممسى فقط — وحدات الشركاء يوسّعها الشريك من لوحته', 403);
        }

        if ($unit->approval_status !== 'approved') {
            // Expansion is of a PUBLISHED listing. Copies of a draft would reach
            // the reviewer while their source never had — five pending doors
            // and a source nobody filed. Approve it first, then build.
            $this->fail('SOURCE_NOT_APPROVED', 'اعتمد الوحدة الأصلية أولاً — التوسيع يكون لوحدة منشورة', 409);
        }

        $data = $this->validate($request, [
            'count' => ['required', 'integer', 'min:1', 'max:'.UnitCloner::MAX_GROUP],
        ], [
            'count.required' => 'عدد الوحدات مطلوب',
            'count.integer' => 'عدد الوحدات يجب أن يكون رقماً صحيحاً',
            'count.min' => 'عدد الوحدات يجب أن يكون 1 على الأقل',
            'count.max' => 'الحد الأقصى '.UnitCloner::MAX_GROUP.' وحدة في المبنى الواحد',
        ]);

        $count = (int) $data['count'];

        // The permit is checked BEFORE anything is written — a refused
        // expansion must not leave approved apartments behind.
        try {
            UnitLicense::guardLicenceCovers($unit, $count);
        } catch (LicenseViolation $e) {
            $this->fail($e->reason, $e->getMessage(), 422, null, $e->meta);
        }

        // The SOURCE must be publishable before it is copied. Every apartment
        // inherits its fields, so a source missing its permit produces copies
        // missing it too, and filing then fails on rows nobody has seen yet.
        // Approval does not guarantee this: the gate lives at SUBMIT time, and
        // a row written straight to the database (seeder, migration, fixture)
        // can be approved without ever passing it. Production unit #39 is one.
        if ($missing = UnitWriter::submitErrors($unit)) {
            $this->fail(
                'SOURCE_UNIT_INCOMPLETE',
                'أكمل بيانات الوحدة الأصلية قبل إضافة وحدات إليها',
                422,
                $missing,
                ['unitId' => (string) $unit->id],
            );
        }

        $before = UnitLicense::groupSize($unit);

        // Create and file in one transaction, as the partner surface does: a
        // failure halfway must not leave apartments on nobody's screen.
        // Documents travel with the apartments — expansion requires a facility
        // permit, and a facility permit is issued to the property, so it covers
        // every door in it.
        $group = DB::transaction(function () use ($unit, $count) {
            $group = UnitCloner::ensureTotal($unit, $count, copyDocuments: true);

            foreach ($group as $member) {
                if ($member->approval_status !== 'draft') {
                    continue; // the source, or an apartment from an earlier expansion
                }

                // Each copy meets the same gate a manual submit passes — a
                // source that passed it a moment ago produces copies that
                // pass it too, but "should" is not a reason to skip the
                // check. A failure rolls the whole expansion back.
                if ($errors = UnitWriter::submitErrors($member)) {
                    throw new AdminPanelException(
                        'APARTMENT_INCOMPLETE',
                        'تعذّر إنشاء الوحدات — إحدى النسخ غير مكتملة',
                        422,
                        $errors,
                        ['apartmentNo' => $member->apartment_no],
                    );
                }

                // `submitted_at` and the reviewer's feed entry come from
                // UnitApprovalObserver on the way into `pending`, the same as
                // every other path that files a unit.
                $member->update(['approval_status' => 'pending', 'rejection_reason' => null]);
            }

            return $group;
        });

        // Reload so the response reports the state AFTER filing.
        if ($groupId = $unit->fresh()->unit_group_id) {
            $group = Unit::where('unit_group_id', $groupId)->orderBy('apartment_no')->get();
        }

        $added = max(0, $group->count() - $before);

        return response()->json([
            'groupId' => $unit->fresh()->unit_group_id,
            // What the building holds now — not what was asked for.
            'groupSize' => $group->count(),
            'added' => $added,
            'units' => $group->map(fn (Unit $u) => [
                'id' => (string) $u->id,
                'apartmentNo' => $u->apartment_no,
                'status' => $u->approval_status,
            ])->values()->all(),
            'message' => $added > 0
                ? 'تمت إضافة '.$added.' وحدة وهي قيد المراجعة. الوحدة الأصلية تستمر في استقبال الحجوزات.'
                : 'المبنى يحتوي بالفعل على هذا العدد',
        ]);
    }

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
