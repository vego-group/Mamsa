<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingComplaint;
use App\Models\BookingComplaintAttachment;
use App\Models\User;
use App\Notifications\ComplaintReceivedPartner;
use App\Notifications\ComplaintSubmitted;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Guest complaints against a completed stay — complaints/refunds spec §5.1.
 *
 * The guest surface never exposes `internal_note`, which is why every response
 * here is assembled field by field rather than by serialising the model (T13).
 */
class ComplaintController extends Controller
{
    use ApiResponse;

    /** The disk complaint photos live on. Private: these are dispute evidence. */
    private const DISK = 'local';

    /**
     * An error the app can branch on.
     *
     * The shared ApiResponse::error() returns only `message`, in Arabic. A
     * client that needs to tell "outside the window" from "already complained"
     * would have to match on translated prose — which breaks the first time
     * someone improves the wording. These endpoints add a stable `code`
     * alongside it; the message stays exactly as before for anything already
     * rendering it.
     */
    private function refuse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ], $status);
    }

    /** POST /bookings/{booking}/complaint */
    public function store(Request $request, Booking $booking): JsonResponse
    {
        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return $this->refuse('NOT_YOUR_BOOKING', 'غير مصرح', 403);
        }

        if ($booking->status !== Booking::STATUS_COMPLETED) {
            return $this->refuse('BOOKING_NOT_COMPLETED', 'لا يمكن تقديم شكوى إلا على حجز مكتمل', 422);
        }

        if ($window = $this->outsideWindow($booking)) {
            return $this->refuse($window['code'], $window['message'], 422);
        }

        // The unique index is the real guard against two simultaneous
        // submissions; this exists to answer with 409 and an Arabic message
        // rather than a database error.
        if (BookingComplaint::where('booking_id', $booking->id)->exists()) {
            return $this->refuse('COMPLAINT_ALREADY_EXISTS', 'تم تقديم شكوى على هذا الحجز من قبل', 409);
        }

        $data = $request->validate([
            'description'       => ['required', 'string',
                'min:'.config('complaints.description_min'),
                'max:'.config('complaints.description_max')],
            'contacted_partner' => ['required', 'boolean'],
            'images'            => ['sometimes', 'array', 'max:'.BookingComplaintAttachment::MAX_PER_COMPLAINT],
            'images.*'          => ['image', 'max:'.(int) (BookingComplaintAttachment::MAX_BYTES / 1024),
                'mimetypes:'.implode(',', BookingComplaintAttachment::ALLOWED_MIMES)],
        ], [
            'description.required'       => 'وصف الشكوى مطلوب',
            'description.min'            => 'الوصف قصير جداً، اكتب تفاصيل أوضح',
            'description.max'            => 'الوصف طويل جداً',
            'contacted_partner.required' => 'يرجى تحديد ما إذا كنت تواصلت مع الشريك',
            'images.max'                 => 'الحد الأقصى '.BookingComplaintAttachment::MAX_PER_COMPLAINT.' صور',
            'images.*.mimetypes'         => 'الصور المسموحة: JPG أو PNG أو WEBP فقط',
            'images.*.max'               => 'حجم الصورة يتجاوز الحد المسموح (5 ميجابايت)',
        ]);

        $complaint = DB::transaction(function () use ($request, $booking, $data) {
            $complaint = BookingComplaint::create([
                'booking_id'        => $booking->id,
                'user_id'           => $booking->user_id,
                'status'            => BookingComplaint::STATUS_SUBMITTED,
                'description'       => $data['description'],
                'contacted_partner' => (bool) $data['contacted_partner'],
            ]);

            foreach ($request->file('images', []) as $image) {
                $path = $image->store('complaints/'.$complaint->id, self::DISK);

                $complaint->attachments()->create([
                    'path'       => $path,
                    'mime'       => $image->getMimeType(),
                    'size_bytes' => $image->getSize(),
                ]);
            }

            return $complaint;
        });

        // After the commit: production sends synchronously, and a mail failure
        // must not roll back a complaint the guest believes they filed.
        $this->announce($complaint->fresh(['attachments']), $booking);

        return response()->json([
            'id'         => $complaint->id,
            'status'     => $complaint->status,
            'created_at' => $complaint->created_at?->toIso8601String(),
        ], 201);
    }

    /** GET /bookings/{booking}/complaint */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return $this->refuse('NOT_YOUR_BOOKING', 'غير مصرح', 403);
        }

        $complaint = BookingComplaint::with('attachments')
            ->where('booking_id', $booking->id)
            ->first();

        if (! $complaint) {
            return $this->refuse('NO_COMPLAINT', 'لا توجد شكوى على هذا الحجز', 404);
        }

        return $this->success($this->guestPayload($complaint));
    }

    /** GET /me/complaints */
    public function index(Request $request): JsonResponse
    {
        $rows = BookingComplaint::with('booking:id')
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn (BookingComplaint $c) => [
                'id'           => $c->id,
                'status'       => $c->status,
                'booking_id'   => $c->booking_id,
                'booking_code' => $c->booking?->code ?: (string) $c->booking?->id,
                'created_at'   => $c->created_at?->toIso8601String(),
            ]);

        return $this->success($rows);
    }

    /* ---------- helpers ---------- */

    /**
     * The complaint window: from check-in until N hours after check-out, in
     * Riyadh time (R4).
     *
     * Evaluated in Asia/Riyadh explicitly rather than in the app default: the
     * boundary is a promise made to a guest standing in Saudi Arabia, and a
     * server timezone change must not quietly move it by three hours.
     */
    /** @return array{code:string,message:string}|null */
    private function outsideWindow(Booking $booking): ?array
    {
        $tz  = 'Asia/Riyadh';
        $now = Carbon::now($tz);

        // ->format('Y-m-d') is not decoration. `start_date` and `end_date` are
        // cast to `date`, so they arrive as Carbon instances in the app
        // timezone (UTC) — and Carbon::parse() IGNORES its timezone argument
        // when handed a Carbon rather than a string. Passing them directly
        // computed the whole window in UTC, shifting it three hours: a guest
        // could file three hours past the deadline, and not at all during the
        // first three hours of their check-in day.
        //
        // Reducing to a date string first makes the timezone argument apply,
        // which is what R4 asks for — the boundary is a promise made to
        // someone standing in Saudi Arabia.
        // The window closes N hours after the guest ACTUALLY leaves, which is
        // the unit's check-out time — not midnight of the check-out day.
        //
        // Measuring from midnight quietly shortened R4's 48 hours to 36 for a
        // 12:00 check-out, and the shortfall grew with every later time. The
        // platform already records this per unit; not using it was the bug.
        //
        // A unit with no recorded time falls back to 12:00 rather than 00:00.
        // 26 of 32 units carry exactly that, and it is what the documentation
        // states — but the reason is narrower than the average: midnight would
        // make OUR missing data cost the GUEST twelve hours of their deadline.
        $checkOut = $booking->loadMissing('unit')->unit?->checkout_time;

        $checkOut = $checkOut
            ? Carbon::parse($checkOut)->format('H:i')
            : (string) config('complaints.default_checkout_time');

        // Opening stays at the start of the check-in day. A guest whose stay
        // has begun can complain from that morning; tying it to check-in time
        // would only ever narrow the window, and nothing asks for that.
        $opens = Carbon::parse(Carbon::parse($booking->start_date)->format('Y-m-d'), $tz)->startOfDay();

        $shuts = Carbon::parse(
            Carbon::parse($booking->end_date)->format('Y-m-d').' '.$checkOut,
            $tz
        )->addHours((int) config('complaints.window_hours_after_checkout'));

        if ($now->lt($opens)) {
            return ['code' => 'WINDOW_NOT_OPEN', 'message' => 'لا يمكن تقديم شكوى قبل بداية الإقامة'];
        }

        if ($now->gt($shuts)) {
            return ['code' => 'WINDOW_CLOSED', 'message' => 'انتهت مهلة تقديم الشكوى (48 ساعة بعد انتهاء الإقامة)'];
        }

        return null;
    }

    /** Admin queue + the partner whose unit it is (§7). */
    private function announce(BookingComplaint $complaint, Booking $booking): void
    {
        try {
            $admins = User::role('SuperAdmin')->where('is_active', true)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new ComplaintSubmitted($complaint));
            }

            $partner = $booking->loadMissing('unit.owner')->unit?->owner;

            // A Mamsa-owned listing has no partner to warn — its `user_id` is
            // the admin who created it, who is already on the queue above.
            if ($partner && ! $booking->unit?->mamsa_owned) {
                $partner->notify(new ComplaintReceivedPartner($complaint));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, mixed> */
    private function guestPayload(BookingComplaint $complaint): array
    {
        // The settled refund, if the decision went that way. `succeeded` only:
        // a pending row is not yet money the guest has been given, and naming
        // it here would be the promise G2 exists to prevent.
        $refund = $complaint->refunds()
            ->where('status', \App\Models\Refund::STATUS_SUCCEEDED)
            ->latest('id')
            ->first();

        return [
            'id'                => $complaint->id,
            'status'            => $complaint->status,
            'description'       => $complaint->description,
            'contacted_partner' => (bool) $complaint->contacted_partner,
            'guest_message'     => $complaint->guest_message,
            'refunded_amount'   => $refund ? round((float) $refund->amount, 2) : null,
            'created_at'        => $complaint->created_at?->toIso8601String(),
            'images'            => $complaint->attachments->map(fn ($a) => [
                // Signed and short-lived (spec §4.2): a link pasted into a
                // chat stops working on its own. These are photographs of
                // someone's rented home, filed in a dispute — a permanent URL
                // would be guessable for as long as the row exists.
                'url'  => BookingComplaintAttachment::signedUrl($a),
                'mime' => $a->mime,
            ])->all(),
            // internal_note is absent by construction — the model hides it and
            // this array never names it (T13).
        ];
    }
}
