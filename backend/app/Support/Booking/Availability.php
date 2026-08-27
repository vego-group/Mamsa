<?php

declare(strict_types=1);

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

/**
 * One definition of "these dates are taken".
 *
 * It was written out three times — the availability probe, the booking create,
 * and now the calendar feed the storefront needs. Three copies of an overlap
 * predicate is three chances for the probe to say yes and the create to say no,
 * which a guest experiences as the site losing their booking at the last step.
 *
 * A unit is unavailable for two independent reasons, and both count:
 *  - a live booking (`pending_payment` or `confirmed`) — a cancelled or
 *    completed one releases the dates;
 *  - a blocked range — a partner's manual closure or an imported iCal event.
 */
final class Availability
{
    /**
     * Statuses that hold dates. `pending_payment` is included deliberately: a
     * guest partway through checkout has a claim on the nights, or two people
     * would pay for the same room.
     */
    public const BLOCKING_STATUSES = [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED];

    /**
     * Bookings on this unit that collide with the range.
     *
     * A stay occupies the NIGHTS from `start` up to but not including `end` —
     * the guest checks out on `end` and the room is free that evening. So two
     * stays collide when `existing.start < new.end AND existing.end > new.start`,
     * and a changeover day belongs to the arriving guest. The units already
     * carry separate check-out and check-in times, which is the same model.
     *
     * The predicate this replaced was three `whereBetween`/`orWhere` clauses
     * with inclusive bounds, and it answered the same physical question two
     * different ways: a stay STARTING on an existing stay's end date was
     * refused, while one ENDING on its start date was allowed. The cause was
     * invisible — `start_date` is cast to `date` but stored as
     * `2026-09-10 00:00:00`, so a bare-date upper bound excluded the row that
     * matched it. Comparing on `<`/`>` removes the boundary ambiguity entirely
     * rather than relying on how a driver compares a datetime to a date.
     */
    public static function conflictingBookings(int $unitId, string $start, string $end): Builder
    {
        return Booking::query()
            ->where('unit_id', $unitId)
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->whereDate('start_date', '<', $end)
            ->whereDate('end_date', '>', $start);
    }

    /**
     * Narrow a UNIT query to those free for the whole range.
     *
     * Used by the search listing, so a result set can honestly be labelled
     * "available for your stay". Reuses the same predicate the probe and the
     * create enforce — a listing that advertised availability on its own rules
     * would be the same lie in a wider place.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Unit>  $units
     */
    public static function onlyFree(Builder $units, string $start, string $end): void
    {
        $units->whereDoesntHave('bookings', fn (Builder $q) => $q
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->whereDate('start_date', '<', $end)
            ->whereDate('end_date', '>', $start));

        $units->whereDoesntHave('blockedDates', fn (Builder $q) => $q->overlapping($start, $end));
    }

    /** Is anything — a booking or a block — holding this range? */
    public static function isTaken(Unit $unit, string $start, string $end): bool
    {
        return self::conflictingBookings((int) $unit->id, $start, $end)->exists()
            || $unit->blockedDates()->overlapping($start, $end)->exists();
    }

    /**
     * Every occupied range in the window, merged into the fewest spans.
     *
     * Both ends are INCLUSIVE and describe NIGHTS, which is what a calendar
     * greys out. A stay of 10→15 occupies the nights of the 10th to the 14th
     * and returns `{10, 14}`: the 15th is free from that morning and must stay
     * selectable, or the picker would refuse a date the booking endpoint
     * accepts — the same disagreement in the other direction.
     *
     * Bookings and closures are deliberately NOT distinguished: a guest only
     * needs to know a date cannot be chosen, and labelling a span "booked"
     * would publish how busy a partner's unit is to anyone who asks.
     *
     * @return list<array{start: string, end: string}>
     */
    public static function blockedRanges(Unit $unit, string $from, string $to): array
    {
        $spans = [];

        foreach (self::conflictingBookings((int) $unit->id, $from, $to)->get(['start_date', 'end_date']) as $booking) {
            $spans[] = self::nights($booking->start_date, $booking->end_date);
        }

        foreach ($unit->blockedDates()->overlapping($from, $to)->get(['start_date', 'end_date']) as $block) {
            $spans[] = self::nights($block->start_date, $block->end_date);
        }

        return self::merge(array_filter($spans), $from, $to);
    }

    /**
     * The nights a half-open range occupies, as an inclusive pair. Null when it
     * occupies none — a same-day row holds no night.
     *
     * @return array{start: string, end: string}|null
     */
    private static function nights(mixed $start, mixed $end): ?array
    {
        $first = self::day($start);
        $last  = date('Y-m-d', strtotime(self::day($end).' -1 day'));

        return $last < $first ? null : ['start' => $first, 'end' => $last];
    }

    /**
     * Clip to the window, sort, and fuse anything touching or overlapping, so
     * a calendar can grey out each span without stitching them itself.
     *
     * Adjacent spans are fused too — a booking ending on the 10th and another
     * starting on the 10th is one unbroken closure to anyone looking at a
     * calendar, and returning it as two would draw a gap that is not selectable.
     *
     * @param  list<array{start: string, end: string}>  $spans
     * @return list<array{start: string, end: string}>
     */
    private static function merge(array $spans, string $from, string $to): array
    {
        $clipped = [];

        foreach ($spans as $span) {
            $start = max($span['start'], $from);
            $end   = min($span['end'], $to);

            if ($start <= $end) {
                $clipped[] = ['start' => $start, 'end' => $end];
            }
        }

        usort($clipped, fn ($a, $b) => $a['start'] <=> $b['start']);

        $merged = [];

        foreach ($clipped as $span) {
            $last = count($merged) - 1;

            // `<=` the day AFTER, so consecutive spans fuse: nights 10–14
            // followed by 15–19 is one unbroken closure, and returning it as
            // two would draw a gap on the 15th that cannot actually be booked.
            $touches = $last >= 0
                && $span['start'] <= date('Y-m-d', strtotime($merged[$last]['end'].' +1 day'));

            if ($touches) {
                $merged[$last]['end'] = max($merged[$last]['end'], $span['end']);

                continue;
            }

            $merged[] = $span;
        }

        return $merged;
    }

    private static function day(mixed $date): string
    {
        return $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : substr((string) $date, 0, 10);
    }
}
