<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DashboardUpload;
use App\Support\AdminPermissions;
use App\Support\Documents\DocumentStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Compliance documents — tourism permits, commercial registrations, national
 * IDs — served to the two people entitled to see them.
 *
 * These live on the public disk today, which this host serves as static files
 * through a symlink: no signature, no expiry, no authorisation. A ULID filename
 * is the only thing between a national ID and anyone who has ever held its URL,
 * and a URL leaks through browser history, Referer and proxy caches without
 * ever expiring. Meanwhile a complaint PHOTO is signed and lives fifteen
 * minutes. The protections are inverted.
 *
 * WHY A SIGNATURE IS NOT ENOUGH. A signature proves the link came from us; it
 * says nothing about who is holding it. The attachment route can stop at a
 * signature because it is read from three surfaces with three different guards
 * and a short-lived signature is the only credential all three can carry. These
 * documents are read by exactly two people — the reviewer and the owner — and
 * both have sessions, so identity is available and therefore required.
 *
 * READS BOTH DISKS, on purpose. This ships BEFORE the files move (the sequence
 * is: route first, then flip writes and reads together in one deploy, then
 * migrate the old files). Serving only the vault would 404 every existing
 * document; serving only the public disk would 404 every new one. When the
 * migration is finished the public branch stops matching and comes out.
 */
class DocumentController extends Controller
{
    public function __invoke(Request $request, string $upload): StreamedResponse
    {
        // Looked up by hand rather than type-hinted: the id is a ULID string,
        // and this route's group resolves bindings but the rest of the file
        // follows the same convention.
        $record = DashboardUpload::find($upload);

        abort_if($record === null, 404);
        abort_unless($this->mayView($request, $record), 403, 'ليس لديك صلاحية لعرض هذا المستند');

        [$disk, $path] = $this->locate($record);

        abort_if($disk === null, 404);

        return $disk->response($path, null, [
            'Content-Type' => $record->mime ?: 'application/octet-stream',
            // Inline so the review screen can frame it. No X-Frame-Options and
            // no frame-ancestors: putting the document beside the numbers it is
            // checked against is the whole design of that screen, and sending
            // the reviewer to another tab turns the check into a click.
            'Content-Disposition' => 'inline',
            // The signature is in the query string, so the URL is a credential.
            // Without this it travels in the Referer of anything the framing
            // page loads next.
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            // Never store a credentialed document in a shared cache.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The reviewer, or the partner whose document it is. Nobody else.
     *
     * Both guards are checked because the same link is opened from two
     * surfaces with two different sessions, and Laravel's session guards coexist
     * in one session — so which one is populated depends on who is looking,
     * not on how the route was reached.
     */
    private function mayView(Request $request, DashboardUpload $record): bool
    {
        if ($admin = Auth::guard('admin-panel')->user()) {
            $role = $admin->hasRole('finance') ? 'finance' : 'superadmin';

            return in_array('approvals.view', AdminPermissions::for($role), true);
        }

        $partner = Auth::guard('dashboard')->user();

        return $partner !== null && (int) $partner->id === (int) $record->user_id;
    }

    /**
     * Where the bytes actually are. Delegated so the reader and the writers
     * cannot disagree about which disk a document is on.
     *
     * @return array{0: Filesystem|null, 1: string}
     */
    private function locate(DashboardUpload $record): array
    {
        return DocumentStorage::locate($record->path);
    }
}
