<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $fillable = [
        'name',
        'role',
        'quote',
        'avatar_url',
        'rating',
        'deal',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'rating'     => 'integer',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Active testimonials, in display order. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }
}
