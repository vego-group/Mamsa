<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Unit extends Model
{
    use HasFactory;

    protected $table = 'units';

    protected $fillable = [
        'user_id',

        'unit_name',
        'unit_type',

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

        'calendar_token',
        'calendar_external_url',

        'approval_status',
        'status',

        'cancellation_policy',
        'checkin_time',
        'checkout_time',
    ];

    // ✅ هنا مكانها الصحيح
    protected $casts = [
        'price' => 'decimal:2',
    ];

    /* =========================
        Relationships
    ========================= */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function images()
    {
        return $this->hasMany(UnitImage::class, 'unit_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'unit_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'unit_id');
    }

    protected static function booted()
    {
        static::creating(function ($unit) {
            if (empty($unit->calendar_token)) {
                $unit->calendar_token = Str::random(40);
            }
        });
    }
}