<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedCard extends Model
{
    protected $fillable = [
        'user_id',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'is_default',
        'moyasar_token',
    ];

    protected $casts = [
        'exp_month'  => 'integer',
        'exp_year'   => 'integer',
        'is_default' => 'boolean',
    ];

    // Never expose the gateway token in API payloads.
    protected $hidden = ['moyasar_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
