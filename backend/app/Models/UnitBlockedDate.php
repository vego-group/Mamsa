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
        'ical_feed_id',
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
     * The feed that imported this block. Null for manual blocks and for rows
     * from the legacy single-feed sync (units.ical_import_url), which predates
     * ical_feed_id — callers must null-check.
     */
    public function icalFeed(): BelongsTo
    {
        return $this->belongsTo(UnitIcalFeed::class, 'ical_feed_id');
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
