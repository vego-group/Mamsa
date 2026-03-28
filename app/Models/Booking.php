<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'user_id',
        'status',
        'start_date',
        'end_date',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'start_date'   => 'date',
        'end_date'     => 'date',
        'total_amount' => 'decimal:2',
    ];

    // العلاقة مع الوحدات
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    // العلاقة مع المستخدم كحاجز
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // العلاقة الاختيارية اللي مسميتها سابقاً "customer"
    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // لعرض badge جاهز
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'new'       => 'جديد',
            'confirmed' => 'مؤكّد',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            default     => $this->status,
        };
    }

    public function getStatusClassAttribute(): string
    {
        return match ($this->status) {
            'new'       => 'bg-blue-100 text-blue-700 border-blue-300',
            'confirmed' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            'completed' => 'bg-green-100 text-green-700 border-green-300',
            'cancelled' => 'bg-red-100 text-red-700 border-red-300',
            default     => 'bg-gray-100 text-gray-700 border-gray-300',
        };
    }
}