<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitIcalFeed extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED  = 'synced';
    public const STATUS_ERROR   = 'error';

    protected $fillable = ['unit_id', 'source', 'url', 'status', 'error', 'last_synced_at'];

    protected $casts = ['last_synced_at' => 'datetime'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function blockedDates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UnitBlockedDate::class, 'ical_feed_id');
    }
}
