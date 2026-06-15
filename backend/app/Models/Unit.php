<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = [
        'user_id',
        'unit_name',
        'unit_type',
        'code',
        'price',
        'capacity',
        'bedrooms',
        'city',
        'district',
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
        'checkin_time',
        'checkout_time',
        'calendar_token',
    ];

    protected $casts = [
        'price'    => 'float',
        'lat'      => 'float',
        'lng'      => 'float',
        'capacity' => 'integer',
        'bedrooms' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
