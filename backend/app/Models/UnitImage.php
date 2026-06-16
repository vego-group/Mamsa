<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitImage extends Model
{
    protected $fillable = ['unit_id', 'path', 'is_main'];

    protected $casts = ['is_main' => 'boolean'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function getUrlAttribute(): string
    {
        // Pass through absolute URLs (e.g. seeded sample photos); otherwise
        // resolve a locally-stored path against the public storage disk.
        if (str_starts_with($this->path, 'http://') || str_starts_with($this->path, 'https://')) {
            return $this->path;
        }

        return asset('storage/' . $this->path);
    }
}
