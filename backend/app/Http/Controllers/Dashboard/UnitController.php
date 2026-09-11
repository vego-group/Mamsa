<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewUnitRequest;
use App\Support\Dashboard\UnitPresenter;
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
        $columns = $this->toColumns($data);

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

        $data = $this->validated($request, [
            'count' => ['required', 'integer', 'min:1', 'max:'.UnitCloner::MAX_GROUP],
        ], [
            'count.required' => 'عدد الوحدات مطلوب',
            'count.max' => 'الحد الأقصى '.UnitCloner::MAX_GROUP.' وحدة في المبنى الواحد',
        ]);

        $count = (int) $data['count'];

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

        $before = UnitLicense::groupSize($unit);

        /*
         * Create AND submit, in one transaction.
         *
         * The partner pressed "add apartments" and typed a number — that IS the
         * declaration. Leaving the new rows as drafts to confirm three more
         * times hands back part of the work this feature exists to remove, and
         * doing those submits as separate calls from the browser means a
         * failure halfway leaves apartments on nobody's screen: not the
         * partner's, not the reviewer's queue.
         *
         * So it is atomic. If any apartment cannot be submitted the whole
         * expansion rolls back and the partner is told why, rather than ending
         * up with a building half filed and half invisible.
         *
         * DOCUMENTS TRAVEL WITH THE APARTMENTS here, unlike the /api/v1 route
         * where copying is opt-in. The cloner's comment says no code can decide
         * that, because a permit issued for one apartment does not cover its
         * neighbours — `license_type` now decides it. Expansion is refused
         * unless the licence is `tourist_facility`, and a facility permit is
         * issued to the property, so it covers every apartment in it by
         * definition. Without copying them, submission would fail on the permit
         * file every clone would be missing.
         */
        $group = DB::transaction(function () use ($unit, $count, $request) {
            $group = UnitCloner::ensureTotal($unit, $count, copyDocuments: true);

            foreach ($group as $member) {
                if ($member->approval_status !== 'draft') {
                    continue; // already approved, or already waiting
                }

                // The same gate a manual submit passes — a clone that could not
                // be filed on its own must not be filed in bulk either.
                $this->assertSubmittable($request->user(), $member);

                $member->update(['approval_status' => 'pending', 'rejection_reason' => null]);
            }

            return $group;
        });

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
