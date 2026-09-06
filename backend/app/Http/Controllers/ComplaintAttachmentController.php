<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BookingComplaintAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a complaint photo from the private disk.
 *
 * Guard is the URL signature, not a session: the same link has to work from the
 * guest app (Bearer), the admin console (admin-panel cookie) and the partner
 * dashboard (dashboard cookie), which are three different guards on two
 * different hosts. A signed, short-lived URL is the one credential all three
 * can hold, and it expires on its own — unlike a session, which does not stop
 * a link from being forwarded.
 */
class ComplaintAttachmentController extends Controller
{
    public function __invoke(BookingComplaintAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk('local');

        abort_unless($disk->exists($attachment->path), 404);

        // Inline, and with the stored mime rather than a guessed one: the
        // upload was validated against an allow-list of three image types, so
        // echoing that back cannot be turned into an HTML or SVG payload
        // rendered on our own origin.
        return $disk->response($attachment->path, null, [
            'Content-Type'        => $attachment->mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
