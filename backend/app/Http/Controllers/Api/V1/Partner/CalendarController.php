<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitBlockedDate;
use App\Services\IcalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Partner availability calendar (anti double-booking v1):
 * manual closures + two-way iCal sync settings for one unit.
 */
class CalendarController extends Controller
{
    /** GET /partner/units/{unit}/calendar — settings + blocks + booked ranges. */
    public function show(Request $request, Unit $unit): JsonResponse
    {
        $this->authorizeOwnership($request, $unit);

        // Lazily mint a token for units created before calendar_token existed.
        if (blank($unit->calendar_token)) {
            $unit->forceFill(['calendar_token' => Str::random(60)])->save();
        }

        return response()->json([
            'export_url'      => route('api.calendar.export', ['token' => $unit->calendar_token]),
            'ical_import_url' => $unit->ical_import_url,
            'ical_synced_at'  => $unit->ical_synced_at?->toIso8601String(),
            'blocked_dates'   => $unit->blockedDates()
                ->where('end_date', '>=', now()->toDateString())
                ->orderBy('start_date')
                ->get(['id', 'start_date', 'end_date', 'source', 'note']),
            // Read-only context so the calendar UI can paint booked days too.
            'booked'          => $unit->bookings()
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('end_date', '>=', now()->toDateString())
                ->orderBy('start_date')
                ->get(['id', 'start_date', 'end_date', 'status']),
        ]);
    }

    /** PUT /partner/units/{unit}/calendar — save the external feed URL (empty = disable). */
    public function update(Request $request, Unit $unit, IcalService $ical): JsonResponse
    {
        $this->authorizeOwnership($request, $unit);

        $data = $request->validate([
            'ical_import_url' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
        ]);

        $url = $data['ical_import_url'] ?? null;

        // Validate the feed before saving — a wrong link silently syncing
        // nothing is worse than an upfront error.
        if ($url) {
            try {
                $ical->fetchAndParse($url);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'تعذّر قراءة رابط التقويم — تأكد أنه رابط iCal (.ics) صالح'], 422);
            }
        }

        $unit->forceFill(['ical_import_url' => $url])->save();

        if ($url) {
            // First sync immediately — the partner shouldn't wait 15 minutes.
            \Artisan::call('calendar:sync', ['--unit' => $unit->id]);
        } else {
            $unit->blockedDates()->where('source', UnitBlockedDate::SOURCE_ICAL)->delete();
            $unit->forceFill(['ical_synced_at' => null])->save();
        }

        return $this->show($request, $unit->fresh());
    }

    /** POST /partner/units/{unit}/blocked-dates — manual closure (صيانة / حجز خارجي…). */
    public function storeBlock(Request $request, Unit $unit): JsonResponse
    {
        $this->authorizeOwnership($request, $unit);

        $data = $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date'   => ['required', 'date', 'after:start_date'],
            'note'       => ['nullable', 'string', 'max:255'],
        ]);

        $block = $unit->blockedDates()->create([
            ...$data,
            'source' => UnitBlockedDate::SOURCE_MANUAL,
        ]);

        return response()->json($block->only(['id', 'start_date', 'end_date', 'source', 'note']), 201);
    }

    /** DELETE /partner/units/{unit}/blocked-dates/{block} — manual blocks only. */
    public function destroyBlock(Request $request, Unit $unit, UnitBlockedDate $block): Response|JsonResponse
    {
        $this->authorizeOwnership($request, $unit);

        abort_if($block->unit_id !== $unit->id, 404);

        // iCal rows mirror the external feed — deleting one here would just
        // resurrect it on the next sync. Remove it at the source platform.
        if ($block->source !== UnitBlockedDate::SOURCE_MANUAL) {
            return response()->json(['message' => 'هذا الإغلاق مستورد من تقويم خارجي — احذفه من المنصة الأخرى'], 422);
        }

        $block->delete();

        return response()->noContent();
    }

    private function authorizeOwnership(Request $request, Unit $unit): void
    {
        abort_if($unit->user_id !== $request->user()->id, 403, 'غير مصرح');
    }
}
