<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @method bool isAdmin()
 
 */
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

    // العلاقات
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->select(['roles.id', 'roles.name as name']);
    }

   public function adminDetails()
   {
    return $this->hasOne(AdminDetail::class);
   }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    // normalize
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


    /*=====================
        أدوار النظام
    =====================*/

   
    // أدمن + سوبر
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['Admin', 'Super Admin']);
    }
}