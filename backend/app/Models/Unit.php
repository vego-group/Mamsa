<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    /**
     * The only unit types the platform supports (backend gaps #3).
     * Every public endpoint is constrained to these, and partner
     * create/update validation enforces the same set.
     */
    public const SUPPORTED_TYPES = ['apartment', 'studio', 'villa'];

    protected $fillable = [
        'user_id',
        'unit_name',
        'unit_type',
        'code',
        'price',
        'cleaning_fee',
        'capacity',
        'bedrooms',
        'bathrooms',
        'area',
        'city',
        'district',
        'address',
        'lat',
        'lng',
        'description',
        'tourism_permit_no',
        'tourism_permit_file',
        'company_license_no',
        'approval_status',
        'rejection_reason',
        'status',
        'cancellation_policy',
        'cancellation_policy_id',
        'checkin_time',
        'checkout_time',
        'calendar_token',
        'ical_import_url',
    ];

    protected $casts = [
        'price'        => 'float',
        'cleaning_fee' => 'float',
        'lat'          => 'float',
        'lng'      => 'float',
        'capacity' => 'integer',
        'bedrooms' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(UnitImage::class);
    }

    public function mainImage(): HasMany
    {
        return $this->hasMany(UnitImage::class)->where('is_main', true);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'unit_features');
    }

    public function icalFeeds(): HasMany
    {
        return $this->hasMany(UnitIcalFeed::class);
    }

    public function blockedDates(): HasMany
    {
        return $this->hasMany(UnitBlockedDate::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function getAvgRatingAttribute(): float|null
    {
        return $this->reviews()->avg('rating');
    }
}
