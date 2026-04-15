<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminDetail extends Model
{
    protected $table = 'Admin_details';

    protected $fillable = [
        'user_id',
        'type',
        'national_id',
        'company_license_no',
        'cr_number',
    ];

    /* =======================
        Relationships
    ======================== */

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}