<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\DashboardUpload;
use App\Models\Feature;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewUnitRequest;
use App\Support\Dashboard\Maps;
use App\Support\Dashboard\UnitPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        $this->syncAmenities($unit, $data['amenities'] ?? null);
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
        $this->syncAmenities($unit, $data['amenities'] ?? null);
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
        $req = $required ? 'required' : 'sometimes';

        return $this->validated($request, [
            'name'                 => [$req, 'string', 'min:2', 'max:150'],
            'type'                 => [$req, 'in:apartment,studio,villa'],
            'pricePerNight'        => [$req, 'numeric', 'gt:0'],
            // cleaningFee (abolished 2026-07-18) is deliberately absent: an
            // old client still sending it is silently ignored, not 422'd.
            'cancellationPolicy'   => ['sometimes', 'in:flexible,moderate,strict'],
            'capacity'             => [$req, 'integer', 'min:1'],
            'bedrooms'             => ['sometimes', 'integer', 'min:0'],
            // Number of beds (عدد الأسرّة) — separate from bedrooms.
            'beds'                 => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'bathrooms'            => ['sometimes', 'nullable', 'integer', 'min:0'],
            'city'                 => [$req, 'string', 'in:'.implode(',', array_keys(Maps::CITIES))],
            'district'             => ['sometimes', 'nullable', 'string', 'max:150'],
            'description'          => ['sometimes', 'nullable', 'string', 'max:500'],
            'amenities'            => ['sometimes', 'array'],
            'amenities.*'          => ['string', 'in:'.implode(',', array_keys(Maps::AMENITIES))],
            'checkIn'              => ['sometimes', 'nullable', 'date_format:H:i'],
            'checkOut'             => ['sometimes', 'nullable', 'date_format:H:i'],
            'lat'                  => ['sometimes', 'nullable', 'numeric'],
            'lng'                  => ['sometimes', 'nullable', 'numeric'],
            'address'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'tourismLicenseNumber' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tourismLicenseFileId' => ['sometimes', 'nullable', 'string'],
            // Photos are attached by referencing fileIds from POST /uploads/presign,
            // in display order; coverFileId marks the main image.
            'photoFileIds'         => ['sometimes', 'array', 'max:10'],
            'photoFileIds.*'       => ['string'],
            'coverFileId'          => ['sometimes', 'nullable', 'string'],
        ]);
    }

    /** Map contract keys → DB columns; sanitize free text (§10.5). */
    private function toColumns(array $data): array
    {
        $map = [
            'name'                 => fn ($v) => ['unit_name' => strip_tags($v)],
            'type'                 => fn ($v) => ['unit_type' => $v],
            'pricePerNight'        => fn ($v) => ['price' => $v],
            // Preset slug → FK. Only affects FUTURE bookings: paid bookings
            // carry a frozen snapshot the engine reads exclusively (FR-036).
            'cancellationPolicy'   => fn ($v) => [
                'cancellation_policy_id' => \App\Models\CancellationPolicy::where('key', $v)->value('id'),
            ],
            'capacity'             => fn ($v) => ['capacity' => $v],
            'bedrooms'             => fn ($v) => ['bedrooms' => $v],
            'beds'                 => fn ($v) => ['beds' => $v],
            'bathrooms'            => fn ($v) => ['bathrooms' => $v],
            'city'                 => fn ($v) => ['city' => Maps::cityToArabic($v) ?? $v],
            'district'             => fn ($v) => ['district' => $v === null ? null : strip_tags($v)],
            'description'          => fn ($v) => ['description' => $v === null ? null : strip_tags($v)],
            'checkIn'              => fn ($v) => ['checkin_time' => $v],
            'checkOut'             => fn ($v) => ['checkout_time' => $v],
            'lat'                  => fn ($v) => ['lat' => $v],
            'lng'                  => fn ($v) => ['lng' => $v],
            'address'              => fn ($v) => ['address' => $v === null ? null : strip_tags($v)],
            'tourismLicenseNumber' => fn ($v) => ['tourism_permit_no' => $v],
            'tourismLicenseFileId' => fn ($v) => ['tourism_permit_file' => $v],
        ];

        $columns = [];
        foreach ($map as $key => $fn) {
            if (array_key_exists($key, $data)) {
                $columns = array_merge($columns, $fn($data[$key]));
            }
        }

        return $columns;
    }

    /** Full submit-time validation (§4). Throws VALIDATION with field errors. */
    private function assertSubmittable(User $user, Unit $unit): void
    {
        $fields = [];

        if (Str::length((string) $unit->unit_name) < 2)                 $fields['name'] = 'الاسم مطلوب';
        if (! in_array($unit->unit_type, Unit::SUPPORTED_TYPES, true))  $fields['type'] = 'نوع الوحدة غير صالح';
        if ((float) $unit->price <= 0)                                  $fields['pricePerNight'] = 'السعر يجب أن يكون أكبر من صفر';
        if ((int) $unit->capacity < 1)                                  $fields['capacity'] = 'السعة مطلوبة';
        if (! Maps::cityToSlug($unit->city) || ! in_array(Maps::cityToSlug($unit->city), array_keys(Maps::CITIES), true)) {
            $fields['city'] = 'المدينة يجب أن تكون ضمن المدن المعتمدة';
        }
        $descLen = Str::length((string) $unit->description);
        if ($descLen < 10 || $descLen > 500)                           $fields['description'] = 'الوصف يجب أن يكون بين 10 و 500 حرف';
        if (blank($unit->address))                                     $fields['address'] = 'العنوان مطلوب';
        if ($unit->lat === null || $unit->lng === null || ! Maps::insideSaudi((float) $unit->lat, (float) $unit->lng)) {
            $fields['location'] = 'الموقع يجب أن يكون داخل حدود المملكة';
        }
        if (blank($unit->tourism_permit_no))                           $fields['tourismLicenseNumber'] = 'رقم رخصة السياحة مطلوب';
        if (blank($unit->tourism_permit_file))                         $fields['tourismLicenseFileId'] = 'ملف الرخصة مطلوب';
        if ($unit->images()->count() < 1)                              $fields['photos'] = 'أضف صورة واحدة على الأقل';

        if ($fields) {
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
     * Every referenced upload must be a stored upload owned by THIS partner
     * (§0.2), of the kind matching where it's used. Validated up-front so a bad
     * fileId fails before any mutation — never leaves a half-attached unit.
     */
    private function assertFilesOwned(Request $request, array $data): void
    {
        $errors = [];

        if (! empty($data['tourismLicenseFileId'])
            && ! $this->ownedUpload($request, $data['tourismLicenseFileId'], 'license_pdf')) {
            $errors['tourismLicenseFileId'] = 'ملف الرخصة غير موجود';
        }

        foreach ($data['photoFileIds'] ?? [] as $i => $fileId) {
            if (! $this->ownedUpload($request, $fileId, 'unit_photo')) {
                $errors["photoFileIds.$i"] = 'الصورة غير موجودة';
            }
        }

        if (! empty($data['coverFileId'])
            && ! in_array($data['coverFileId'], $data['photoFileIds'] ?? [], true)) {
            $errors['coverFileId'] = 'صورة الغلاف يجب أن تكون ضمن الصور المرفوعة';
        }

        if ($errors) {
            $this->fail('VALIDATION', 'ملفات غير صالحة', 400, $errors);
        }
    }

    private function ownedUpload(Request $request, string $fileId, string $kind): ?DashboardUpload
    {
        return DashboardUpload::whereKey($fileId)
            ->where('user_id', $request->user()->id)
            ->where('kind', $kind)
            ->where('status', 'stored')
            ->first();
    }

    /**
     * Replace the unit's gallery from the ordered photoFileIds (§1 answer:
     * photoFileIds[] + coverFileId). Absent key → gallery untouched; present
     * (even empty) → authoritative replace. coverFileId marks the main image,
     * else the first photo. Files are already stored (presign+PUT); we just
     * link them as UnitImage rows in order.
     */
    private function syncPhotos(Request $request, Unit $unit, array $data): void
    {
        if (! array_key_exists('photoFileIds', $data)) {
            return;
        }

        $cover = $data['coverFileId'] ?? ($data['photoFileIds'][0] ?? null);

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $unit, $data, $cover) {
            $unit->images()->delete();

            foreach ($data['photoFileIds'] as $fileId) {
                $upload = $this->ownedUpload($request, $fileId, 'unit_photo');
                if (! $upload) {
                    continue; // already validated in assertFilesOwned; defensive
                }

                $unit->images()->create([
                    'file_id' => $upload->id,
                    'path'    => $upload->path,
                    'is_main' => $fileId === $cover,
                ]);
            }
        });
    }

    /* ---- helpers ---- */

    private function syncAmenities(Unit $unit, ?array $keys): void
    {
        if ($keys === null) {
            return;
        }

        $ids = collect($keys)
            ->map(fn ($k) => Maps::amenityToArabic($k))
            ->filter()
            ->map(fn ($name) => Feature::firstOrCreate(['name' => $name])->id);

        $unit->features()->sync($ids);
    }

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
        do {
            $code = 'MRN'.strtoupper(Str::random(5));
        } while (Unit::where('code', $code)->exists());

        return $code;
    }

    /** Accept both "u_1" (contract) and raw "1". */
    private static function rawId(string $id): string
    {
        return Str::startsWith($id, 'u_') ? Str::after($id, 'u_') : $id;
    }
}
