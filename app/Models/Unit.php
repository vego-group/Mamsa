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
        'name',          // اسم الوحدة
        'code',          // الكود
        'description',
        'status',        // available | unavailable | reserved
        'price',
        'calendar_token'
    ];

    protected static function booted(): void
    {
        // توليد calendar_token تلقائيًا إذا مفقود
        static::creating(function (Unit $unit) {
            if (empty($unit->calendar_token)) {
                $unit->calendar_token = Str::random(40);
            }
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function images()
    {
        // FK: unit_images.unit_id --> units.id
        return $this->hasMany(UnitImage::class, 'unit_id', 'id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'unit_id', 'id');
    }

    // بادجات
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

    // رابط التقويم العام (ICS)
    public function getCalendarPublicUrlAttribute(): ?string
    {
        if (!$this->calendar_token) return null;
        return route('units.calendar.ics', ['unit' => $this->id, 'token' => $this->calendar_token]);
    }
}