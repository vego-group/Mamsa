<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = [
        'partner_id',
        'unit_type',
        'unit_name',
        'bedrooms',
        'city',
        'district',
        'lat',
        'lng',
        'price',
        'description',
        'capacity',
        'approval_status',
        'unit_status'
    ];

    /* =======================
        Relationships
    ======================== */

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function images()
    {
        return $this->hasMany(UnitImage::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}