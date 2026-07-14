<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardUpload extends Model
{
    public const KINDS = ['unit_photo', 'license_pdf', 'company_doc'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'kind', 'original_name', 'mime', 'size', 'path', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
