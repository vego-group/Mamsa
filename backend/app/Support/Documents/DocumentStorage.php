<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Models\DashboardUpload;
use App\Models\PartnerDetail;
use App\Models\Unit;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where a compliance document lives, and the only thing that decides it.
 *
 * Permits, commercial registrations and ID scans used to be written to the
 * public disk, which this host serves as static files through a symlink: no
 * signature, no expiry, no authorisation. They now go to a vault under
 * `storage/app`, outside that symlink, reachable only through the signed and
 * authorised /documents route.
 *
 * The stored PATH does not change — `dashboard/license_pdf/file_x.pdf` means
 * the same thing on either disk. Only the disk does. That is what lets the
 * reader look in the vault and fall back to the public disk without a column
 * migration, and it is why documents written before the switch keep working
 * until they are moved.
 *
 * One class rather than the rule repeated at each writer, because the cost of
 * two writers disagreeing is asymmetric: a document mistaken for a photo is
 * published, while a photo mistaken for a document merely goes through PHP.
 */
final class DocumentStorage
{
    /** Is this upload kind a compliance document rather than public imagery? */
    public static function isSensitive(?string $kind): bool
    {
        return in_array((string) $kind, (array) config('documents.sensitive_kinds', []), true);
    }

    /** Does this stored path sit in a documents directory? */
    public static function isSensitivePath(?string $path): bool
    {
        foreach ((array) config('documents.sensitive_dirs', []) as $dir) {
            if (str_starts_with((string) $path, rtrim($dir, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write bytes to the disk this kind belongs on.
     *
     * Returns the logical path unchanged, so callers keep storing exactly what
     * they stored before.
     */
    public static function put(string $path, string $bytes, bool $sensitive): string
    {
        if ($sensitive) {
            self::vault()->put(self::vaultPath($path), $bytes);
        } else {
            Storage::disk('public')->put($path, $bytes);
        }

        return $path;
    }

    /**
     * Where the bytes actually are — vault first, then the public disk.
     *
     * Both are checked for as long as unmigrated documents exist. Once the
     * migration is done the public branch stops matching and can come out.
     *
     * @return array{0: Filesystem|null, 1: string}
     */
    public static function locate(?string $path): array
    {
        $path = trim((string) $path);

        if ($path === '') {
            return [null, ''];
        }

        $vault = self::vault();

        if ($vault->exists(self::vaultPath($path))) {
            return [$vault, self::vaultPath($path)];
        }

        $public = Storage::disk('public');

        return $public->exists($path) ? [$public, $path] : [null, ''];
    }

    /** Remove a document from whichever disk holds it. */
    public static function delete(?string $path): void
    {
        [$disk, $resolved] = self::locate($path);

        $disk?->delete($resolved);
    }

    /**
     * Drop a document that has just been replaced, if nothing else wants it.
     *
     * Two shapes arrive here. A bare PATH was written by one surface and is
     * referenced by one column, so replacing it means the bytes are finished.
     * An upload ID may be referenced from somewhere else entirely — that is why
     * the old code refused to delete ids at all, and why replacing a document
     * left its predecessor on disk forever.
     *
     * Checking is cheap and settles it: if no live column still points at the
     * id, the bytes and the row go. If one does, both stay. Either way a
     * replacement stops accumulating documents nobody can reach and nobody
     * deletes — which is exactly how the orphans on production got there.
     */
    public static function forget(?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        if (! str_starts_with($value, 'file_')) {
            self::delete($value);

            return;
        }

        if (self::referencedAnywhere($value)) {
            return;
        }

        $upload = DashboardUpload::find($value);

        if ($upload === null) {
            return;
        }

        self::delete($upload->path);
        $upload->delete();
    }

    /**
     * Does any live column still point at this upload?
     *
     * The same columns the orphan sweep reads, for the same reason: these are
     * the only places a document can be reachable from.
     */
    public static function referencedAnywhere(string $value): bool
    {
        foreach (['tourism_permit_file', 'ownership_doc_file'] as $column) {
            if (Unit::where($column, $value)->exists()) {
                return true;
            }
        }

        $partner = new PartnerDetail;

        foreach ($partner->getFillable() as $column) {
            if (str_contains($column, 'file') && PartnerDetail::where($column, $value)->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function vaultPath(string $path): string
    {
        return trim((string) config('documents.vault_root'), '/').'/'.ltrim($path, '/');
    }

    private static function vault(): Filesystem
    {
        return Storage::disk(config('documents.vault_disk'));
    }
}
