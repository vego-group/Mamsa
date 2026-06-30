<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    protected $fillable = [
        'title',
        'subtitle',
        'discount_percent',
        'image_url',
        'valid_until',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'valid_until'      => 'date',
        'is_active'        => 'boolean',
        'discount_percent' => 'integer',
        'sort_order'       => 'integer',
    ];

    /** Active offers that haven't expired, in display order. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }
}
