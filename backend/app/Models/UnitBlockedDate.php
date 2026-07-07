<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitBlockedDate extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ICAL   = 'ical';

    protected $fillable = [
        'unit_id',
        'start_date',
        'end_date',
        'source',
        'note',
        'external_uid',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Ranges overlapping [$start, $end). end_date follows the iCal DTEND
     * convention (checkout day, exclusive) — same as bookings.
     */
    public function scopeOverlapping(Builder $query, string $start, string $end): Builder
    {
        return $query->where('start_date', '<', $end)
            ->where('end_date', '>', $start);
    }
}
