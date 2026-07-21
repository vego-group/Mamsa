<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitController extends Controller
{
    use \App\Traits\ApiResponse;
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Unit::with(['images', 'owner']);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('unit_name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->approval_status);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        return UnitResource::collection($query->latest()->paginate(20));
    }

    /**
     * Editorial "featured" toggle for the storefront home section (§2.1).
     * Admin-only — being featured is a platform decision, not the partner's.
     */
    public function setFeatured(Request $request, Unit $unit): JsonResponse
    {
        $data = $request->validate(['is_featured' => ['required', 'boolean']]);

        $unit->update(['is_featured' => $data['is_featured']]);

        return $this->success(
            new UnitResource($unit->load(['images', 'features', 'owner.partnerDetail'])),
            $data['is_featured'] ? 'تم تمييز الوحدة' : 'تم إلغاء تمييز الوحدة',
        );
    }
}
