<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'payment_method',
        'payment_status',
        'paid_at',
        'amount',
        'moyasar_id',
        'moyasar_reference',
        'moyasar_response',
    ];

    protected $casts = [
        'paid_at'          => 'datetime',
        'moyasar_response' => 'array',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}