<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'code',
        'description',
        'status',
        'price',
    ];

    // مالك الوحدة (المشرف الذي أنشأها)
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // بادجات جاهزة للعرض
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'available'   => 'متاحة',
            'unavailable' => 'غير متاحة',
            'reserved'    => 'محجوزة',
            default       => $this->status,
        };
    }

    public function getStatusClassAttribute(): string
    {
        return match ($this->status) {
            'available'   => 'bg-green-100 text-green-700 border-green-300',
            'unavailable' => 'bg-gray-200 text-gray-700 border-gray-300',
            'reserved'    => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            default       => 'bg-gray-100 text-gray-700 border-gray-200',
        };
    }
}