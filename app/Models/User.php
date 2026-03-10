<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    /**
     * الحقول القابلة للتعبئة
     */
    protected $fillable = [
        'phone',
        'name',
        'email',
        'password',
        'email_verified_at',
    ];

    /**
     * إخفاء الحقول عند التحويل لمصفوفة/JSON
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * التحويلات (Casts)
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed', // يشفر تلقائيًا في Laravel 10/11/12
    ];

    /*
    |--------------------------------------------------------------------------
    | العلاقات
    |--------------------------------------------------------------------------
    */
    public function roles()
    {
        // جدول الأدوار: roles
        // جدول الربط: user_roles
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function partner()
    {
        return $this->hasOne(Partner::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /*
    |--------------------------------------------------------------------------
    | مساعدات الأدوار
    |--------------------------------------------------------------------------
    */

    /**
     * هل يملك المستخدم دورًا محددًا (بالاسم)؟
     * يقبل نصًا واحدًا مثل: 'Super Admin'
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $this->normalizeRoleName($roleName))->exists();
    }

    /**
     * هل يملك المستخدم أيًا من الأدوار الممررة؟
     * يقبل مصفوفة مثل: ['Admin', 'Super Admin']
     */
    public function hasAnyRole(array $roleNames): bool
    {
        $normalized = array_map([$this, 'normalizeRoleName'], $roleNames);
        return $this->roles()->whereIn('name', $normalized)->exists();
    }

    /**
     * تسهيل: هل هو شريك؟
     */
    public function isPartner(): bool
    {
        return $this->hasRole('Partner');
    }

    /**
     * تسهيل: هل هو أدمن (Admin أو Super Admin)؟
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['Admin', 'Super Admin']);
    }

    /**
     * تطبيع أسماء الأدوار:
     *  super_admin  -> Super Admin
     *  admin        -> Admin
     */
    protected function normalizeRoleName(string $name): string
    {
        return ucwords(str_replace('_', ' ', trim($name)));
    }
}
