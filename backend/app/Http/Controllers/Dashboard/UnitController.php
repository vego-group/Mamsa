<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewUnitRequest;
use App\Support\Dashboard\UnitPresenter;
use App\Support\Permits\PermitMode;
use App\Support\Permits\PermitRenewal;
use App\Support\Permits\PermitWriter;
use App\Support\Units\ApartmentExpansion;
use App\Support\Units\LicenseViolation;
use App\Support\Units\UnitCloner;
use App\Support\Units\UnitLicense;
use App\Support\Units\UnitWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Units CRUD + lifecycle (contract §4). Status is never set directly by the
 * partner: create → draft; submit → pending; admin approve/reject; editing an
 * approved unit auto-reverts to pending and hides it from the public site.
 */
class UnitController extends DashboardController
{
    public function index(Request $request): JsonResponse
    {
        [$page, $limit] = $this->pageArgs($request);

        $query = $request->user()->units()
            ->with(['images', 'features', 'cancellationPolicy'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->latest();

        if ($status = $request->query('status')) {
            $query->where('approval_status', $status);
        }

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('unit_name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%"));
        }

        return $this->paginated(
            $query->paginate(perPage: $limit, page: $page),
            fn (Unit $u) => UnitPresenter::make($u),
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->ok(UnitPresenter::make($this->ownUnit($request, self::rawId($id))));
    }

    public function store(Request $request): JsonResponse
    {
        // Drafts don't validate required fields — only whatever is provided.
        $data = $this->validateUnit($request, required: false);
        $this->assertFilesOwned($request, $data);

        $unit = $request->user()->units()->create(array_merge(
            $this->toColumns($data),
            [
                'approval_status' => 'draft',
                'code' => self::uniqueCode(),
                'calendar_token' => Str::random(60),
            ],
        ));

        // The permit is written after the row exists, by its own writer: the
        // licence rules run there, so `tourist_facility` with no count is a
        // named 422 instead of the CHECK violation the insert would raise.
        if ($permit = UnitWriter::permitChanges($data)) {
            try {
                PermitWriter::apply($unit, $permit, (int) $request->user()->id);
            } catch (LicenseViolation $e) {
                $unit->delete(); // the draft never existed as far as the partner is concerned

                $this->fail($e->reason, $e->getMessage(), 422, null, $e->meta);
            }
        }

        UnitWriter::syncAmenities($unit, $data);
        $this->syncPhotos($request, $unit, $data);

        return $this->ok(UnitPresenter::make($unit->fresh(['images', 'features', 'cancellationPolicy'])), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        // §4 — editing is blocked while under review; allowed for draft/rejected/approved.
        if ($unit->approval_status === 'pending') {
            $this->fail('UNIT_LOCKED', 'لا يمكن تعديل وحدة قيد المراجعة', 409);
        }

        $data = $this->validateUnit($request, required: false);
        $this->assertFilesOwned($request, $data);

        // The permit (number, file, type, count) is one record covering the
        // whole scope, with a single writer — sending its fields through the
        // ordinary update would hit the model guard. This is also the ONLY way
        // a partner classifies a listing from this surface, and without a
        // classification no building can ever be expanded.
        if ($permit = UnitWriter::permitChanges($data)) {
            try {
                PermitWriter::apply($unit, $permit, (int) $request->user()->id);
            } catch (LicenseViolation $e) {
                $this->fail($e->reason, $e->getMessage(), 422, null, $e->meta);
            }
        }

        $columns = UnitWriter::toColumns($data, withPermit: false);

        // §4 — an approved unit edited → back to pending + hidden from the site.
        $wasApproved = $unit->approval_status === 'approved';
        if ($wasApproved) {
            $columns['approval_status'] = 'pending';
        }

        $unit->update($columns);
        UnitWriter::syncAmenities($unit, $data);
        $this->syncPhotos($request, $unit, $data);

        if ($wasApproved) {
            $this->notifyAdmins($unit);
        }

        return $this->ok(UnitPresenter::make($unit->fresh(['images', 'features', 'cancellationPolicy'])));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        // §4 — drafts only.
        if ($unit->approval_status !== 'draft') {
            $this->fail('UNIT_NOT_DELETABLE', 'يمكن حذف المسودات فقط', 409);
        }

        $unit->delete();

        return $this->ok();
    }

    /**
     * Turn one listing into a building of `count` identical apartments.
     *
     * The logic has existed and been tested since 2026-08-30, but only on the
     * Bearer `/api/v1` surface — this dashboard had no route for it at all, so
     * a partner with ten apartments could not reach the feature that exists for
     * them. This is that route, on the surface they actually use.
     *
     * `count` is a TOTAL, not an addition. "I have 8" on a building of 5 adds
     * three; sending 8 again adds nothing. The response says so in its own
     * fields rather than leaving it to a document: `groupSize` is what the
     * building now holds, and `added` is what this call created. The difference
     * between "I have 8" and "add 8" is the difference between a building of
     * eight and a building of thirteen.
     *
     * Only `count` is exposed here. The Bearer route also takes explicit door
     * numbers and ranges; those stay there until a screen actually asks for
     * them, rather than shipping three input shapes and discovering which one
     * partners use afterwards.
     */
    public function apartments(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        $data = $this->validated($request, self::apartmentRules(), [
            'count.required' => 'عدد الوحدات مطلوب',
            'count.max' => 'الحد الأقصى '.UnitCloner::MAX_GROUP.' وحدة في المبنى الواحد',
            'permits.*.number.required' => 'رقم التصريح مطلوب لكل وحدة',
        ]);

        $count = (int) $data['count'];
        $permits = $data['permits'] ?? [];

        // The request's shape has to match the mode the building is in: a
        // facility permit covers the new doors, a private one does not — and
        // sending the wrong half is a different mistake from sending too few.
        try {
            PermitMode::guardShape($unit, array_key_exists('permits', $data));
        } catch (LicenseViolation $e) {
            $this->fail($e->reason, $e->getMessage(), 422, null, $e->meta);
        }

        // Each apartment's licence file must be this partner's own, stored, and
        // of the licence kind — the same check every other attachment passes,
        // applied per entry so the answer names which one.
        $fileErrors = [];

        foreach ($permits as $i => $permit) {
            if ($errors = UnitWriter::fileErrors((int) $request->user()->id, ['tourismLicenseFileId' => $permit['fileId'] ?? null])) {
                $fileErrors["permits.{$i}.fileId"] = reset($errors);
            }
        }

        if ($fileErrors !== []) {
            $this->fail('VALIDATION', 'ملفات غير صالحة', 400, $fileErrors);
        }

        // The licence is checked BEFORE anything is written: a refused
        // expansion must not leave apartments behind for an admin to review.
        try {
            UnitLicense::guardGroupSize($unit, $count);
        } catch (LicenseViolation $e) {
            // meta carries the numbers the message talks about — the dashboard
            // renders "your permit covers 8 units" from the field, not by
            // reading the Arabic. Dropping it here was the whole point of the
            // refusal being machine-readable, undone one argument short.
            $this->fail($e->reason, $e->getMessage(), 422, null, $e->meta);
        }

        // The SOURCE must be publishable before it is copied.
        //
        // Every apartment inherits the source's fields, so a source missing its
        // permit produces copies missing it too, and auto-submit then fails on
        // rows the partner never saw — reporting a validation error about
        // `tourismLicenseFileId` on apartments that do not exist yet. The
        // partner reads that as "the system lost my documents".
        //
        // Checked here, the answer names the listing they actually have and the
        // fields it actually lacks. Approval does not guarantee this: the gate
        // lives in submitErrors() at SUBMIT time, so anything written straight
        // to the database — a seeder, a migration, a fixture — can be approved
        // without ever passing it.
        if ($missing = UnitWriter::submitErrors($unit)) {
            $this->fail(
                'SOURCE_UNIT_INCOMPLETE',
                'أكمل بيانات الوحدة الأصلية قبل إضافة وحدات إليها',
                422,
                $missing,
                ['unit_id' => 'u_'.$unit->id],
            );
        }

        /*
         * Create AND file, in ONE transaction.
         *
         * The partner pressed "add apartments" and typed a number — that IS the
         * declaration. Leaving the new rows as drafts to confirm three more
         * times hands back part of the work this feature exists to remove, and
         * doing those submits as separate calls from the browser means a
         * failure halfway leaves apartments on nobody's screen: not the
         * partner's, not the reviewer's queue.
         *
         * So it is atomic. If any apartment cannot be filed the whole expansion
         * rolls back, rather than leaving a building half filed and half
         * invisible.
         *
         * WHETHER THE DOCUMENTS TRAVEL is the licence mode's decision, not this
         * method's: a facility permit is issued to the property and covers
         * every door, so the clones copy it; a private permit names one unit,
         * so each new door arrives with its own and nothing is copied.
         * {@see ApartmentExpansion}
         */
        try {
            $result = DB::transaction(function () use ($unit, $count, $permits, $request) {
                $result = ApartmentExpansion::run($unit, $count, $permits, (int) $request->user()->id);

                foreach ($result['added'] as $member) {
                    if ($member->approval_status !== 'draft') {
                        continue; // already approved, or already waiting
                    }

                    // The same gate a manual submit passes — a clone that could
                    // not be filed on its own must not be filed in bulk either.
                    $this->assertSubmittable($request->user(), $member->fresh());

                    $member->update(['approval_status' => 'pending', 'rejection_reason' => null]);
                }

                return $result;
            });
        } catch (LicenseViolation $e) {
            $this->fail($e->reason, $e->getMessage(), 422, null, $e->meta);
        }

        $before = $result['before'];
        $group = $result['group'];

        // Reload so the response reports state AFTER filing rather than the
        // state the rows were created in — the client reads status, not assumes.
        if ($groupId = $unit->fresh()->unit_group_id) {
            $group = Unit::where('unit_group_id', $groupId)->orderBy('apartment_no')->get();
        }

        // Outside the transaction: a mail failure must not undo a filing that
        // actually happened.
        foreach ($group->where('approval_status', 'pending') as $pending) {
            $this->notifyAdmins($pending);
        }

        return $this->ok([
            'groupId' => $unit->fresh()->unit_group_id,
            // What the building holds now — not what was asked for. They differ
            // whenever `count` is at or below the current size.
            'groupSize' => $group->count(),
            'added' => max(0, $group->count() - $before),
            'units' => $group->map(fn (Unit $u) => [
                'id' => 'u_'.$u->id,
                'apartmentNo' => $u->apartment_no,
                'status' => $u->approval_status,
            ])->values()->all(),
            'message' => $group->count() > $before
                ? 'تمت إضافة '.($group->count() - $before).' وحدة وهي قيد المراجعة. مبناك الحالي يستمر في استقبال الحجوزات.'
                : 'المبنى يحتوي بالفعل على هذا العدد',
        ]);
    }

    /**
     * POST /units/:id/permit-renewals — file a new permit without taking the
     * listing down.
     *
     * The listing keeps selling on the permit in force, and its
     * `approval_status` is untouched: a renewal is one document being reviewed,
     * not the listing being reviewed again. {@see PermitRenewal}
     */
    public function renewPermit(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        $data = $this->validated($request, [
            'permitExpiresAt' => ['required', 'date_format:Y-m-d'],
            'tourismLicenseNumber' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tourismLicenseFileId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'permitAddress' => ['sometimes', 'array'],
            'permitAddress.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'permitAddress.district' => ['sometimes', 'nullable', 'string', 'max:150'],
            'permitAddress.building' => ['sometimes', 'nullable', 'string', 'max:50'],
            'permitAddress.unitNo' => ['sometimes', 'nullable', 'string', 'max:50'],
        ], [
            'permitExpiresAt.required' => 'تاريخ انتهاء التصريح الجديد مطلوب',
            'permitExpiresAt.date_format' => 'صيغة التاريخ يجب أن تكون YYYY-MM-DD',
        ]);

        // A file must be the partner's own, stored, and of the licence kind —
        // the same check every other attachment passes.
        if (array_key_exists('tourismLicenseFileId', $data) && filled($data['tourismLicenseFileId'])
            && ($errors = UnitWriter::fileErrors((int) $request->user()->id, ['tourismLicenseFileId' => $data['tourismLicenseFileId']]))) {
            $this->fail('VALIDATION', 'ملفات غير صالحة', 400, $errors);
        }

        $fields = array_filter([
            'expires_at' => $data['permitExpiresAt'],
            'number' => $data['tourismLicenseNumber'] ?? null,
            'file' => $data['tourismLicenseFileId'] ?? null,
            'addr_city' => $data['permitAddress']['city'] ?? null,
            'addr_district' => $data['permitAddress']['district'] ?? null,
            'addr_building' => $data['permitAddress']['building'] ?? null,
            'addr_unit_no' => $data['permitAddress']['unitNo'] ?? null,
        ], fn ($v) => $v !== null);

        try {
            $renewal = PermitRenewal::open($unit, $fields, (int) $request->user()->id);
        } catch (LicenseViolation $e) {
            $this->fail($e->reason, $e->getMessage(), 422, null, $e->meta);
        }

        return $this->ok($this->renewalShape($renewal), 201);
    }

    /** GET /units/:id/permit-renewals — what this listing has filed, newest first. */
    public function permitRenewals(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        $rows = Permit::query()->forUnit($unit)
            ->whereIn('status', [Permit::STATUS_PENDING, Permit::STATUS_REJECTED, Permit::STATUS_SUPERSEDED])
            ->orderByDesc('id')->get();

        return $this->ok($rows->map(fn (Permit $p) => $this->renewalShape($p))->values()->all());
    }

    /** @return array<string, mixed> */
    private function renewalShape(Permit $permit): array
    {
        return [
            'id' => (string) $permit->id,
            'status' => $permit->status,
            'permitExpiresAt' => $permit->expires_at?->toDateString(),
            'tourismLicenseNumber' => $permit->number,
            'tourismLicenseFileId' => $permit->file,
            'submittedAt' => $permit->created_at?->toIso8601ZuluString(),
            'reviewedAt' => $permit->reviewed_at?->toIso8601ZuluString(),
            // The reason is the partner's; the reviewer's notes are internal.
            'rejectionReason' => $permit->rejection_reason,
        ];
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        if (! in_array($unit->approval_status, ['draft', 'rejected'], true)) {
            $this->fail('UNIT_NOT_SUBMITTABLE', 'لا يمكن تقديم هذه الوحدة', 409);
        }

        $this->assertSubmittable($request->user(), $unit);

        $unit->update(['approval_status' => 'pending', 'rejection_reason' => null]);
        $this->notifyAdmins($unit);

        return $this->ok([
            'unit' => UnitPresenter::make($unit->fresh(['images', 'features', 'cancellationPolicy'])),
            'message' => 'سيصلك إشعار خلال 24–48 ساعة',
        ]);
    }

    /* ---- validation ---- */

    private function validateUnit(Request $request, bool $required): array
    {
        return $this->validated($request, UnitWriter::rules($required));
    }

    /**
     * The expansion body. `permits` is per-unit mode only, one entry per NEW
     * apartment; every field inside it is optional except the number, because
     * a permit with no number is not a permit.
     *
     * @return array<string, mixed>
     */
    private static function apartmentRules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:'.UnitCloner::MAX_GROUP],
            'permits' => ['sometimes', 'array', 'max:'.UnitCloner::MAX_GROUP],
            'permits.*.apartmentNo' => ['sometimes', 'nullable', 'string', 'max:20'],
            'permits.*.number' => ['required', 'string', 'max:50'],
            // Required, not optional: nothing is copied in per-unit mode, so an
            // apartment arriving without its own file cannot pass the submit
            // gate — and failing there would roll the whole expansion back
            // with an error about a row the partner never saw.
            'permits.*.fileId' => ['required', 'string', 'max:64'],
            'permits.*.expiresAt' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'permits.*.address' => ['sometimes', 'array'],
            'permits.*.address.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'permits.*.address.district' => ['sometimes', 'nullable', 'string', 'max:150'],
            'permits.*.address.building' => ['sometimes', 'nullable', 'string', 'max:50'],
            'permits.*.address.unitNo' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function toColumns(array $data): array
    {
        return UnitWriter::toColumns($data);
    }

    /** Full submit-time validation (§4). Throws VALIDATION with field errors. */
    private function assertSubmittable(User $user, Unit $unit): void
    {
        if ($fields = UnitWriter::submitErrors($unit)) {
            $this->fail('VALIDATION', 'بيانات غير مكتملة', 400, $fields);
        }

        // §4 — companies must have complete payout docs before submitting.
        if (($user->partnerDetail?->type ?? 'individual') === 'company'
            && ! ProfileController::docs($user)['complete']) {
            $this->fail('COMPANY_DOCS_INCOMPLETE', 'أكمل مستندات الشركة قبل تقديم الوحدة', 409);
        }
    }

    /* ---- files (§9.1 presign flow → unit) ---- */

    /**
     * A bad fileId fails before any mutation — never leaves a half-attached unit.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertFilesOwned(Request $request, array $data): void
    {
        if ($errors = UnitWriter::fileErrors((int) $request->user()->id, $data)) {
            $this->fail('VALIDATION', 'ملفات غير صالحة', 400, $errors);
        }
    }

    /** @param array<string, mixed> $data */
    private function syncPhotos(Request $request, Unit $unit, array $data): void
    {
        UnitWriter::syncPhotos((int) $request->user()->id, $unit, $data);
    }

    /* ---- helpers ---- */

    private function notifyAdmins(Unit $unit): void
    {
        try {
            $admins = User::role(['Admin', 'SuperAdmin'])->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new NewUnitRequest($unit->loadMissing('owner')));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function uniqueCode(): string
    {
        return UnitWriter::uniqueCode();
    }

    /** Accept both "u_1" (contract) and raw "1". */
    private static function rawId(string $id): string
    {
        return Str::startsWith($id, 'u_') ? Str::after($id, 'u_') : $id;
    }
}
