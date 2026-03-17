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
        'name',
        'code',
        'description',
        'status',
        'price',
        'calendar_token',
        'calendar_external_url', // جديد
        'type',                  // جديد
        'bedrooms',              // جديد
        'capacity',              // جديد
        'city',                  // جديد
        'district',              // جديد
        'lat',                   // جديد
        'lng',                   // جديد
    ];

    protected static function booted(): void
    {
        static::creating(function (Unit $unit) {
            if (empty($unit->calendar_token)) {
                $unit->calendar_token = Str::random(40);
            }
        });
    }

    public function owner(){ return $this->belongsTo(User::class, 'user_id'); }
    public function images(){ return $this->hasMany(UnitImage::class, 'unit_id', 'id'); }
    public function bookings(){ return $this->hasMany(Booking::class, 'unit_id', 'id'); }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'available'   => 'متاحة',
            'unavailable' => 'غير متاحة',
            'reserved'    => 'محجوزة',
            default       => (string)$this->status,
        };
    }

    public function getStatusClassAttribute(): string
    {
        return match ($this->status) {
            'available'   => 'badge bg-success-subtle text-success',
            'unavailable' => 'badge bg-secondary-subtle text-secondary',
            'reserved'    => 'badge bg-warning-subtle text-warning',
            default       => 'badge bg-light text-body',
        };
    }

    public function getCalendarPublicUrlAttribute(): ?string
    {
        if (!$this->calendar_token) return null;
        return route('units.calendar.ics', ['unit'=>$this->id, 'token'=>$this->calendar_token]);
    }
}