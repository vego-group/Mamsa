<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $units = Unit::with(['images', 'owner'])
            ->where('approval_status', 'pending')
            ->latest()
            ->paginate(20);

        return UnitResource::collection($units);
    }

    public function show(Unit $unit): UnitResource
    {
        return new UnitResource($unit->load(['images', 'features', 'owner.partnerDetail']));
    }

    public function approve(Unit $unit): JsonResponse
    {
        if ($unit->approval_status !== 'pending') {
            return response()->json(['message' => 'الوحدة ليست في انتظار الموافقة'], 422);
        }

        $unit->update(['approval_status' => 'approved', 'rejection_reason' => null]);

        return response()->json(['message' => 'تمت الموافقة', 'unit' => new UnitResource($unit->fresh())]);
    }

    public function reject(Request $request, Unit $unit): JsonResponse
    {
        if ($unit->approval_status !== 'pending') {
            return response()->json(['message' => 'الوحدة ليست في انتظار الموافقة'], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $unit->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $data['reason'],
        ]);

        return response()->json(['message' => 'تم الرفض', 'unit' => new UnitResource($unit->fresh())]);
    }
}
