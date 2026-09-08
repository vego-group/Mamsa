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
    public function __invoke(string $attachment): StreamedResponse
    {
        // Looked up by hand, not type-hinted as a model.
        //
        // This route lives in routes/dashboard.php, where every route takes a
        // `string $id` and resolves it itself; this one follows that convention.
        // It was originally written with a `BookingComplaintAttachment` type-hint
        // and returned a 500 on every valid signed link, because the group had no
        // SubstituteBindings: a model type-hint does not 404 on a miss, the
        // container just constructs an EMPTY model, and the null `path` died as a
        // TypeError inside Flysystem. The middleware is registered now, so the
        // type-hint would work — the manual lookup stays for consistency with the
        // rest of the file, not because binding is unavailable.
        $record = BookingComplaintAttachment::find($attachment);

        abort_if($record === null, 404);

        $disk = Storage::disk('local');

        abort_unless((string) $record->path !== '' && $disk->exists($record->path), 404);

        // Inline, and with the stored mime rather than a guessed one: the
        // upload was validated against an allow-list of three image types, so
        // echoing that back cannot be turned into an HTML or SVG payload
        // rendered on our own origin.
        return $disk->response($record->path, null, [
            'Content-Type'        => $record->mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
