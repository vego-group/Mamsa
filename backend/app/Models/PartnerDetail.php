<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerDetail extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'type',
        'national_id',
        'national_id_file',
        'cr_number',
        'iban',
        'authorization_letter_file',
        'vat_certificate_file',
        'operator_license_file',
        'status',
        'verified_at',
        'rejection_reason',
        'suspension_reason',
        'verified_documents',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at'        => 'datetime',
        'verified_at'        => 'datetime',
        'verified_documents' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
