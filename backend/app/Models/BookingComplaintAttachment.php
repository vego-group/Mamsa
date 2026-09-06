<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo attached to a complaint. Stored on a private disk — `path` is never
 * rendered as a URL directly; admin surfaces mint a short-lived signed URL.
 *
 * Written once, never edited: created_at only, no updated_at.
 */
class BookingComplaintAttachment extends Model
{
    public const UPDATED_AT = null;

    /** The only image types accepted (spec §4.2). */
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_PER_COMPLAINT = 6;
    public const MAX_BYTES         = 5 * 1024 * 1024;

    /** Signed-URL lifetime. Short on purpose: a pasted link should expire. */
    public const SIGNED_URL_TTL_MINUTES = 15;

    protected $fillable = [
        'complaint_id',
        'path',
        'mime',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    /**
     * A signed URL that expires. Built here so every surface — guest, admin,
     * partner — mints them the same way and none of them is tempted to hand
     * out a raw path.
     */
    public static function signedUrl(self $attachment): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'complaints.attachment',
            now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            ['attachment' => $attachment->id],
        );
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(BookingComplaint::class, 'complaint_id');
    }
}
