<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Unit;

class UnitPolicy
{
    // super_admin له كل الصلاحيات
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return null;
    }

    public function viewAny(User $user): bool
    {
        // admin يقدر يشوف وحداته فقط (يتم تطبيق ذلك في الـController عبر فلترة)
        return $user->hasAnyRole(['super_admin','admin']);
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->id === $unit->user_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('super_admin');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->id === $unit->user_id;
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->id === $unit->user_id;
    }
}