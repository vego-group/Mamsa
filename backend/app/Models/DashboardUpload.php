<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DashboardUpload extends Model
{
    public const KINDS = ['unit_photo', 'license_pdf', 'company_doc', 'ownership_doc'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'kind', 'original_name', 'mime', 'size', 'path', 'status', 'width', 'height', 'variants'];

    protected $casts = ['variants' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve a stored file reference to a public URL. Unit permit / partner KYC
     * columns store a DashboardUpload id (`file_...`); older rows may hold a raw
     * relative path or an absolute URL. Uploads always land on the `public`
     * disk (see UploadController), so the file is served statically.
     */
    /**
     * A short-lived, authorised link to a compliance document.
     *
     * The counterpart to resolveUrl(): that one produces a permanent public
     * URL, which is exactly the problem — a permit or an ID scan reachable
     * forever by anyone who has ever seen the address. This produces a link
     * that expires and that the route refuses to serve to anyone but the
     * reviewer or the document's owner.
     *
     * Minted at read time and never stored, so the expiry only bites when a
     * screen is left open longer than the window.
     *
     * Returns null for a blank value, matching resolveUrl(), so a caller
     * swapping one for the other keeps its null handling.
     */
    public static function signedUrl(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        // Columns hold either an upload id or a raw path depending on which
        // surface wrote them. Only an id can be signed — a raw path has no row
        // to authorise against, so it falls back to the public URL until the
        // migration gives it one.
        if (! str_starts_with($value, 'file_')) {
            return self::resolveUrl($value);
        }

        return URL::temporarySignedRoute(
            'documents.show',
            now()->addMinutes((int) config('documents.link_minutes', 120)),
            ['upload' => $value],
        );
    }

    public static function resolveUrl(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $path = str_starts_with($value, 'file_')
            ? static::whereKey($value)->value('path')
            : $value;

        return blank($path) ? null : Storage::disk('public')->url(ltrim($path, '/'));
    }
}
