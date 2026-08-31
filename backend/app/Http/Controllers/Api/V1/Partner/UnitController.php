<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Support\Units\UnitCloner;
use App\Support\Units\UnitWriter;
use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewUnitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class UnitController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $units = $request->user()->units()->with(['images', 'features'])->latest()->paginate(15);

        return UnitResource::collection($units);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_name'           => ['required', 'string', 'max:150'],
            'unit_type'           => ['required', 'in:apartment,studio,villa'],
            'price'               => ['required', 'numeric', 'min:1'],
            'capacity'            => ['required', 'integer', 'min:1'],
            'bedrooms'            => ['required', 'integer', 'min:0'],
            'beds'                => ['nullable', 'integer', 'min:1', 'max:20'],
            'bathrooms'           => ['nullable', 'integer', 'min:1', 'max:10'],
            'city'                => ['required', 'string', 'max:100'],
            'district'            => ['nullable', 'string', 'max:150'],
            // Required to submit for review (UnitWriter::submitErrors), and this
            // surface had no way to set it — a partner could never satisfy a
            // gate they could not reach.
            'address'             => ['nullable', 'string', 'max:255'],
            'lat'                 => ['nullable', 'numeric'],
            'lng'                 => ['nullable', 'numeric'],
            // Bounded to match the shared writer. Left unbounded, this surface
            // could store a description the partner dashboard and admin console
            // then refuse to save back, stranding the unit on whichever screen
            // wrote it.
            'description'         => ['nullable', 'string', 'max:'.\App\Support\Units\UnitWriter::MAX_DESCRIPTION],
            'tourism_permit_no'   => ['nullable', 'string', 'max:50'],
            'company_license_no'  => ['nullable', 'string', 'max:50'],
            'cancellation_policy' => ['nullable', 'in:no_cancel,48_hours'],
            'checkin_time'        => ['nullable', 'date_format:H:i'],
            'checkout_time'       => ['nullable', 'date_format:H:i'],
            'features'            => ['nullable', 'array'],
            'features.*'          => ['string', 'max:100'],
        ]);

        $unit = $request->user()->units()->create(array_merge(
            \Arr::except($data, ['features']),
            [
                'approval_status' => 'draft',
                'code'            => strtoupper(Str::random(8)),
                'calendar_token'  => Str::random(60),
            ]
        ));

        if (! empty($data['features'])) {
            $featureIds = collect($data['features'])->map(function ($name) {
                return \App\Models\Feature::firstOrCreate(['name' => $name])->id;
            });
            $unit->features()->sync($featureIds);
        }

        return response()->json(new UnitResource($unit->load(['images', 'features'])), 201);
    }

    public function show(Request $request, Unit $unit): UnitResource|JsonResponse
    {
        if ($unit->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        return new UnitResource($unit->load(['images', 'features']));
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        if ($unit->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $data = $request->validate([
            'unit_name'           => ['sometimes', 'string', 'max:150'],
            'unit_type'           => ['sometimes', 'in:apartment,studio,villa'],
            'price'               => ['sometimes', 'numeric', 'min:1'],
            'capacity'            => ['sometimes', 'integer', 'min:1'],
            'bedrooms'            => ['sometimes', 'integer', 'min:0'],
            'beds'                => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'bathrooms'           => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'city'                => ['sometimes', 'string', 'max:100'],
            'district'            => ['nullable', 'string', 'max:150'],
            // Required to submit for review (UnitWriter::submitErrors), and this
            // surface had no way to set it — a partner could never satisfy a
            // gate they could not reach.
            'address'             => ['nullable', 'string', 'max:255'],
            'lat'                 => ['nullable', 'numeric'],
            'lng'                 => ['nullable', 'numeric'],
            // Bounded to match the shared writer. Left unbounded, this surface
            // could store a description the partner dashboard and admin console
            // then refuse to save back, stranding the unit on whichever screen
            // wrote it.
            'description'         => ['nullable', 'string', 'max:'.\App\Support\Units\UnitWriter::MAX_DESCRIPTION],
            'tourism_permit_no'   => ['nullable', 'string', 'max:50'],
            'company_license_no'  => ['nullable', 'string', 'max:50'],
            'cancellation_policy' => ['nullable', 'in:no_cancel,48_hours'],
            'checkin_time'        => ['nullable', 'date_format:H:i'],
            'checkout_time'       => ['nullable', 'date_format:H:i'],
            'status'              => ['nullable', 'in:available,unavailable'],
            'features'            => ['nullable', 'array'],
            'features.*'          => ['string', 'max:100'],
        ]);

        // FR-066: editing an approved unit resets it to pending
        $resetToPending = $unit->approval_status === 'approved';
        if ($resetToPending) {
            $data['approval_status'] = 'pending';
        }

        $unit->update(\Arr::except($data, ['features']));

        if (array_key_exists('features', $data)) {
            $featureIds = collect($data['features'])->map(function ($name) {
                return \App\Models\Feature::firstOrCreate(['name' => $name])->id;
            });
            $unit->features()->sync($featureIds);
        }

        if ($resetToPending) {
            $this->notifyAdminsOfRequest($unit);
        }

        return response()->json(new UnitResource($unit->fresh()->load(['images', 'features'])));
    }

    public function destroy(Request $request, Unit $unit): JsonResponse
    {
        if ($unit->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($unit->bookings()->whereIn('status', ['pending_payment', 'confirmed'])->exists()) {
            return response()->json(['message' => 'لا يمكن حذف وحدة بها حجوزات نشطة'], 422);
        }

        $unit->delete();

        return response()->json(['message' => 'تم الحذف']);
    }

    /** UnitWriter field names → the names this surface accepts. */
    private const SUBMIT_FIELD_MAP = [
        'name'                 => 'unit_name',
        'type'                 => 'unit_type',
        'pricePerNight'        => 'price',
        'tourismLicenseNumber' => 'tourism_permit_no',
        'tourismLicenseFileId' => 'tourism_permit_file',
        'photos'               => 'images',
        // `location` covers lat AND lng together — the check is that the pair
        // lands inside Saudi, not that either number is present, so splitting it
        // across two fields would report a failure neither one caused.
        'location'             => 'location',
    ];

    public function submit(Request $request, Unit $unit): JsonResponse
    {
        if ($unit->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if (! in_array($unit->approval_status, ['draft', 'rejected'])) {
            return response()->json(['message' => 'لا يمكن تقديم هذه الوحدة'], 422);
        }

        // The dashboard has always run these checks; this surface flipped the
        // status with none of them, so a listing could reach an admin's review
        // queue with no location, no licence number and no licence file. One
        // rule, both surfaces — UnitWriter::submitErrors is the single owner.
        if ($errors = UnitWriter::submitErrors($unit)) {
            return response()->json([
                'message' => 'الوحدة غير مكتملة، أكمل البيانات المطلوبة قبل الإرسال',
                'code'    => 'UNIT_INCOMPLETE',
                // Mapped to the names THIS surface uses, so a client can put the
                // message on the field the partner actually edits. The writer
                // speaks the dashboard's camelCase; v1 is snake_case, and
                // returning its keys verbatim would point at inputs that do not
                // exist here.
                'errors'  => collect($errors)
                    ->mapWithKeys(fn (string $msg, string $field) => [
                        self::SUBMIT_FIELD_MAP[$field] ?? $field => [$msg],
                    ])
                    ->all(),
            ], 422);
        }

        $unit->update(['approval_status' => 'pending']);

        $this->notifyAdminsOfRequest($unit);

        return response()->json(new UnitResource($unit->fresh()));
    }

    /**
     * POST /partner/units/{unit}/apartments — turn one listing into a building.
     *
     * A partner with 100 identical apartments builds ONE of them properly, then
     * says which door numbers exist. Every number becomes a real, separately
     * bookable unit sharing this one's spec and photos, tied together by a
     * `unit_group_id`.
     *
     * Numbers already in the group are skipped rather than rejected, so a call
     * that times out partway can simply be repeated.
     */
    public function apartments(Request $request, Unit $unit): JsonResponse
    {
        if ($unit->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $data = $request->validate([
            // Either an explicit list…
            // Three ways to say the same thing, because partners think about
            // this differently: "these exact doors", "401 through 420", or
            // simply "I have five of them".
            'numbers'        => ['required_without_all:from,count', 'array', 'max:'.UnitCloner::MAX_GROUP],
            'numbers.*'      => ['string', 'max:20'],
            'from'           => ['required_without_all:numbers,count', 'integer', 'min:0', 'max:99999'],
            'to'             => ['required_with:from', 'integer', 'min:0', 'max:99999', 'gte:from'],
            'prefix'         => ['nullable', 'string', 'max:5'],
            // The plain count. Numbers become 1..N, so the group still has a
            // stable identity per apartment and re-sending the same count adds
            // nothing — raising it to 8 later simply adds the missing three.
            'count'          => ['required_without_all:numbers,from', 'integer', 'min:1', 'max:'.UnitCloner::MAX_GROUP],
            // Off by default: a permit issued per apartment does not cover its
            // neighbours, and copying it would put an admin in front of 99
            // listings evidenced by a licence that is not theirs.
            'copy_documents' => ['nullable', 'boolean'],
        ]);

        $copyDocuments = (bool) ($data['copy_documents'] ?? false);

        // `count` is a TOTAL — "I have five units" — while numbers and ranges
        // name specific doors. Treating the total as a list of doors 1..N is
        // what turned a building of 401-405 into ten apartments.
        if (isset($data['count'])) {
            if ($data['count'] > UnitCloner::MAX_GROUP) {
                return $this->groupTooLarge((int) $data['count']);
            }

            $group = UnitCloner::ensureTotal($unit, (int) $data['count'], $copyDocuments);

            return $this->groupResponse($group);
        }

        $numbers = isset($data['numbers'])
            ? $data['numbers']
            : collect(range($data['from'], $data['to']))
                ->map(fn ($n) => ($data['prefix'] ?? '').$n)->all();

        if (($size = UnitCloner::projectedSize($unit, $numbers)) > UnitCloner::MAX_GROUP) {
            return $this->groupTooLarge($size);
        }

        $group = UnitCloner::assign($unit, $numbers, $copyDocuments);

        return $this->groupResponse($group);
    }

    private function groupTooLarge(int $size): JsonResponse
    {
        return response()->json([
            'message' => 'الحد الأقصى '.UnitCloner::MAX_GROUP.' وحدة في المبنى الواحد، والمطلوب '.$size,
            'code'    => 'GROUP_TOO_LARGE',
        ], 422);
    }

    /** @param  \Illuminate\Support\Collection<int, Unit>  $group */
    private function groupResponse($group): JsonResponse
    {
        return response()->json([
            'message' => 'المبنى يحتوي الآن على '.$group->count().' وحدة',
            'data'    => UnitResource::collection($group->load(['images', 'features'])),
        ], 201);
    }

    /**
     * Notify all Admins/SuperAdmins that a unit is awaiting review
     * (in-app + email). FR-101.
     */
    private function notifyAdminsOfRequest(Unit $unit): void
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
}
