<?php

namespace App\Http\Controllers\Api\V1\Partner;

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
            'city'                => ['required', 'string', 'max:100'],
            'district'            => ['nullable', 'string', 'max:150'],
            'lat'                 => ['nullable', 'numeric'],
            'lng'                 => ['nullable', 'numeric'],
            'description'         => ['nullable', 'string'],
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
            'city'                => ['sometimes', 'string', 'max:100'],
            'district'            => ['nullable', 'string', 'max:150'],
            'lat'                 => ['nullable', 'numeric'],
            'lng'                 => ['nullable', 'numeric'],
            'description'         => ['nullable', 'string'],
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

        if ($unit->bookings()->whereIn('status', ['pending', 'confirmed'])->exists()) {
            return response()->json(['message' => 'لا يمكن حذف وحدة بها حجوزات نشطة'], 422);
        }

        $unit->delete();

        return response()->json(['message' => 'تم الحذف']);
    }

    public function submit(Request $request, Unit $unit): JsonResponse
    {
        if ($unit->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if (! in_array($unit->approval_status, ['draft', 'rejected'])) {
            return response()->json(['message' => 'لا يمكن تقديم هذه الوحدة'], 422);
        }

        $unit->update(['approval_status' => 'pending']);

        $this->notifyAdminsOfRequest($unit);

        return response()->json(new UnitResource($unit->fresh()));
    }

    /**
     * Notify all Admins/SuperAdmins that a unit is awaiting review
     * (in-app + email). FR-101.
     */
    private function notifyAdminsOfRequest(Unit $unit): void
    {
        $admins = User::role(['Admin', 'SuperAdmin'])->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewUnitRequest($unit->loadMissing('owner')));
        }
    }
}
