<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'phone',
        'name',
        'email',
        'password',
        'email_verified_at',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];

    /* =======================
        العلاقات
    ======================== */

    // الأدوار
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->select(['roles.id', 'roles.name as name']);
    }

    // علاقة التفاصيل الإدارية (بدل Partner)
    public function adminDetail()
    {
        return $this->hasOne(AdminDetail::class, 'user_id');
    }

    // اختياري: alias للوراثة القديمة (ما يخرب الأكواد)
    public function partner()
    {
        return $this->adminDetail();
    }

    // الحجوزات
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    // المراجعات
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /* =======================
        نظام الأدوار
    ======================== */

    protected function normalizeRoleName(string $name): string
    {
        return ucwords(str_replace('_', ' ', trim($name)));
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles()
            ->where('name', $this->normalizeRoleName($roleName))
            ->exists();
    }

    public function hasAnyRole(array $roleNames): bool
    {
        $normalized = array_map([$this, 'normalizeRoleName'], $roleNames);
        return $this->roles()->whereIn('name', $normalized)->exists();
    }

    public function assignRole(string $name, bool $createIfMissing = false): void
    {
        $roleName = $this->normalizeRoleName($name);
        $role = Role::where('name', $roleName)->first();

        if (!$role && $createIfMissing) {
            $role = Role::create(['name' => $roleName]);
        }

        if ($role) {
            $this->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    public function syncRoles(array|string $names, bool $createIfMissing = false): void
    {
        $names = is_array($names) ? $names : explode(',', $names);

        $ids = [];

        foreach ($names as $name) {
            $roleName = $this->normalizeRoleName($name);
            $role = Role::where('name', $roleName)->first();

            if (!$role && $createIfMissing) {
                $role = Role::create(['name' => $roleName]);
            }

            if ($role) {
                $ids[] = $role->id;
            }
        }

        $this->roles()->sync($ids);
    }

    public function removeRole(string $name): void
    {
        $roleName = $this->normalizeRoleName($name);
        $role = Role::where('name', $roleName)->first();

        if ($role) {
            $this->roles()->detach($role->id);
        }
    }

    /* =======================
        أدوار النظام
    ======================== */

    // مدير (Admin + Super Admin)
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['Admin', 'Super Admin']);
    }

    // شريك (Partner) ← بناءً على الرول
    public function isPartner(): bool
    {
        return $this->hasRole('Partner');
    }
}