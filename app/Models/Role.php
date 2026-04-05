<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'name',        // مثل: Admin, Super Admin, 
        'guard_name',  // إن وجد عندك هذا العمود (لا يضر وجوده)
    ];

    /**
     * علاقة Many-to-Many مع المستخدمين عبر pivot: user_roles(user_id, role_id)
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}