<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\PartnerApplicationResult;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner management + applications review. The list shows every partner
 * (any application state) with live aggregates from their units/bookings;
 * pending applications are actioned via approve/reject, verified partners via
 * revoke/disable — mirroring the Figma "Partners Management" screen.
 */
class PartnerController extends Controller
{
    use ApiResponse;

    private const HIGH_RISK_CANCEL_RATE = 15.0; // % cancelled bookings

    public function index(Request $request): JsonResponse
    {
        $query = $this->aggregateQuery();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if (in_array($type = $request->query('type'), ['individual', 'company'], true)) {
            $query->whereHas('partnerDetail', fn ($q) => $q->where('type', $type));
        }

        $partners = $query->latest()->paginate(20);
        $partners->getCollection()->transform(fn (User $u) => $this->mapRow($u));

        return response()->json([
            'data'  => $partners->items(),
            'meta'  => [
                'current_page' => $partners->currentPage(),
                'last_page'    => $partners->lastPage(),
                'total'        => $partners->total(),
            ],
            'counts' => $this->typeCounts(),
            'stats'  => $this->stats(),
        ]);
    }

    /** Full partner profile for the detail drawer. */
    public function show(User $user): JsonResponse
    {
        abort_unless((bool) $user->partnerDetail, 404);

        $u = $this->aggregateQuery()->whereKey($user->id)->firstOrFail();
        $d = $u->partnerDetail;

        $revenue    = round((float) ($u->revenue ?? 0), 2);
        $commission = round((float) ($u->subtotal_sum ?? 0) * Booking::COMMISSION_RATE, 2);
        $bookings   = (int) $u->bookings_count;

        return $this->success([
            'user_id'    => $u->id,
            'code'       => sprintf('PTR-%03d', $u->id),
            'name'       => $u->name,
            'email'      => $u->email,
            'phone'      => $u->phone,
            'city'       => $u->city,
            'type'       => $d->type,
            'rating'     => $u->rating !== null ? round((float) $u->rating, 1) : null,
            'verified'   => $d->status === PartnerDetail::STATUS_APPROVED,
            'application_status' => $d->status,
            'status'     => $this->statusFor($u, $d),
            'is_active'  => (bool) $u->is_active,
            'created_at' => $u->created_at?->toIso8601String(),
            'financial'  => [
                'total_revenue'   => $revenue,
                'commission_paid' => $commission,
                'partner_earning' => round($revenue - $commission, 2),
                'avg_booking'     => $bookings > 0 ? round($revenue / $bookings, 2) : 0.0,
            ],
            'performance' => [
                'total_units'       => (int) $u->units_count,
                'total_bookings'    => $bookings,
                'cancellations'     => (int) $u->cancellations_count,
                'cancellation_rate' => $bookings > 0 ? round(($u->cancellations_count / $bookings) * 100, 1) : 0.0,
            ],
            'documents' => $this->documents($d),
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

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $detail->update([
            'status'           => PartnerDetail::STATUS_REJECTED,
            'rejection_reason' => $data['reason'],
            'reviewed_at'      => now(),
        ]);
        $this->notifyApplicant($user, approved: false, reason: $data['reason']);

        return $this->success(['status' => PartnerDetail::STATUS_REJECTED], 'تم رفض الطلب');
    }

    /** Enable / disable the partner account (blocks their dashboard access). */
    public function setActive(Request $request, User $user): JsonResponse
    {
        abort_unless((bool) $user->partnerDetail, 404);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $user->update(['is_active' => $data['is_active']]);

        return $this->success(['is_active' => (bool) $user->is_active], 'تم تحديث حالة الشريك');
    }

    /** Revoke verification — sends the partner back to pending review. */
    public function revoke(User $user): JsonResponse
    {
        $detail = $user->partnerDetail;
        abort_unless((bool) $detail, 404);

        $detail->update([
            'status'      => PartnerDetail::STATUS_PENDING,
            'reviewed_at' => null,
        ]);

        return $this->success(['status' => PartnerDetail::STATUS_PENDING], 'تم إلغاء التوثيق');
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** Base partner query with all list/detail aggregates attached. */
    private function aggregateQuery(): Builder
    {
        return User::query()
            ->role(['Individual', 'Company'])
            ->whereHas('partnerDetail')
            ->with('partnerDetail')
            ->withCount('units')
            ->withCount(['unitBookings as bookings_count'])
            ->withCount(['unitBookings as cancellations_count' => fn ($q) => $q->where('bookings.status', 'cancelled')])
            ->withSum(['unitBookings as revenue' => fn ($q) => $q->whereIn('bookings.status', Booking::REVENUE_STATUSES)], 'total_amount')
            ->withSum(['unitBookings as subtotal_sum' => fn ($q) => $q->whereIn('bookings.status', Booking::REVENUE_STATUSES)], 'subtotal')
            ->withAvg(['unitReviews as rating'], 'rating')
            ->addSelect(['city' => Unit::query()->select('city')
                ->whereColumn('units.user_id', 'users.id')
                ->latest()->limit(1)]);
    }

    /** @return array<string, mixed> */
    private function mapRow(User $u): array
    {
        $d = $u->partnerDetail;
        $bookings = (int) $u->bookings_count;
        $rate = $bookings > 0 ? round(($u->cancellations_count / $bookings) * 100, 1) : 0.0;

        return [
            'user_id'           => $u->id,
            'code'              => sprintf('PTR-%03d', $u->id),
            'name'              => $u->name,
            'type'              => $d?->type,
            'city'              => $u->city,
            'units_count'       => (int) $u->units_count,
            'bookings_count'    => $bookings,
            'revenue'           => round((float) ($u->revenue ?? 0), 2),
            'rating'            => $u->rating !== null ? round((float) $u->rating, 1) : null,
            'verified'          => $d?->status === PartnerDetail::STATUS_APPROVED,
            'status'            => $this->statusFor($u, $d),
            'cancellation_rate' => $rate,
            'high_risk'         => $this->isHighRisk($u, $d, $rate),
            'is_active'         => (bool) $u->is_active,
        ];
    }

    /** active | pending | inactive — the badge in the STATUS column. */
    private function statusFor(User $u, ?PartnerDetail $d): string
    {
        if ($d?->status === PartnerDetail::STATUS_PENDING) {
            return 'pending';
        }
        if (! $u->is_active || $d?->status === PartnerDetail::STATUS_REJECTED) {
            return 'inactive';
        }

        return 'active';
    }

    private function isHighRisk(User $u, ?PartnerDetail $d, float $cancelRate): bool
    {
        return $cancelRate >= self::HIGH_RISK_CANCEL_RATE
            || $d?->status !== PartnerDetail::STATUS_APPROVED
            || ($u->rating !== null && (float) $u->rating < 4.0);
    }

    /** @return array<int, array{key: string, status: string}> */
    private function documents(PartnerDetail $d): array
    {
        $verified = $d->status === PartnerDetail::STATUS_APPROVED;
        $state = fn (bool $provided) => ! $provided ? 'missing' : ($verified ? 'verified' : 'pending');

        return [
            ['key' => 'identity',  'status' => $state((bool) ($d->national_id || $d->cr_number))],
            ['key' => 'bank',      'status' => $state((bool) $d->iban)],
            ['key' => 'ownership', 'status' => $state((bool) ($d->authorization_letter_file || $d->vat_certificate_file || $d->operator_license_file))],
        ];
    }

    /** @return array<string, int|float> */
    private function stats(): array
    {
        $partners = User::role(['Individual', 'Company'])->whereHas('partnerDetail');

        $active = (clone $partners)
            ->where('is_active', true)
            ->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_APPROVED))
            ->count();

        // Total revenue across all partner units = platform revenue (paid stays).
        $revenue = (float) Booking::query()->revenue()->sum('total_amount');

        // High-risk needs per-partner rates; cheap at partner scale.
        $highRisk = $this->aggregateQuery()->get()
            ->filter(function (User $u) {
                $rate = $u->bookings_count > 0 ? ($u->cancellations_count / $u->bookings_count) * 100 : 0.0;
                return $this->isHighRisk($u, $u->partnerDetail, $rate);
            })->count();

        $verified = (clone $partners)
            ->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_APPROVED))
            ->count();

        return [
            'active'        => $active,
            'verified'      => $verified,
            'total_revenue' => round($revenue, 2),
            'high_risk'     => $highRisk,
        ];
    }

    /** @return array<string, int> */
    private function typeCounts(): array
    {
        $base = User::role(['Individual', 'Company'])->whereHas('partnerDetail');

        return [
            'all'         => (clone $base)->count(),
            'individuals' => (clone $base)->whereHas('partnerDetail', fn ($q) => $q->where('type', 'individual'))->count(),
            'companies'   => (clone $base)->whereHas('partnerDetail', fn ($q) => $q->where('type', 'company'))->count(),
        ];
    }

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

    private function notifyApplicant(User $user, bool $approved, ?string $reason = null): void
    {
        try {
            $user->notify(new PartnerApplicationResult($approved, $reason));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
