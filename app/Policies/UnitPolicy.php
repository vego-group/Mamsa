<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Unit;

class UnitPolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // المدير غير النشط لا يسمح له إلا بالعرض
        if ($user->hasRole('admin') && intval($user->is_active) !== 1) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin','admin']);
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->id === $unit->user_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin') && intval($user->is_active) === 1;
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->id === $unit->user_id &&
               intval($user->is_active) === 1;
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->id === $unit->user_id &&
               intval($user->is_active) === 1;
    }
}