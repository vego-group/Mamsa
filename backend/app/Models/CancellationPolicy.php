<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cancellation policy template (flexible / moderate / strict) — SRS 2.3.1.
 */
class CancellationPolicy extends Model
{
    protected $fillable = [
        'key',
        'name_ar',
        'name_en',
        'description',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /** Refund tiers ordered widest threshold first (matched top-down). */
    public function tiers(): HasMany
    {
        return $this->hasMany(PolicyTier::class)->orderByDesc('min_hours_before_checkin');
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
