<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitImage extends Model
{
    protected $fillable = ['unit_id', 'file_id', 'path', 'is_main', 'width', 'height', 'variants', 'sort_order'];

    protected $casts = ['is_main' => 'boolean', 'variants' => 'array'];

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

    /**
     * Derivative URLs keyed by size (`thumb`/`card`/`full`), or null when this
     * row has none — a legacy image, or one whose processing failed. Callers
     * fall back to `url`, which is why the key is omitted rather than pointing
     * at the original: a `thumb` that is secretly full-size would defeat the
     * whole point of asking for one.
     *
     * @return array<string, string>|null
     */
    public function getVariantUrlsAttribute(): ?array
    {
        $paths = $this->variants;

        if (! is_array($paths) || $paths === []) {
            return null;
        }

        $urls = [];

        foreach ($paths as $key => $path) {
            if (is_string($path) && $path !== '') {
                $urls[$key] = asset('storage/' . $path);
            }
        }

        return $urls ?: null;
    }
}
