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
     * How many apartments of this listing are free for the range.
     *
     * 1 or 0 for a standalone unit, so a caller can treat every listing the
     * same way. For a building it is the number still bookable — asking
     * isTaken() about the one unit the card happened to show reported the whole
     * building full the moment a single apartment was taken.
     */
    public static function freeCount(Unit $unit, string $start, string $end): int
    {
        if (! $unit->unit_group_id) {
            return self::isTaken($unit, $start, $end) ? 0 : 1;
        }

        $siblings = Unit::where('unit_group_id', $unit->unit_group_id)
            ->where('approval_status', 'approved')
            ->where('status', 'available');

        self::onlyFree($siblings, $start, $end);

        return $siblings->count();
    }

    /**
     * Stamp `available_count` on each unit — how many apartments of its
     * building are bookable, over the window given (or simply listed, if none).
     *
     * One query for the whole page rather than one per card. Lives here rather
     * than on the listing controller because the favourites page needs the
     * identical number, and two implementations of "how many are free" is how
     * two screens end up disagreeing.
     *
     * @param  \Illuminate\Support\Collection<int, Unit>  $units
     */
    public static function attachCounts($units, ?string $start = null, ?string $end = null): void
    {
        $groups = $units->pluck('unit_group_id')->filter()->unique()->values();
        $counts = collect();

        if ($groups->isNotEmpty()) {
            $siblings = Unit::query()
                ->whereIn('unit_group_id', $groups)
                ->where('approval_status', 'approved')
                ->where('status', 'available');

            if ($start && $end) {
                self::onlyFree($siblings, $start, $end);
            }

            $counts = $siblings
                ->select('unit_group_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as aggregate'))
                ->groupBy('unit_group_id')
                ->pluck('aggregate', 'unit_group_id');
        }

        foreach ($units as $unit) {
            // A standalone unit is a building of one. Reporting null would make
            // every existing listing look like it had no availability.
            $unit->setAttribute(
                'available_count',
                $unit->unit_group_id ? (int) ($counts[$unit->unit_group_id] ?? 0) : 1,
            );
        }
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
     * For a MULTI-UNIT BUILDING the question is different: a night is closed
     * only when EVERY apartment is taken. Answering per-unit greyed out the
     * whole building the moment one of five apartments was booked — the picker
     * refusing dates the booking endpoint would happily have accepted, which is
     * the exact disagreement this method exists to prevent.
     *
     * @return list<array{start: string, end: string}>
     */
    public static function blockedRanges(Unit $unit, string $from, string $to): array
    {
        $units = $unit->unit_group_id
            ? Unit::where('unit_group_id', $unit->unit_group_id)
                ->where('approval_status', 'approved')
                ->where('status', 'available')
                ->get()
            : collect([$unit]);

        // A building whose apartments are all unlisted still has to answer for
        // the unit that was asked about, rather than reporting nothing closed.
        if ($units->isEmpty()) {
            $units = collect([$unit]);
        }

        $total = $units->count();
        $tally = [];

        foreach ($units as $member) {
            // Counted ONCE per apartment per night. A unit carrying both a
            // booking and a manual closure on the same night would otherwise
            // count twice and reach `total` while other apartments sat free.
            foreach (self::occupiedNights($member, $from, $to) as $night) {
                $tally[$night] = ($tally[$night] ?? 0) + 1;
            }
        }

        $full = array_keys(array_filter($tally, fn (int $n) => $n >= $total));
        sort($full);

        return self::spansFrom($full);
    }

    /**
     * The distinct nights one unit is occupied, clipped to the window.
     *
     * @return list<string>
     */
    private static function occupiedNights(Unit $unit, string $from, string $to): array
    {
        $spans = [];

        foreach (self::conflictingBookings((int) $unit->id, $from, $to)->get(['start_date', 'end_date']) as $booking) {
            $spans[] = self::nights($booking->start_date, $booking->end_date);
        }

        foreach ($unit->blockedDates()->overlapping($from, $to)->get(['start_date', 'end_date']) as $block) {
            $spans[] = self::nights($block->start_date, $block->end_date);
        }

        $nights = [];

        foreach (self::merge(array_filter($spans), $from, $to) as $span) {
            for ($d = $span['start']; $d <= $span['end']; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
                $nights[$d] = true;
            }
        }

        return array_keys($nights);
    }

    /**
     * Consecutive nights fused into the fewest inclusive spans.
     *
     * @param  list<string>  $nights  sorted, distinct
     * @return list<array{start: string, end: string}>
     */
    private static function spansFrom(array $nights): array
    {
        $spans = [];

        foreach ($nights as $night) {
            $last = count($spans) - 1;

            if ($last >= 0 && $night === date('Y-m-d', strtotime($spans[$last]['end'].' +1 day'))) {
                $spans[$last]['end'] = $night;

                continue;
            }

            $spans[] = ['start' => $night, 'end' => $night];
        }

        return $spans;
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
