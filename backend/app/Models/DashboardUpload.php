<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
