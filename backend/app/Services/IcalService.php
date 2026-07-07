<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Unit;
use App\Models\UnitBlockedDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * iCal (.ics) build + parse for two-way availability sync (anti double-booking).
 *
 * Export: our confirmed/pending bookings + the partner's manual closures, as
 * all-day VEVENTs — external platforms (Booking, Airbnb…) import this feed.
 * Import: a deliberately small, tolerant parser for the availability subset of
 * RFC 5545 (VEVENT / DTSTART / DTEND / UID / SUMMARY) — platform feeds use
 * exactly that subset, so a full library adds surface without value here.
 */
class IcalService
{
    /** Build the .ics feed for a unit. */
    public function export(Unit $unit): string
    {
        $unit->loadMissing([
            'bookings' => fn ($q) => $q->whereIn('status', ['pending', 'confirmed'])
                ->where('end_date', '>=', now()->subMonths(1)),
            'blockedDates' => fn ($q) => $q->where('source', UnitBlockedDate::SOURCE_MANUAL)
                ->where('end_date', '>=', now()->subMonths(1)),
        ]);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Mamsa//Availability Calendar//AR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape('Mamsa — '.$unit->unit_name),
        ];

        foreach ($unit->bookings as $booking) {
            $lines = [...$lines, ...$this->event(
                uid: "booking-{$booking->id}@mamsaa.com",
                start: $booking->start_date,
                end: $booking->end_date,
                summary: 'Reserved (Mamsa)',
                stamp: $booking->updated_at,
            )];
        }

        foreach ($unit->blockedDates as $block) {
            $lines = [...$lines, ...$this->event(
                uid: "block-{$block->id}@mamsaa.com",
                start: $block->start_date,
                end: $block->end_date,
                summary: $block->note ?: 'Not available',
                stamp: $block->updated_at,
            )];
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545 requires CRLF line endings.
        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Fetch and parse an external .ics feed into date ranges.
     *
     * @return array<int, array{uid: string, start: string, end: string, summary: string}>
     */
    public function fetchAndParse(string $url): array
    {
        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'Mamsa-Calendar-Sync/1.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("iCal feed returned HTTP {$response->status()}");
        }

        return $this->parse($response->body());
    }

    /**
     * Minimal tolerant RFC 5545 parser — VEVENT blocks, date or datetime values.
     *
     * @return array<int, array{uid: string, start: string, end: string, summary: string}>
     */
    public function parse(string $ics): array
    {
        // Unfold continuation lines (a CRLF followed by a space or tab).
        $ics   = preg_replace("/\r?\n[ \t]/", '', $ics) ?? '';
        $lines = preg_split("/\r?\n/", $ics) ?: [];

        $events  = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === 'BEGIN:VEVENT') {
                $current = ['uid' => '', 'start' => null, 'end' => null, 'summary' => ''];
                continue;
            }

            if ($line === 'END:VEVENT') {
                if ($current && $current['start']) {
                    // DTEND may be absent for single-day events → next day (exclusive end).
                    $current['end'] ??= CarbonImmutable::parse($current['start'])->addDay()->toDateString();
                    $events[] = $current;
                }
                $current = null;
                continue;
            }

            if ($current === null || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $name = strtoupper(explode(';', $key, 2)[0]); // strip params e.g. DTSTART;VALUE=DATE

            match ($name) {
                'DTSTART' => $current['start'] = $this->toDate($value),
                'DTEND'   => $current['end'] = $this->toDate($value),
                'UID'     => $current['uid'] = mb_substr($value, 0, 255),
                'SUMMARY' => $current['summary'] = mb_substr($this->unescape($value), 0, 255),
                default   => null,
            };
        }

        return array_values(array_filter($events, fn ($e) => $e['start'] && $e['end'] && $e['end'] > $e['start']));
    }

    /** @return list<string> one VEVENT as ics lines */
    private function event(string $uid, mixed $start, mixed $end, string $summary, mixed $stamp): array
    {
        $fmt = fn ($d) => CarbonImmutable::parse($d)->format('Ymd');

        return [
            'BEGIN:VEVENT',
            "UID:{$uid}",
            'DTSTAMP:'.CarbonImmutable::parse($stamp ?? now())->utc()->format('Ymd\THis\Z'),
            // VALUE=DATE → all-day events; DTEND is the checkout day (exclusive).
            "DTSTART;VALUE=DATE:{$fmt($start)}",
            "DTEND;VALUE=DATE:{$fmt($end)}",
            'SUMMARY:'.$this->escape($summary),
            'END:VEVENT',
        ];
    }

    /** "20260710", "20260710T140000Z" or "2026-07-10" → "2026-07-10" (null if malformed). */
    private function toDate(string $value): ?string
    {
        try {
            return CarbonImmutable::parse(trim($value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function escape(string $text): string
    {
        return addcslashes($text, "\\;,\n");
    }

    private function unescape(string $text): string
    {
        return str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], $text);
    }
}
