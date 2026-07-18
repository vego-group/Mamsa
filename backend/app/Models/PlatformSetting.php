<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value store for the few runtime-editable platform knobs
 * (currently only service_fee_percent). Read through App\Support\Pricing,
 * which caches values — never query this model directly in request code.
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];
}
