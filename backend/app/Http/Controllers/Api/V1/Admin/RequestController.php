<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Notifications\UnitReviewResult;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner "requests" = units submitted for review. A request is any unit that
 * has left draft state (pending / approved / rejected).
 */
class RequestController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Unit::with('owner.roles');

        $status = $request->query('status');
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('approval_status', $status);
        } else {
            // "all" = everything that was actually submitted (exclude drafts)
            $query->where('approval_status', '!=', 'draft');
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('unit_name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', "%{$search}%"));
            });
        }

        $units = $query->latest()->paginate(20);

        $data = $units->getCollection()->map(fn (Unit $u) => [
            'id'              => $u->id,
            'code'            => $u->code,
            'unit_name'       => $u->unit_name,
            'city'            => $u->city,
            'price'           => (float) $u->price,
            'name'            => $u->owner?->name ?? '—',
            'type'            => $u->owner?->hasRole('Company') ? 'Company' : 'Individual',
            'approval_status' => $u->approval_status,
            'created_at'      => $u->created_at?->toIso8601String(),
        ])->all();

        return response()->json([
            'data'  => $data,
            'meta'  => [
                'current_page' => $units->currentPage(),
                'last_page'    => $units->lastPage(),
                'total'        => $units->total(),
            ],
            'stats' => $this->stats(),
        ]);
    }

    public function show(Unit $unit): UnitResource
    {
        return new UnitResource($unit->load(['images', 'features', 'owner.partnerDetail']));
    }

    public function approve(Unit $unit): JsonResponse
    {
        if ($unit->approval_status !== 'pending') {
            return $this->error('الوحدة ليست في انتظار الموافقة', 422);
        }

        $unit->update(['approval_status' => 'approved', 'rejection_reason' => null]);

        $this->notifyOwner($unit, approved: true);

        return $this->success(['unit' => new UnitResource($unit->fresh())], 'تمت الموافقة');
    }

    public function reject(Request $request, Unit $unit): JsonResponse
    {
        if ($unit->approval_status !== 'pending') {
            return $this->error('الوحدة ليست في انتظار الموافقة', 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $unit->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $data['reason'],
        ]);

        $this->notifyOwner($unit, approved: false, reason: $data['reason']);

        return $this->success(['unit' => new UnitResource($unit->fresh())], 'تم الرفض');
    }

    /**
     * Notify the unit's partner of the review result (in-app + email + SMS).
     * Best-effort — a delivery failure must not fail the admin action.
     */
    private function notifyOwner(Unit $unit, bool $approved, ?string $reason = null): void
    {
        try {
            $unit->loadMissing('owner')->owner?->notify(new UnitReviewResult($unit, $approved, $reason));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array<string,int> */
    private function stats(): array
    {
        $byStatus = Unit::query()
            ->where('approval_status', '!=', 'draft')
            ->selectRaw('approval_status, COUNT(*) as c')
            ->groupBy('approval_status')
            ->pluck('c', 'approval_status');

        return [
            'total'    => (int) $byStatus->sum(),
            'pending'  => (int) ($byStatus['pending'] ?? 0),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
        ];
    }
}
