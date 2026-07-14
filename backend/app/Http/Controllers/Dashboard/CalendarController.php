<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Unit;
use App\Models\UnitBlockedDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Availability calendar (contract §5.1–5.3): a per-day month grid plus manual
 * block/unblock. Day priority when overlapping: booked > external > blocked >
 * available. All ranges use the checkout-exclusive convention (end = DTEND).
 */
class CalendarController extends DashboardController
{
    public function month(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->fail('VALIDATION', 'بيانات غير صالحة', 400, ['month' => 'صيغة الشهر يجب أن تكون YYYY-MM']);
        }

        $first = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $last  = $first->endOfMonth();

        $bookings = $unit->bookings()
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('start_date', '<=', $last->toDateString())
            ->where('end_date', '>', $first->toDateString())
            ->get(['id', 'start_date', 'end_date', 'status']);

        $blocks = $unit->blockedDates()
            ->with('icalFeed:id,source')
            ->where('start_date', '<=', $last->toDateString())
            ->where('end_date', '>', $first->toDateString())
            ->get();

        $days = [];
        for ($d = $first; $d->lte($last); $d = $d->addDay()) {
            $iso = $d->toDateString();
            $days[] = ['date' => $iso] + $this->dayStatus($iso, $bookings, $blocks);
        }

        return response()->json($days);
    }

    public function block(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        $data = $this->validated($request, [
            'dates'   => ['required', 'array', 'min:1'],
            'dates.*' => ['date_format:Y-m-d'],
            'reason'  => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $dates  = collect($data['dates'])->unique()->sort()->values();
        $reason = isset($data['reason']) ? strip_tags((string) $data['reason']) : null;

        // §5.2 — reject any day already booked or externally-blocked.
        foreach ($dates as $date) {
            if ($this->isBookedOrExternal($unit, $date)) {
                $this->fail('DATE_UNAVAILABLE', "التاريخ {$date} محجوز أو مستورد من تقويم خارجي", 409);
            }
        }

        // Store each requested day as a one-night [date, date+1) manual block,
        // skipping days already manually closed (idempotent).
        foreach ($dates as $date) {
            $next = CarbonImmutable::parse($date)->addDay()->toDateString();

            $exists = $unit->blockedDates()
                ->where('source', UnitBlockedDate::SOURCE_MANUAL)
                ->where('start_date', $date)->where('end_date', $next)->exists();

            if (! $exists) {
                $unit->blockedDates()->create([
                    'start_date' => $date,
                    'end_date'   => $next,
                    'source'     => UnitBlockedDate::SOURCE_MANUAL,
                    'note'       => $reason,
                ]);
            }
        }

        return $this->ok();
    }

    public function unblock(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        $data = $this->validated($request, [
            'dates'   => ['required', 'array', 'min:1'],
            'dates.*' => ['date_format:Y-m-d'],
        ]);

        // §5.3 — only manually-blocked days can be unblocked.
        foreach (collect($data['dates'])->unique() as $date) {
            $unit->blockedDates()
                ->where('source', UnitBlockedDate::SOURCE_MANUAL)
                ->where('start_date', '<=', $date)
                ->where('end_date', '>', $date)
                ->delete();
        }

        return $this->ok();
    }

    /* ---- day resolution ---- */

    private function dayStatus(string $iso, $bookings, $blocks): array
    {
        if ($b = $bookings->first(fn ($bk) => $bk->start_date->toDateString() <= $iso && $iso < $bk->end_date->toDateString())) {
            return ['status' => 'booked', 'bookingCode' => 'BK-'.$b->id, 'bookingId' => 'b_'.$b->id];
        }

        $ical = $blocks->first(fn ($bl) => $bl->source === UnitBlockedDate::SOURCE_ICAL
            && $bl->start_date->toDateString() <= $iso && $iso < $bl->end_date->toDateString());
        if ($ical) {
            return ['status' => 'external', 'source' => $ical->icalFeed?->source ?? ($ical->note ?: 'خارجي')];
        }

        $manual = $blocks->first(fn ($bl) => $bl->source === UnitBlockedDate::SOURCE_MANUAL
            && $bl->start_date->toDateString() <= $iso && $iso < $bl->end_date->toDateString());
        if ($manual) {
            return ['status' => 'blocked', 'reason' => $manual->note];
        }

        return ['status' => 'available'];
    }

    private function isBookedOrExternal(Unit $unit, string $date): bool
    {
        $next = CarbonImmutable::parse($date)->addDay()->toDateString();

        $booked = $unit->bookings()
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('start_date', '<', $next)->where('end_date', '>', $date)->exists();

        $external = $unit->blockedDates()
            ->where('source', UnitBlockedDate::SOURCE_ICAL)
            ->where('start_date', '<', $next)->where('end_date', '>', $date)->exists();

        return $booked || $external;
    }

    private static function rawId(string $id): string
    {
        return Str::startsWith($id, 'u_') ? Str::after($id, 'u_') : $id;
    }
}
