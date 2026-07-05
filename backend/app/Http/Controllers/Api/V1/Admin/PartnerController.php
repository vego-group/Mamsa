<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerDetail;
use App\Models\User;
use App\Notifications\PartnerApplicationResult;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner applications review (approve / reject) — mirrors the unit-request
 * workflow in RequestController. The applicant is notified in-app + by email;
 * the approval email carries the partner dashboard link.
 */
class PartnerController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = PartnerDetail::with('user');

        $status = $request->query('status');
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        $details = $query->latest()->paginate(20);

        $data = $details->getCollection()->map(fn (PartnerDetail $d) => [
            'user_id'          => $d->user_id,
            'name'             => $d->user?->name ?? '—',
            'phone'            => $d->user?->phone,
            'email'            => $d->user?->email,
            'type'             => $d->type,
            'national_id'      => $d->national_id,
            'cr_number'        => $d->cr_number,
            'status'           => $d->status,
            'rejection_reason' => $d->rejection_reason,
            'applied_at'       => $d->created_at?->toIso8601String(),
            'reviewed_at'      => $d->reviewed_at?->toIso8601String(),
        ])->all();

        return response()->json([
            'data'  => $data,
            'meta'  => [
                'current_page' => $details->currentPage(),
                'last_page'    => $details->lastPage(),
                'total'        => $details->total(),
            ],
            'stats' => $this->stats(),
        ]);
    }

    public function approve(User $user): JsonResponse
    {
        $detail = $this->pendingDetailOrFail($user);
        if ($detail instanceof JsonResponse) {
            return $detail;
        }

        $detail->update([
            'status'           => PartnerDetail::STATUS_APPROVED,
            'rejection_reason' => null,
            'reviewed_at'      => now(),
        ]);

        $this->notifyApplicant($user, approved: true);

        return $this->success(['status' => PartnerDetail::STATUS_APPROVED], 'تمت الموافقة على الشريك');
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        $detail = $this->pendingDetailOrFail($user);
        if ($detail instanceof JsonResponse) {
            return $detail;
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $detail->update([
            'status'           => PartnerDetail::STATUS_REJECTED,
            'rejection_reason' => $data['reason'],
            'reviewed_at'      => now(),
        ]);

        $this->notifyApplicant($user, approved: false, reason: $data['reason']);

        return $this->success(['status' => PartnerDetail::STATUS_REJECTED], 'تم رفض الطلب');
    }

    /** Resolve the user's pending application, or the matching 4xx response. */
    private function pendingDetailOrFail(User $user): PartnerDetail|JsonResponse
    {
        $detail = $user->partnerDetail;

        if (! $detail) {
            return $this->error('لا يوجد طلب شراكة لهذا المستخدم', 404);
        }

        if ($detail->status !== PartnerDetail::STATUS_PENDING) {
            return $this->error('الطلب ليس في انتظار المراجعة', 422);
        }

        return $detail;
    }

    /** Best-effort — a delivery failure must not fail the admin action. */
    private function notifyApplicant(User $user, bool $approved, ?string $reason = null): void
    {
        try {
            $user->notify(new PartnerApplicationResult($approved, $reason));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array<string,int> */
    private function stats(): array
    {
        $byStatus = PartnerDetail::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total'    => (int) $byStatus->sum(),
            'pending'  => (int) ($byStatus['pending'] ?? 0),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
        ];
    }
}
