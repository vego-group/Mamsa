<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bookings\CancelBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\CancellationPolicyService;
use App\Support\Booking\Availability;
use App\Support\Booking\UnitUnavailable;
use App\Support\Pricing;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    use ApiResponse;

    public function show(Booking $booking): BookingResource|JsonResponse
    {
        if ($booking->user_id !== auth()->id()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $booking->load(['unit.images', 'unit.features', 'unit.owner', 'user', 'payment', 'review']);

        return new BookingResource($booking);
    }

    /**
     * Preview the refund the guest would receive if they cancelled now —
     * FR-043/044. Read-only; never touches the gateway.
     */
    public function cancellationPreview(Booking $booking, CancellationPolicyService $policy): JsonResponse
    {
        if ($booking->user_id !== auth()->id()) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success($policy->quote($booking)->toArray());
    }

    /**
     * Cancel a booking and run the automatic refund/void — FR-046/047.
     * All business rules live in CancelBookingAction; the controller stays thin.
     */
    public function cancel(Request $request, Booking $booking, CancelBookingAction $action): JsonResponse
    {
        if ($booking->user_id !== auth()->id()) {
            return $this->error('غير مصرح', 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $quote = $action->execute($booking, $booking->user, $data['reason'] ?? null);

        return $this->success(
            $quote->toArray(),
            $quote->refundAmount > 0 ? 'تم الإلغاء وسيتم رد المبلغ المستحق' : 'تم إلغاء الحجز',
        );
    }

    /**
     * GET /bookings/{booking}/review — the review left on this booking.
     *
     * A guest could write one and never read it back; the only review endpoint
     * was the write. Returns the object or a bare `null` (200) — an unreviewed
     * booking is an ordinary state, not a 404.
     *
     * Readable by the guest who booked, the partner who owns the unit, and
     * admins: all three already see the review elsewhere, and gating it here
     * would only hide it from the person who wrote it.
     */
    public function review(Booking $booking): JsonResponse
    {
        $viewer = auth()->user();
        $isGuest = (int) $booking->user_id === (int) $viewer?->id;
        $isOwner = (int) ($booking->unit?->user_id ?? 0) === (int) $viewer?->id;

        if (! $isGuest && ! $isOwner && ! $viewer?->isAdmin()) {
            return response()->json(['message' => 'غير مصرح', 'code' => 'FORBIDDEN'], 403);
        }

        $review = $booking->review()->with('user')->first();

        if (! $review) {
            return response()->json(null);
        }

        return response()->json([
            'id' => $review->id,
            'booking_id' => (int) $review->booking_id,
            'unit_id' => (int) $review->unit_id,
            'user_id' => (int) $review->user_id,
            'user_name' => $review->user?->name,
            // No avatar storage exists yet, so this is null for everyone rather
            // than a placeholder that would look like a real picture failing.
            'user_avatar_url' => null,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at?->toIso8601ZuluString(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // Email task doc §2 — a verified email is required before booking so
        // confirmations/refund notices have a trusted channel. The frontend
        // branches on the machine code and routes to the /user/email flow.
        if (config('booking.require_verified_email')) {
            $user = $request->user();
            if (blank($user->email) || ! $user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب توثيق بريدك الإلكتروني قبل إتمام الحجز.',
                    'code' => 'EMAIL_VERIFICATION_REQUIRED',
                ], 422);
            }
        }

        $data = $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'guests' => ['required', 'integer', 'min:1'],
            // Split counts (§2.3). `guests` stays the TOTAL; children is a
            // subset of it. Optional so older clients sending only `guests`
            // keep working (children defaults to 0).
            'children' => ['sometimes', 'integer', 'min:0', 'lte:guests'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Present now, locked to 1, so opening multi-unit booking later
            // does not change the request shape clients already send.
            'units_count' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ((int) ($data['units_count'] ?? 1) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حجز أكثر من وحدة في الطلب الواحد غير مدعوم حالياً',
                'code' => 'MULTI_UNIT_BOOKING_NOT_SUPPORTED',
                'meta' => ['max_units_count' => 1],
            ], 422);
        }

        // Idempotency: a double-tap that beats the disabled button, or a proxy
        // retry, must not produce two bookings for the same stay. The key is
        // checked again INSIDE the lock — checking only here is a read followed
        // by a write, and two requests in the same millisecond both pass it.
        $idempotencyKey = trim((string) $request->header('Idempotency-Key')) ?: null;

        if ($idempotencyKey !== null && mb_strlen($idempotencyKey) > 64) {
            return response()->json([
                'success' => false,
                'message' => 'مفتاح التكرار أطول من المسموح',
                'code' => 'IDEMPOTENCY_KEY_TOO_LONG',
                'meta' => ['max_length' => 64],
            ], 422);
        }

        if ($idempotencyKey !== null
            && $existing = Booking::where('idempotency_key', $idempotencyKey)
                ->where('user_id', $request->user()->id)->first()) {
            return response()->json(
                new BookingResource($existing->load(['unit.images', 'user'])), 200,
            );
        }

        $unit = Unit::where('id', $data['unit_id'])
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->firstOrFail();

        $nights = (int) now()->parse($data['start_date'])->diffInDays($data['end_date']);

        /*
         * Check and create under a lock on the UNIT row.
         *
         * The availability probe the client ran earlier is advice, not a
         * reservation — nothing stops another guest booking the same nights in
         * the seconds between. Re-checking here without a lock only narrows that
         * window: two requests can both read "free" before either has written,
         * and both then succeed. MySQL has no exclusion constraint to express
         * "no overlapping ranges", so the unit row is the thing to serialise on.
         *
         * Concurrent bookings for DIFFERENT units are unaffected; two for the
         * same unit queue, and the second sees the first's row.
         */
        try {
            $booking = DB::transaction(function () use ($unit, $data, $nights, $idempotencyKey, $request) {
                // Re-checked under the lock. Above the transaction this is
                // advice; here it is a decision, because the unit row is held.
                if ($idempotencyKey !== null
                    && $replay = Booking::where('idempotency_key', $idempotencyKey)
                        ->where('user_id', $request->user()->id)->first()) {
                    return $replay;
                }

                /*
                 * A multi-unit building is ONE card, so the id the guest sends
                 * is whichever apartment the listing happened to show. Booking
                 * that exact row would fail while four of its five siblings sat
                 * empty — and two guests clicking the same card would collide
                 * on it. So the group is the thing being booked, and the server
                 * picks a free apartment out of it.
                 *
                 * Every sibling is locked, ALWAYS in id order: two concurrent
                 * bookings that locked the same rows in different orders would
                 * deadlock rather than queue.
                 */
                $candidates = $unit->unit_group_id
                    ? Unit::where('unit_group_id', $unit->unit_group_id)
                        ->where('approval_status', 'approved')
                        ->where('status', 'available')
                        ->orderBy('id')->lockForUpdate()->get()
                    : Unit::whereKey($unit->id)->lockForUpdate()->get();

                $booked = false;
                $blocked = false;

                foreach ($candidates as $candidate) {
                    if (Availability::conflictingBookings((int) $candidate->id, $data['start_date'], $data['end_date'])->exists()) {
                        $booked = true;

                        continue;
                    }

                    if ($candidate->blockedDates()->overlapping($data['start_date'], $data['end_date'])->exists()) {
                        $blocked = true;

                        continue;
                    }

                    // Priced from the apartment actually allocated, not from the
                    // one the card showed: siblings start identical but a partner
                    // can reprice one, and the split is frozen for the life of
                    // the booking.
                    return $this->persist($candidate, $data, Pricing::breakdown(
                        (float) $candidate->price, $nights, (bool) $candidate->mamsa_owned,
                    ), $idempotencyKey);
                }

                // Two distinct outcomes, and the client is told which by a
                // CODE rather than by which Arabic sentence came back.
                $meta = [
                    'available_count' => 0,
                    'requested' => 1,
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                ];

                throw $booked || ! $blocked
                    ? UnitUnavailable::taken($meta)
                    : UnitUnavailable::blocked($meta);
            });
        } catch (UnitUnavailable $e) {
            // 409, not 422: the request was well formed and would have been
            // accepted a moment earlier. 422 says "you sent something wrong".
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->reason,
                'meta' => $e->meta,
            ], 409);
        } catch (QueryException $e) {
            // Someone else's key. The lookup above is scoped to the caller, so
            // a key already used by another guest falls through to the unique
            // index — which is the right place for it to stop, but a 500 is the
            // wrong way to say so.
            if ($this->isDuplicateKey($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'مفتاح التكرار مستخدم بالفعل',
                    'code' => 'IDEMPOTENCY_KEY_REUSED',
                ], 409);
            }

            // Waiting on the unit row longer than innodb_lock_wait_timeout is
            // "try again", NOT "there is nothing left". Returning 409 here would
            // tell a guest the unit was full during a busy moment when it was
            // not, and they would stop trying.
            if (! $this->isLockTimeout($e)) {
                throw $e;
            }

            return response()->json([
                'success' => false,
                'message' => 'الخدمة مشغولة، حاول مرة أخرى',
                'code' => 'INVENTORY_LOCK_TIMEOUT',
            ], 503, ['Retry-After' => '2']);
        }

        return response()->json(new BookingResource($booking->load(['unit.images', 'user'])), 201);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $pricing
     */
    /**
     * MySQL 1205 is the lock wait timeout; 1213 is a deadlock victim. Both mean
     * the same thing to a caller — nothing was written, come back — and neither
     * is a shortage of inventory.
     */
    private function isLockTimeout(QueryException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
    }

    /**
     * A unique-index collision — 1062 on MySQL and MariaDB, 19 on sqlite, which
     * the test suite runs on. Matched on the column name as well, so an
     * unrelated unique violation is not reported as a reused idempotency key.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1062, 19], true)
            && str_contains($e->getMessage(), 'idempotency_key');
    }

    private function persist(Unit $unit, array $data, array $pricing, ?string $idempotencyKey = null): Booking
    {
        return Booking::create([
            'unit_id' => $unit->id,
            'user_id' => auth()->id(),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'guests' => $data['guests'],
            'children' => $data['children'] ?? 0,
            'nightly_rate' => $pricing['nightly_rate'],
            'subtotal' => $pricing['subtotal'],
            // Fees abolished 2026-07-18 — stored as explicit 0 (not null) so
            // the columns stay uniform next to the fee-era historical rows.
            'service_fee' => 0,
            'service_fee_percent' => 0,
            'cleaning_fee' => 0,
            'taxes' => $pricing['taxes'],
            'tax_percent' => $pricing['tax_percent'],
            'commission_rate' => $pricing['commission_rate'],
            'commission_amount' => $pricing['commission_amount'],
            // Frozen payout basis (§1.8) — a later rate change must never alter
            // what a partner is owed for a stay already taken.
            'partner_share' => $pricing['partner_share'],
            'total_amount' => $pricing['total'],
            'status' => Booking::STATUS_PENDING, // explicit so the in-memory model matches the DB default
            'units_count' => 1,
            // The nights are held from THIS moment, not from when the payment
            // page opens: the row already blocks them, so the clock has to start
            // where the blocking does. Config, because the right number is a
            // question about real checkout durations, not about code.
            'hold_expires_at' => now()->addMinutes((int) config('booking.hold_minutes', 60)),
            'idempotency_key' => $idempotencyKey,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
