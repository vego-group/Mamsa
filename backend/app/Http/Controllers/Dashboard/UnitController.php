<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewUnitRequest;
use App\Support\Dashboard\UnitPresenter;
use App\Support\Units\UnitWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'code'            => self::uniqueCode(),
                'calendar_token'  => Str::random(60),
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
            'unit'    => UnitPresenter::make($unit->fresh(['images', 'features', 'cancellationPolicy'])),
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
