<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'tourism_permit_no',
        'national_id',
        'company_license_no',
        'cr_number',
        'verification_status',
        'verified_at'
    ];

    protected $casts = [
        'verified_at' => 'datetime'
    ];

    /* =======================
        Relationships
    ======================== */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function units()
    {
        return $this->hasMany(Unit::class);
    }
}