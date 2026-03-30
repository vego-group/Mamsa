<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminDetail extends Model
{
    protected $table = 'admin_details';

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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
