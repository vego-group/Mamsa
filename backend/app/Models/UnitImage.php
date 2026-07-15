<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitImage extends Model
{
    protected $fillable = ['unit_id', 'file_id', 'path', 'is_main'];

    protected $casts = ['is_main' => 'boolean'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function getUrlAttribute(): string
    {
        $path = trim((string) $this->path);

        // Empty/legacy rows fall back to the bundled default so the URL is never
        // just "<base>/storage" (which happens when the path is blank).
        if ($path === '') {
            return \App\Support\Media::defaultImageUrl();
        }

        // Pass through absolute URLs (e.g. seeded sample photos); otherwise
        // resolve a locally-stored path against the public storage disk.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/' . $path);
    }
}
