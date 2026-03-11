<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitImage extends Model
{
    protected $table = 'unit_images';

    // المفتاح الأساسي الفعلي في الجدول
    protected $primaryKey = 'id';   // ← بدّلناها من image_id إلى id
    public $timestamps = false;     // خليه true إذا عندك created_at/updated_at

    protected $fillable = [
        'unit_id',
        'image_url',
        // 'created_at', // احذف السطر إذا العمود غير موجود عندك
    ];

    public function unit()
    {
        return $this->belongsTo(\App\Models\Unit::class, 'unit_id', 'id');
    }
}