<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'unit_features');
    }
}
