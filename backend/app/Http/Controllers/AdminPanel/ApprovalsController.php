<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Unit;
use App\Notifications\UnitReviewResult;
use App\Support\AdminPanel\UnitPresenter;
use App\Support\Permits\PermitExpiry;
use App\Support\Units\LicenseViolation;
use App\Support\Units\UnitLicense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Approvals (unit review queue) — BACKEND_SPEC §5.7. A request is a unit in
 * `pending` (pending_review). Default order: oldest submittedAt first (SLA).
 * submittedAt uses the stamped submitted_at; approvedAt uses updated_at
 * (units carry no reviewed_at column).
 */
class ApprovalsController extends Controller
{
    // Oldest submission first is the SLA order, so sort by the real submission
    // time. Every pending row has it: backfilled for existing rows, stamped by
    // the observer for new ones.
    private const SORT = ['submittedAt' => 'submitted_at'];

    public function __construct(private readonly UnitPresenter $units) {}

    public function index(Request $request): JsonResponse
    {
        $args = $this->listArgs($request);
        $query = Unit::query()->with(['owner.partnerDetail', 'images'])->where('approval_status', 'pending');

        if ($rt = $this->cleanParam($request->query('requestType'))) {
            match ($rt) {
                'new' => $query->whereNull('rejection_reason'),
                'resubmission' => $query->whereNotNull('rejection_reason'),
                'reapproval_after_edit' => $query->whereRaw('1 = 0'), // not tracked yet
                default => null,
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

        $page = $this->queryList($query, $args, [], self::SORT, ['submitted_at', 'asc']);

        return $this->items($page, fn (Unit $u) => $this->units->approvalRow($u));
    }

    /**
     * GET /admin/approvals/stats?range=today|7d|30d
     *
     * `pendingReview` is deliberately NOT scoped by range — it answers "how much
     * work is on my desk right now", not "how much arrived this week". The
     * decision counters and the average ARE scoped, by decision time.
     */
    public function stats(Request $request): JsonResponse
    {
        $range = $this->approvalsRange($request->query('range'));
        [$from, $to] = $this->rangeWindow($range);

        // A decided unit's `updated_at` is its decision time (units carry no
        // dedicated reviewed_at column — see the note in the response docs).
        $decided = fn (string $status) => Unit::where('approval_status', $status)
            ->whereBetween('updated_at', [$from, $to])->count();

        // Real reviewer latency: submission → decision. Rows with no
        // submitted_at are pre-migration decisions whose submission time is
        // unrecoverable; they are excluded rather than counted from created_at,
        // which would fold draft time into the SLA.
        // NULL, not 0, when nothing in the window has a measurable submission:
        // 0 renders as "reviews are instant", which is the same false signal in
        // the other direction. The client shows "no data" for null.
        $measurable = Unit::whereIn('approval_status', ['approved', 'rejected'])
            ->whereBetween('updated_at', [$from, $to])
            ->whereNotNull('submitted_at');

        // The sample size travels with the average. Without it the screen shows
        // "7 decisions" beside "no measured decisions" and reads as broken —
        // both true, but only explicable if the client can say "averaged over
        // 3 of 7". Stays useful after the backfill window, whenever a decision
        // is missing a timestamp for any reason.
        $sample = (clone $measurable)->count();
        $avg = $measurable->selectRaw($this->avgHoursSql('submitted_at', 'updated_at').' as h')->value('h');

        $approved = $decided('approved');
        $rejected = $decided('rejected');

        return response()->json([
            'pendingReview' => Unit::where('approval_status', 'pending')->count(),
            'approved' => $approved,
            'rejected' => $rejected,
            'avgReviewHours' => $avg === null ? null : round((float) $avg, 1),
            'avgReviewSample' => $sample,
            'range' => $range,

            // Legacy keys — kept so a client that predates `range` keeps working.
            // They mirror the requested window rather than always "today", which
            // is only a difference when range !== 'today'.
            'approvedToday' => $approved,
            'rejectedToday' => $rejected,
        ]);
    }

    /** Whitelist the range; anything unknown or absent means today. */
    private function approvalsRange(mixed $raw): string
    {
        return in_array($raw, ['today', '7d', '30d'], true) ? (string) $raw : 'today';
    }

    /**
     * `today` is the calendar day in Asia/Riyadh — not a rolling 24 hours, and
     * not the UTC day the app otherwise runs in. `7d`/`30d` roll back from now.
     *
     * @return array{0:Carbon, 1:Carbon}
     */
    private function rangeWindow(string $range): array
    {
        $tz = 'Asia/Riyadh';

        return match ($range) {
            '7d' => [now()->subDays(7), now()],
            '30d' => [now()->subDays(30), now()],
            // Convert the Riyadh day boundaries into the UTC instants the
            // timestamps are actually stored in.
            default => [
                now($tz)->startOfDay()->setTimezone('UTC'),
                now($tz)->endOfDay()->setTimezone('UTC'),
            ],
        };
    }

    public function show(string $id): JsonResponse
    {
        $u = $this->units->baseQuery()->with(['features', 'owner.partnerDetail', 'cancellationPolicy'])->whereKey($id)->first();

        if (! $u) {
            $this->fail('NOT_FOUND', 'الطلب غير موجود', 404);
        }

        $owner = $u->owner;
        $rating = $owner ? $owner->unitReviews()->avg('rating') : null;

        return response()->json(array_merge($this->units->approvalRow($u), [
            'unit' => $this->units->detail($u),
            'partnerVerified' => $owner?->partnerDetail?->verified_at !== null,
            'partnerRating' => $rating !== null ? round((float) $rating, 1) : 0.0,
            // The permit's own address beside the listing's, and the machine's
            // opinion of whether they agree — see addressMatch() for what that
            // opinion is worth.
            'permit' => [
                'address' => $this->units->detail($u)['permitAddress'],
                'expiresAt' => PermitExpiry::on($u)?->toDateString(),
                'status' => PermitExpiry::status($u),
            ],
            'addressMatch' => $this->addressMatch($u),
            'group' => $this->groupContext($u),
        ]));
    }

    /**
     * Does the address on the permit match the listing's?
     *
     * Only the CITY is answered. Cities go through one canonical map
     * ({@see \App\Support\City}), so two spellings of Riyadh reduce to one
     * value and a comparison means something. Districts are free text on both
     * sides — "النرجس" and "حي النرجس" are the same place and different
     * strings — so comparing them would produce confident nonsense, and a
     * reviewer who saw one false mismatch would stop reading the field.
     *
     * `null` means "not compared", never "matches".
     *
     * @return array{city: bool|null, district: null}
     */
    private function addressMatch(Unit $u): array
    {
        $permit = \App\Models\Permit::currentFor($u);
        $permitCity = $permit?->addr_city;

        return [
            'city' => $permitCity === null || $u->city === null
                ? null
                : \App\Support\City::toArabic((string) $permitCity) === \App\Support\City::toArabic((string) $u->city),
            // Deliberately never computed. See above.
            'district' => null,
        ];
    }

    /**
     * The building this listing belongs to, so a reviewer approving one
     * apartment can see what else is in the queue behind it — and, in per-unit
     * mode, that each door carries a different permit.
     *
     * Null for a standalone listing rather than a group of one: the screen
     * should show nothing, not a building with one door.
     *
     * @return array<string, mixed>|null
     */
    private function groupContext(Unit $u): ?array
    {
        if (! $u->unit_group_id) {
            return null;
        }

        $members = Unit::where('unit_group_id', $u->unit_group_id)->orderBy('apartment_no')->get();

        return [
            'id' => $u->unit_group_id,
            'size' => $members->count(),
            'mode' => \App\Support\Permits\PermitMode::of($u),
            'apartments' => $members->map(fn (Unit $m) => [
                'id' => (string) $m->id,
                'apartmentNo' => $m->apartment_no,
                'status' => $m->approval_status,
                'permitNumber' => $m->tourism_permit_no,
                'permitExpiresAt' => PermitExpiry::on($m)?->toDateString(),
            ])->values()->all(),
        ];
    }

    public function approve(string $id): JsonResponse
    {
        $unit = $this->pendingUnit($id);

        // The licence rule is not a form hint the console may wave through.
        // An apartment in a building of ten, approved under a permit that
        // covers one, is a unit trading illegally — and the reviewer clicking
        // approve is exactly the person who might do it by mistake, because
        // the offending fact is on a sibling row rather than this one.
        //
        // The permit, not the rollout flag: this apartment already exists, so
        // approving it expands nothing. With the flag in the check, an edit to
        // one apartment of a live building (edit → pending) could never be
        // re-approved while partner expansion was switched off — the row sat
        // off the storefront for a reason that had nothing to do with it.
        try {
            UnitLicense::guardLicenceCovers($unit, UnitLicense::groupSize($unit));
        } catch (LicenseViolation $e) {
            $this->fail($e->reason, $e->getMessage(), 422, null, $e->meta);
        }

        $unit->update(['approval_status' => 'approved', 'rejection_reason' => null]);
        $this->notifyOwner($unit, true);

        return $this->ok();
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
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
