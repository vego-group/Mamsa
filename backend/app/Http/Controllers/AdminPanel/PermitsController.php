<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\DashboardUpload;
use App\Models\Permit;
use App\Models\Unit;
use App\Notifications\UnitReviewResult;
use App\Support\Permits\PermitExpiry;
use App\Support\Permits\PermitRenewal;
use App\Support\Units\LicenseViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Permits on the admin console: what is about to lapse, and the renewals
 * waiting on a decision.
 *
 * Renewals are a list of their OWN rather than a new kind of row in
 * /admin/approvals. The approvals queue is a list of listings, and every
 * screen and filter there treats an item as one — dropping a document review
 * into it would make "unit name" and "partner" mean two different things
 * depending on the row. A reviewer looking at a renewal is answering one
 * question: does this document cover this listing, and until when.
 */
class PermitsController extends Controller
{
    /** GET /admin/permits?status=expiring|expired|valid — what needs attention. */
    public function index(Request $request): JsonResponse
    {
        $args = $this->listArgs($request);
        $status = $this->cleanParam($request->query('status')) ?? 'expiring';

        $query = Permit::query()->where('status', Permit::STATUS_CURRENT)->whereNotNull('expires_at');

        $today = now()->startOfDay();

        match ($status) {
            'expired' => $query->whereDate('expires_at', '<', $today->toDateString()),
            'valid' => $query->whereDate('expires_at', '>', $today->copy()->addDays(PermitExpiry::warningDays())->toDateString()),
            // The default is the one a human acts on: still valid, not for long.
            default => $query
                ->whereDate('expires_at', '>=', $today->toDateString())
                ->whereDate('expires_at', '<=', $today->copy()->addDays(PermitExpiry::warningDays())->toDateString()),
        };

        $page = $this->queryList($query, $args, [], ['expiresAt' => 'expires_at'], ['expires_at', 'asc']);

        return $this->items($page, fn (Permit $p) => $this->shape($p));
    }

    /** GET /admin/permit-renewals — the queue of documents awaiting a decision. */
    public function renewals(Request $request): JsonResponse
    {
        $args = $this->listArgs($request);
        $status = $this->cleanParam($request->query('status')) ?? Permit::STATUS_PENDING;

        $query = Permit::query()->where('status', $status);

        $page = $this->queryList($query, $args, [], ['submittedAt' => 'created_at'], ['created_at', 'asc']);

        return $this->items($page, fn (Permit $p) => $this->shape($p, withCurrent: true));
    }

    /** POST /admin/permit-renewals/:id/approve — it becomes the permit in force. */
    public function approve(Request $request, string $id): JsonResponse
    {
        $renewal = $this->pending($id);

        try {
            $renewal = PermitRenewal::approve($renewal, (int) $request->user()->id);
        } catch (LicenseViolation $e) {
            $this->fail($e->reason, $e->getMessage(), 409, null, $e->meta);
        }

        $this->tellTheOwner($renewal, approved: true);

        return response()->json($this->shape($renewal));
    }

    /** POST /admin/permit-renewals/:id/reject — the permit in force carries on. */
    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], ['reason.required' => 'يجب إدخال سبب الرفض']);

        $renewal = $this->pending($id);

        try {
            $renewal = PermitRenewal::reject($renewal, $data['reason'], (int) $request->user()->id, $data['notes'] ?? null);
        } catch (LicenseViolation $e) {
            $this->fail($e->reason, $e->getMessage(), 409);
        }

        $this->tellTheOwner($renewal, approved: false, reason: $data['reason']);

        return response()->json($this->shape($renewal));
    }

    /* ---------- helpers ---------- */

    private function pending(string $id): Permit
    {
        $renewal = Permit::find($id);

        if (! $renewal) {
            $this->fail('NOT_FOUND', 'طلب التجديد غير موجود', 404);
        }

        if ($renewal->status !== Permit::STATUS_PENDING) {
            $this->fail('RENEWAL_NOT_PENDING', 'طلب التجديد لم يعد قيد المراجعة', 409);
        }

        return $renewal;
    }

    private function tellTheOwner(Permit $permit, bool $approved, ?string $reason = null): void
    {
        try {
            $unit = $permit->units()->first();
            $owner = $unit?->owner;

            // A platform listing has no partner to tell; its own admins already
            // see the queue. Silence here is correct, not an oversight.
            if ($unit && $owner && ! $unit->mamsa_owned) {
                Notification::send([$owner], new UnitReviewResult($unit, $approved, $reason));
            }
        } catch (\Throwable $e) {
            report($e); // a decision that was made must not fail on its email
        }
    }

    /** @return array<string, mixed> */
    private function shape(Permit $permit, bool $withCurrent = false): array
    {
        $unit = $permit->units()->first();

        $row = [
            'id' => (string) $permit->id,
            'status' => $permit->status,
            'scope' => $permit->scope_type,
            'unitId' => $unit ? (string) $unit->id : null,
            'unitName' => $unit?->unit_name,
            'partnerName' => $unit?->mamsa_owned ? 'ممسى' : $unit?->owner?->name,
            'mamsaOwned' => (bool) ($unit?->mamsa_owned),
            'unitsCovered' => $permit->units()->count(),
            'tourismPermitNo' => $permit->number,
            'permitFileUrl' => DashboardUpload::signedUrl($permit->file),
            'licenseType' => $permit->license_type,
            'licensedUnitsCount' => $permit->licensed_units_count,
            'permitExpiresAt' => $permit->expires_at?->toDateString(),
            'permitStatus' => $unit ? PermitExpiry::status($unit) : null,
            // The address printed on the document, for the reviewer to set
            // beside the listing's own — the comparison a human makes.
            'permitAddress' => [
                'city' => $permit->addr_city,
                'district' => $permit->addr_district,
                'building' => $permit->addr_building,
                'unitNo' => $permit->addr_unit_no,
            ],
            'listingAddress' => $unit ? [
                'city' => $unit->city,
                'district' => $unit->district,
                'address' => $unit->address,
                'lat' => $unit->lat !== null ? (float) $unit->lat : null,
                'lng' => $unit->lng !== null ? (float) $unit->lng : null,
            ] : null,
            'submittedAt' => $permit->created_at?->toIso8601ZuluString(),
            'reviewedAt' => $permit->reviewed_at?->toIso8601ZuluString(),
            'reviewedBy' => $permit->reviewed_by,
            'createdBy' => $permit->created_by,
            'rejectionReason' => $permit->rejection_reason,
        ];

        if ($withCurrent && $unit) {
            // What it would replace — a reviewer compares the two dates before
            // anything else.
            $current = Permit::query()->forUnit($unit)->current()->first();

            $row['currentPermit'] = $current ? [
                'id' => (string) $current->id,
                'tourismPermitNo' => $current->number,
                'permitExpiresAt' => $current->expires_at?->toDateString(),
            ] : null;
        }

        return $row;
    }
}
