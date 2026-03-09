<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitImage extends Model
{
    protected $fillable = [
        'unit_id',
        'image_url'
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}