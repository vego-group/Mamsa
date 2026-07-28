<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Unit;
use App\Notifications\UnitReviewResult;
use App\Support\AdminPanel\UnitPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approvals (unit review queue) — BACKEND_SPEC §5.7. A request is a unit in
 * `pending` (pending_review). Default order: oldest submittedAt first (SLA).
 * submittedAt / approvedAt use updated_at (units carry no reviewed_at column).
 */
class ApprovalsController extends Controller
{
    private const SORT = ['submittedAt' => 'updated_at'];

    public function __construct(private readonly UnitPresenter $units) {}

    public function index(Request $request): JsonResponse
    {
        $args  = $this->listArgs($request);
        $query = Unit::query()->with('owner.partnerDetail')->where('approval_status', 'pending');

        if ($rt = $this->cleanParam($request->query('requestType'))) {
            match ($rt) {
                'new'                   => $query->whereNull('rejection_reason'),
                'resubmission'          => $query->whereNotNull('rejection_reason'),
                'reapproval_after_edit' => $query->whereRaw('1 = 0'), // not tracked yet
                default                 => null,
            };
        }
        if ($pt = $this->cleanParam($request->query('partnerType'))) {
            $query->whereHas('owner.partnerDetail', fn ($d) => $d->where('type', $pt));
        }
        if ($args['search'] !== null) {
            $s = $args['search'];
            $query->where(function ($q) use ($s) {
                $q->where('unit_name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")->orWhere('city', 'like', "%{$s}%")
                  ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', "%{$s}%"));
            });
        }

        $page = $this->queryList($query, $args, [], self::SORT, ['updated_at', 'asc']);

        return $this->items($page, fn (Unit $u) => $this->units->approvalRow($u));
    }

    public function stats(): JsonResponse
    {
        $avg = (float) Unit::whereIn('approval_status', ['approved', 'rejected'])
            ->selectRaw($this->avgHoursSql('created_at', 'updated_at').' as h')->value('h');

        return response()->json([
            'pendingReview' => Unit::where('approval_status', 'pending')->count(),
            'approvedToday' => Unit::where('approval_status', 'approved')->whereDate('updated_at', today())->count(),
            'rejectedToday' => Unit::where('approval_status', 'rejected')->whereDate('updated_at', today())->count(),
            'avgReviewHours'=> round($avg, 1),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $u = $this->units->baseQuery()->with(['features', 'owner.partnerDetail'])->whereKey($id)->first();

        if (! $u) {
            $this->fail('NOT_FOUND', 'الطلب غير موجود', 404);
        }

        $owner  = $u->owner;
        $rating = $owner ? $owner->unitReviews()->avg('rating') : null;

        return response()->json(array_merge($this->units->approvalRow($u), [
            'unit'            => $this->units->detail($u),
            'partnerVerified' => $owner?->partnerDetail?->verified_at !== null,
            'partnerRating'   => $rating !== null ? round((float) $rating, 1) : 0.0,
        ]));
    }

    public function approve(string $id): JsonResponse
    {
        $unit = $this->pendingUnit($id);
        $unit->update(['approval_status' => 'approved', 'rejection_reason' => null]);
        $this->notifyOwner($unit, true);

        return $this->ok();
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
            'notes'  => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], ['reason.required' => 'يجب إدخال سبب الرفض']);

        $unit = $this->pendingUnit($id);
        $unit->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason']]);
        $this->notifyOwner($unit, false, $data['reason']);

        return $this->ok();
    }

    /* ---------- helpers ---------- */

    private function pendingUnit(string $id): Unit
    {
        $unit = Unit::find($id);

        if (! $unit) {
            $this->fail('NOT_FOUND', 'الطلب غير موجود', 404);
        }
        if ($unit->approval_status !== 'pending') {
            $this->fail('CONFLICT', 'الوحدة ليست في انتظار المراجعة', 409);
        }

        return $unit;
    }

    /** Best-effort review-result notification; a delivery failure never fails the action. */
    private function notifyOwner(Unit $unit, bool $approved, ?string $reason = null): void
    {
        try {
            $unit->loadMissing('owner')->owner?->notify(new UnitReviewResult($unit, $approved, $reason));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
