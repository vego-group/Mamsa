<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\IcalService;
use Illuminate\Http\Response;

/**
 * Public iCal export — external platforms (Booking, Airbnb…) poll this URL to
 * block our bookings on their side. No auth by design: the 60-char random
 * `calendar_token` is the credential, exactly like the export links those
 * platforms hand out themselves.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly IcalService $ical) {}

    public function export(string $token): Response
    {
        $unit = Unit::where('calendar_token', $token)->firstOrFail();

        return response($this->ical->export($unit), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="mamsa-unit-'.$unit->id.'.ics"',
            'Cache-Control'       => 'public, max-age=300', // platforms poll — let them cache 5 min
        ]);
    }
}
