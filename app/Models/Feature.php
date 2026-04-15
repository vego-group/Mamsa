<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Feature extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function units()
    {
        return $this->belongsToMany(Unit::class, 'unit_features', 'feature_id', 'unit_id');
    }
}