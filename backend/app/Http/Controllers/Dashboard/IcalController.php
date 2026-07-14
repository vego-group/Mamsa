<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Unit;
use App\Models\UnitIcalFeed;
use App\Services\IcalFeedSyncService;
use App\Services\IcalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * iCal import feeds + export (contract §5.4/5.5). Multiple named feeds per
 * unit; the background command calendar:sync also refreshes them every 15 min.
 */
class IcalController extends DashboardController
{
    public function index(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        return response()->json(
            $unit->icalFeeds()->latest()->get()->map(fn (UnitIcalFeed $f) => self::feed($f))->values(),
        );
    }

    public function store(Request $request, string $id, IcalService $ical, IcalFeedSyncService $sync): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        $data = $this->validated($request, [
            'source' => ['required', 'string', 'max:50'],
            'url'    => ['required', 'url', 'starts_with:https://,http://', 'max:2048'],
        ]);

        // §5.4 — validate the URL actually fetches valid iCal before saving.
        try {
            $ical->fetchAndParse($data['url']);
        } catch (\Throwable $e) {
            $this->fail('INVALID_ICAL', 'تعذّر قراءة الرابط — تأكد أنه رابط iCal (.ics) صالح', 400);
        }

        $feed = $unit->icalFeeds()->create([
            'source' => strip_tags($data['source']),
            'url'    => $data['url'],
            'status' => UnitIcalFeed::STATUS_PENDING,
        ]);

        // First sync immediately — the partner shouldn't wait for the cron.
        $sync->sync($feed);

        return $this->ok(self::feed($feed->fresh()), 201);
    }

    public function destroy(Request $request, string $id, string $feedId): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));
        $feed = $this->ownFeed($unit, $feedId);

        // Its imported blocked rows cascade to null then are cleaned up here.
        $feed->blockedDates()->delete();
        $feed->delete();

        return $this->ok();
    }

    public function sync(Request $request, string $id, string $feedId, IcalFeedSyncService $sync): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));
        $feed = $this->ownFeed($unit, $feedId);

        $sync->sync($feed);

        return $this->ok(self::feed($feed->fresh()));
    }

    public function export(Request $request, string $id): JsonResponse
    {
        $unit = $this->ownUnit($request, self::rawId($id));

        if (blank($unit->calendar_token)) {
            $unit->forceFill(['calendar_token' => Str::random(60)])->save();
        }

        return $this->ok([
            'url' => route('api.calendar.export', ['token' => $unit->calendar_token]),
        ]);
    }

    /* ---- helpers ---- */

    private function ownFeed(Unit $unit, string $feedId): UnitIcalFeed
    {
        $feed = $unit->icalFeeds()->whereKey($feedId)->first();

        if (! $feed) {
            $this->fail('FEED_NOT_FOUND', 'التقويم غير موجود', 404);
        }

        return $feed;
    }

    private static function feed(UnitIcalFeed $f): array
    {
        return [
            'id'       => (string) $f->id,
            'source'   => $f->source,
            'url'      => $f->url,
            'status'   => $f->status === UnitIcalFeed::STATUS_PENDING ? 'synced' : $f->status,
            'lastSync' => $f->last_synced_at?->toIso8601ZuluString(),
        ];
    }

    private static function rawId(string $id): string
    {
        return Str::startsWith($id, 'u_') ? Str::after($id, 'u_') : $id;
    }
}
