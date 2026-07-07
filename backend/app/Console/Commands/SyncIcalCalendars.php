<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Unit;
use App\Models\UnitBlockedDate;
use App\Services\IcalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pull every unit's external .ics feed (Booking, Airbnb…) and mirror its events
 * as source=ical blocked dates — the import half of the anti-double-booking
 * sync. Scheduled every 15 minutes; one unit's bad feed never stops the rest.
 */
class SyncIcalCalendars extends Command
{
    protected $signature = 'calendar:sync {--unit= : Sync a single unit id}';

    protected $description = 'Import external iCal feeds into unit blocked dates';

    public function handle(IcalService $ical): int
    {
        $units = Unit::whereNotNull('ical_import_url')
            ->when($this->option('unit'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        $ok = $failed = 0;

        foreach ($units as $unit) {
            try {
                $events = $ical->fetchAndParse($unit->ical_import_url);

                // Ignore stale history — availability only matters going forward.
                $events = array_filter($events, fn ($e) => $e['end'] >= now()->subMonth()->toDateString());

                // Mirror the feed atomically: replace all ical-sourced rows.
                DB::transaction(function () use ($unit, $events) {
                    $unit->blockedDates()->where('source', UnitBlockedDate::SOURCE_ICAL)->delete();

                    foreach ($events as $e) {
                        $unit->blockedDates()->create([
                            'start_date'   => $e['start'],
                            'end_date'     => $e['end'],
                            'source'       => UnitBlockedDate::SOURCE_ICAL,
                            'note'         => $e['summary'] ?: 'حجز خارجي',
                            'external_uid' => $e['uid'] ?: null,
                        ]);
                    }

                    $unit->forceFill(['ical_synced_at' => now()])->save();
                });

                $this->info("unit {$unit->id}: ".count($events).' events');
                $ok++;
            } catch (\Throwable $e) {
                // Keep the last good snapshot — a temporarily broken feed must
                // not reopen dates that were blocked on the previous sync.
                Log::warning('iCal sync failed', ['unit_id' => $unit->id, 'error' => $e->getMessage()]);
                $this->warn("unit {$unit->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("synced {$ok}, failed {$failed}");

        return $failed > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
