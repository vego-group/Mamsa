<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UnitBlockedDate;
use App\Models\UnitIcalFeed;
use App\Notifications\IcalSyncFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Syncs a single named iCal feed (contract §5.4) into source=ical blocked
 * dates attributed to that feed. Per-feed isolation means one broken feed
 * keeps its last-good rows and only flips ITS status to "error" — the other
 * feeds on the unit are untouched.
 */
class IcalFeedSyncService
{
    public function __construct(private readonly IcalService $ical) {}

    /** @return bool  true on success, false if the fetch/parse failed. */
    public function sync(UnitIcalFeed $feed): bool
    {
        try {
            $events = $this->ical->fetchAndParse($feed->url);

            // Availability only matters forward — drop stale history.
            $events = array_filter(
                $events,
                fn ($e) => $e['end'] >= now()->subMonth()->toDateString(),
            );

            DB::transaction(function () use ($feed, $events) {
                $feed->blockedDates()->delete();

                foreach ($events as $e) {
                    $feed->unit->blockedDates()->create([
                        'start_date'   => $e['start'],
                        'end_date'     => $e['end'],
                        'source'       => UnitBlockedDate::SOURCE_ICAL,
                        'ical_feed_id' => $feed->id,
                        'note'         => $e['summary'] ?: $feed->source,
                        'external_uid' => $e['uid'] ?: null,
                    ]);
                }

                $feed->update([
                    'status'         => UnitIcalFeed::STATUS_SYNCED,
                    'error'          => null,
                    'last_synced_at' => now(),
                ]);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('iCal feed sync failed', ['feed_id' => $feed->id, 'error' => $e->getMessage()]);

            $wasError = $feed->status === UnitIcalFeed::STATUS_ERROR;
            $feed->update([
                'status' => UnitIcalFeed::STATUS_ERROR,
                'error'  => \Illuminate\Support\Str::limit($e->getMessage(), 480),
            ]);

            // Notify the partner once per error transition, not every 15 min.
            if (! $wasError) {
                $this->notifyFailure($feed);
            }

            return false;
        }
    }

    private function notifyFailure(UnitIcalFeed $feed): void
    {
        try {
            $feed->unit->owner?->notify(new IcalSyncFailed($feed->loadMissing('unit')));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
