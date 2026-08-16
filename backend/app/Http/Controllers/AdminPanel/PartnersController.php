<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\PartnerApplicationResult;
use App\Services\Sms\SmsProvider;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Partners (hosts) — BACKEND_SPEC §5.5. Live aggregates from each partner's
 * units/bookings/reviews; flagged = 12-month cancellation rate over threshold.
 */
class PartnersController extends Controller
{
    private const HIGH_RISK_RATE = 15.0;

    public function __construct(private readonly SmsProvider $sms) {}

    private const SORT = [
        'revenue'       => 'revenue',
        'rating'        => 'rating',
        'unitsCount'    => 'units_count',
        'bookingsCount' => 'bookings_count',
        'joinedAt'      => 'created_at',
        'name'          => 'name',
    ];

    public function index(Request $request): JsonResponse
    {
        $args  = $this->listArgs($request);
        $query = $this->baseQuery();

        if ($type = $this->cleanParam($request->query('type'))) {
            $query->whereHas('partnerDetail', fn ($q) => $q->where('type', $type));
        }
        if ($status = $this->cleanParam($request->query('status'))) {
            $this->applyStatus($query, $status);
        }

        $page = $this->queryList($query, $args, ['name', 'phone', 'email'], self::SORT, ['created_at', 'desc']);

        return $this->items($page, fn (User $u) => $this->row($u));
    }

    public function stats(): JsonResponse
    {
        $base = User::role(['Individual', 'Company'], 'web')->whereHas('partnerDetail');

        $highRisk = $this->baseQuery()->get()->filter(fn (User $u) => $this->flagged($this->rateOf($u)))->count();

        return response()->json([
            'total'        => (clone $base)->count(),
            'individuals'  => (clone $base)->whereHas('partnerDetail', fn ($q) => $q->where('type', 'individual'))->count(),
            'companies'    => (clone $base)->whereHas('partnerDetail', fn ($q) => $q->where('type', 'company'))->count(),
            'active'       => (clone $base)->where('is_active', true)->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_APPROVED))->count(),
            'pending'      => (clone $base)->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_PENDING))->count(),
            'verified'     => (clone $base)->whereHas('partnerDetail', fn ($q) => $q->whereNotNull('verified_at'))->count(),
            'highRisk'     => $highRisk,
            'totalRevenue' => $this->money(Booking::query()->revenue()->sum('total_amount')),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $u = $this->baseQuery()->whereKey($id)->first();

        if (! $u || ! $u->partnerDetail) {
            $this->fail('NOT_FOUND', 'الشريك غير موجود', 404);
        }

        $d          = $u->partnerDetail;
        $revenue    = $this->money($u->revenue);
        $commission = $this->money((float) $u->subtotal_sum * Booking::COMMISSION_RATE);
        $bookings   = (int) $u->bookings_count;

        // The partner's earnings are the SUM of the frozen per-booking share,
        // not revenue − commission. `revenue` is VAT-inclusive gross and the VAT
        // is remitted to ZATCA, so subtracting only the commission credits the
        // partner the guest's VAT. On one staging partner that reported
        // 108,454.35 against a true 88,566.96 — a 19,887 SAR overstatement, and
        // a number the wallet would never pay.
        $earning = (float) Booking::query()->revenue()
            ->whereHas('unit', fn ($q) => $q->where('user_id', $u->id))
            ->sum('partner_share');

        return response()->json(array_merge($this->row($u), [
            'nationalId'       => $d->national_id,
            'tourismPermitNo'  => null,                 // tourism permit is per-unit, not on the partner
            'crNumber'         => $d->cr_number,
            'iban'             => $d->iban,
            'documents'        => $this->documents($d),
            'documentsComplete'=> $this->documentsComplete($d),
            'commissionPaid'   => $commission,
            'partnerEarning'   => $this->money($earning),
            'avgPerBooking'    => $bookings > 0 ? $this->money($revenue / $bookings) : 0.0,
            'rejectionReason'  => $d->rejection_reason,
            // Recorded on suspend and never surfaced until now: an admin looking
            // at a suspended partner could not see why, which is the one thing
            // they open the page for. Cleared by /reactivate.
            'suspensionReason' => $d->suspension_reason,
        ]));
    }

    /* ---------- mutations §5.5 ---------- */

    /** POST /admin/partners/:id/approve — pending → active. */
    public function approve(string $id): JsonResponse
    {
        $d = $this->pendingDetail($id);
        $d->update(['status' => PartnerDetail::STATUS_APPROVED, 'rejection_reason' => null, 'reviewed_at' => now()]);
        $this->notifyApplicant($d->user, true);

        return $this->ok();
    }

    /** POST /admin/partners/:id/reject — { reason }, pending → rejected. */
    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'يجب إدخال سبب الرفض']);

        $d = $this->pendingDetail($id);
        $d->update(['status' => PartnerDetail::STATUS_REJECTED, 'rejection_reason' => $data['reason'], 'reviewed_at' => now()]);
        $this->notifyApplicant($d->user, false, $data['reason']);

        return $this->ok();
    }

    /** POST /admin/partners/:id/suspend — { reason }, active → suspended. */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'يجب إدخال سبب الإيقاف']);

        $u = $this->partner($id);

        if (! $u->is_active || $u->partnerDetail->status !== PartnerDetail::STATUS_APPROVED) {
            $this->fail('CONFLICT', 'لا يمكن إيقاف هذا الشريك في حالته الحالية', 409);
        }

        $u->update(['is_active' => false]);
        $u->partnerDetail->update(['suspension_reason' => $data['reason']]);

        return $this->ok();
    }

    /**
     * POST /admin/partners/:id/reactivate — suspended → active.
     *
     * Exists because PATCH /admin/users/:id/status flips `is_active` and leaves
     * the stored suspension reason behind, so a reactivated partner keeps a
     * stale "why they were suspended" on their record forever and reads as
     * suspended to the next admin who opens it. Clearing the reason is the
     * whole point of a separate endpoint.
     */
    public function reactivate(string $id): JsonResponse
    {
        $u = $this->partner($id);

        // Deliberately not a general "activate": an invited partner who never
        // completed KYC is inactive too, and turning them on here would skip
        // the review entirely.
        if ($u->is_active || $u->partnerDetail->status !== PartnerDetail::STATUS_APPROVED) {
            $this->fail('CONFLICT', 'لا يمكن إعادة تفعيل هذا الشريك في حالته الحالية', 409);
        }

        $u->update(['is_active' => true]);
        $u->partnerDetail->update(['suspension_reason' => null]);

        return $this->ok();
    }

    /** POST /admin/partners/:id/verify — grant the verified badge. */
    public function verify(string $id): JsonResponse
    {
        $this->partner($id)->partnerDetail->update(['verified_at' => now()]);

        return $this->ok();
    }

    /** POST /admin/partners/:id/revoke-verification — drop the badge. */
    public function revokeVerification(string $id): JsonResponse
    {
        $this->partner($id)->partnerDetail->update(['verified_at' => null]);

        return $this->ok();
    }

    /** POST /admin/partners/:partnerId/documents/:documentId/verify — mark one KYC doc verified. */
    public function verifyDocument(string $partnerId, string $documentId): JsonResponse
    {
        $d    = $this->partner($partnerId)->partnerDetail;
        $docs = (array) ($d->verified_documents ?? []);

        if (! in_array($documentId, $docs, true)) {
            $docs[] = $documentId;
            $d->update(['verified_documents' => array_values($docs)]);
        }

        return $this->ok();
    }

    /** POST /admin/partners/invite — { phone, type, name? }. Creates a pending partner + SMS. */
    public function invite(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'phone' => ['required', 'string', 'regex:/^(\+?9665\d{8}|05\d{8}|5\d{8})$/'],
            'type'  => ['required', Rule::in(['individual', 'company'])],
            'name'  => ['sometimes', 'nullable', 'string', 'max:100'],
        ], ['phone.regex' => 'رقم جوال غير صالح', 'type.in' => 'نوع الشريك غير صالح']);

        $phone = PhoneNumber::toE164Ksa($data['phone']);

        if (User::where('phone', $phone)->exists()) {
            $this->fail('CONFLICT', 'هذا الرقم مسجّل بالفعل', 409);
        }

        $user = User::create([
            'name'       => $data['name'] ?? null,
            'phone'      => $phone,
            'is_active'  => false,
            'invited_at' => now(),
        ]);
        $user->assignRole(Role::findByName($data['type'] === 'company' ? 'Company' : 'Individual', 'web'));
        $user->partnerDetail()->create([
            'type'   => $data['type'],
            'status' => PartnerDetail::STATUS_PENDING,
        ]);

        $this->sendInviteSms($phone, 'تمت دعوتك لتصبح شريكاً في منصة ممسى. حمّل تطبيق الشركاء وأكمل بيانات التوثيق.');

        return $this->ok();
    }

    /* ---------- mutation helpers ---------- */

    private function partner(string $id): User
    {
        $u = User::role(['Individual', 'Company'], 'web')->with('partnerDetail')->whereKey($id)->first();

        if (! $u || ! $u->partnerDetail) {
            $this->fail('NOT_FOUND', 'الشريك غير موجود', 404);
        }

        return $u;
    }

    private function pendingDetail(string $id): PartnerDetail
    {
        $d = $this->partner($id)->partnerDetail;

        if ($d->status !== PartnerDetail::STATUS_PENDING) {
            $this->fail('CONFLICT', 'طلب الشريك ليس قيد المراجعة', 409);
        }

        return $d;
    }

    private function notifyApplicant(User $user, bool $approved, ?string $reason = null): void
    {
        try {
            $user->notify(new PartnerApplicationResult($approved, $reason));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function sendInviteSms(string $phone, string $message): void
    {
        try {
            $this->sms->send($phone, $message, config('sms.sender_id'));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ---------- query ---------- */

    private function baseQuery(): Builder
    {
        $yearAgo = now()->subYear();

        return User::query()->role(['Individual', 'Company'], 'web')
            ->whereHas('partnerDetail')
            ->with('partnerDetail')
            ->withCount('units')
            ->withCount(['unitBookings as bookings_count'])
            ->withCount(['unitBookings as bookings_12m' => fn ($q) => $q->where('bookings.created_at', '>=', $yearAgo)])
            ->withCount(['unitBookings as cancellations_12m' => fn ($q) => $q->where('bookings.status', 'cancelled')->where('bookings.created_at', '>=', $yearAgo)])
            ->withSum(['unitBookings as revenue' => fn ($q) => $q->whereIn('bookings.status', Booking::REVENUE_STATUSES)], 'total_amount')
            ->withSum(['unitBookings as subtotal_sum' => fn ($q) => $q->whereIn('bookings.status', Booking::REVENUE_STATUSES)], 'subtotal')
            ->withAvg(['unitReviews as rating'], 'rating')
            ->addSelect(['city' => Unit::query()->select('city')->whereColumn('units.user_id', 'users.id')->latest()->limit(1)]);
    }

    private function applyStatus(Builder $query, string $status): void
    {
        match ($status) {
            'pending'   => $query->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_PENDING)),
            'rejected'  => $query->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_REJECTED)),
            'active'    => $query->where('is_active', true)->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_APPROVED)),
            'suspended' => $query->where('is_active', false)->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_APPROVED)),
            default     => null,
        };
    }

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        $d    = $u->partnerDetail;
        $c12  = (int) $u->cancellations_12m;
        $rate = $this->rateOf($u);

        return [
            'id'               => (string) $u->id,
            'code'             => $this->code('PTR', $u->id),
            'name'             => $u->name ?? '',
            'type'             => $d?->type ?? 'individual',
            'city'             => $u->city ?? '',
            'email'            => $u->email ?? '',
            'phone'            => (string) $u->phone,
            'joinedAt'         => $this->iso($u->created_at),
            'unitsCount'       => (int) $u->units_count,
            'bookingsCount'    => (int) $u->bookings_count,
            'revenue'          => $this->money($u->revenue),
            'rating'           => $u->rating !== null ? round((float) $u->rating, 1) : 0.0,
            'verified'         => $d?->verified_at !== null,
            'status'           => $this->partnerStatus($u, $d),
            // Raw signal alongside the derived `status` (which folds both into one
            // string): payout eligibility is `approved` AND `isActive`, so the
            // client needs the flag, not just the label.
            'isActive'         => (bool) $u->is_active,
            'cancellations12m' => $c12,
            'cancellationRate' => $rate,
            'flagged'          => $this->flagged($rate),
        ];
    }

    private function rateOf(User $u): float
    {
        $b = (int) $u->bookings_12m;

        return $b > 0 ? round(((int) $u->cancellations_12m / $b) * 100, 1) : 0.0;
    }

    private function flagged(float $rate): bool
    {
        return $rate >= self::HIGH_RISK_RATE;
    }

    /* ---------- documents ---------- */

    /** @return array<int, array<string, mixed>> */
    private function documents(PartnerDetail $d): array
    {
        // `verified` now means ONE thing: an admin checked this document via
        // documents/:id/verify. It used to fall back to the partner-level KYC
        // status, so approving a partner turned every row green at once —
        // including rows with no file behind them at all. A reviewer then read
        // a badge that recorded somebody's decision about the *partner* as
        // though it recorded a review of the *document*.
        //
        // A rejected partner still marks its documents rejected: that is not a
        // false claim of review, and 'pending_review' on a rejected file would
        // read as "still with us" when it is not.
        $default = $d->status === PartnerDetail::STATUS_REJECTED
            ? 'rejected'
            : 'pending_review';
        $verified = (array) ($d->verified_documents ?? []);

        return array_map(fn (array $row) => [
            'id'      => $row['kind'],
            'kind'    => $row['kind'],
            'label'   => $row['label'],
            'fileUrl' => $this->fileUrl($row['file']),
            'value'   => $row['value'],
            'status'  => in_array($row['kind'], $verified, true) ? 'verified' : $default,
        ], $this->documentRows($d));
    }

    /**
     * The KYC rows, before presentation — `file` is the STORED reference, not a
     * resolved URL.
     *
     * documentsComplete() folds over this rather than over the public shape on
     * purpose: `fileUrl` is null both when nothing was uploaded and when an
     * upload row has gone missing, and those are different facts. Completeness
     * asks "was it supplied", which only the raw column can answer.
     *
     * @return list<array{kind: string, label: string, value: ?string, file: ?string}>
     */
    private function documentRows(PartnerDetail $d): array
    {
        $mk = fn (string $kind, string $label, ?string $value, ?string $file) => compact('kind', 'label', 'value', 'file');

        $docs = [];
        if ($d->type === 'company') {
            $docs[] = $mk('commercial_registration', 'السجل التجاري', $d->cr_number, null);
            $docs[] = $mk('vat_certificate', 'شهادة ضريبة القيمة المضافة', null, $d->vat_certificate_file);
            $docs[] = $mk('operator_license', 'رخصة تشغيل', null, $d->operator_license_file);
        } else {
            $docs[] = $mk('national_id', 'الهوية الوطنية', $d->national_id, $d->national_id_file);
        }
        $docs[] = $mk('authorization_letter', 'خطاب تفويض', null, $d->authorization_letter_file);
        $docs[] = $mk('iban', 'رقم الآيبان', $d->iban, null);

        return $docs;
    }

    /**
     * "Has this partner SUBMITTED everything required?" — and nothing else.
     *
     * Derived from the very rows in `documents[]`: every kind that can carry a
     * file has one, every value-backed kind has a value. The two therefore
     * cannot contradict each other on screen, which they used to — this read
     * unrelated columns AND required KYC approval, so `false` sat above five
     * green rows and neither field was wrong about its own question.
     *
     * Approval status is deliberately NOT part of it: whether the documents
     * were *reviewed* is a separate claim, folded client-side over
     * `documents[].status`. One fact per field, each owned by the side that can
     * establish it.
     */
    private function documentsComplete(PartnerDetail $d): bool
    {
        return collect($this->documentRows($d))->every(
            // A row is satisfied by whichever of the two it is backed by:
            // `commercial_registration` and `iban` are numbers with no file and
            // can never have one, so requiring a file would make a complete
            // company permanently incomplete.
            fn (array $doc) => filled($doc['file']) || filled($doc['value']),
        );
    }

    private function fileUrl(?string $path): ?string
    {
        // KYC doc columns store a DashboardUpload id (file_...) → resolve to its
        // real public path.
        return \App\Models\DashboardUpload::resolveUrl($path);
    }
}
