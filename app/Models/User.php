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
        'password' => 'hashed',
    ];

    // العلاقات
    public function roles()
    {
        // pivot: user_roles(user_id, role_id) -> roles(id, name)
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->select(['roles.id','roles.role_name as name']);
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

    // ====== مساعدات الأدوار (تعتمد على عمود roles.name) ======
    protected function normalizeRoleName(string $name): string
    {
        return ucwords(str_replace('_', ' ', trim($name)));
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('role_name', $this->normalizeRoleName($roleName))->exists();
    }

    public function hasAnyRole(array $roleNames): bool
    {
        $normalized = array_map([$this, 'normalizeRoleName'], $roleNames);
        return $this->roles()->whereIn('role_name', $normalized)->exists();
    }

    public function assignRole(string $name, bool $createIfMissing = false): void
    {
        $roleName = $this->normalizeRoleName($name);
        $role = Role::where('role_name', $roleName)->first();

        if (!$role && $createIfMissing) {
            $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
        }
        if ($role) {
            $this->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    public function syncRoles(array|string $names, bool $createIfMissing = false): void
    {
        $names = is_array($names) ? $names : array_map('trim', explode(',', $names));
        $ids   = [];

        foreach ($names as $name) {
            $roleName = $this->normalizeRoleName($name);
            $role = Role::where('name', $roleName)->first();
            if (!$role && $createIfMissing) {
                $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
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

    public function isPartner(): bool
    {
        return $this->hasRole('Partner');
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['Admin', 'Super Admin']);
    }
}