<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    /**
     * SuperAdmin bypass (يشوف ويسوي كل شيء)
     */
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('SuperAdmin')) {
            return true;
        }

        return null;
    }

    /**
     * عرض قائمة الوحدات
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin') || $user->hasRole('SuperAdmin');
    }

    /**
     * عرض وحدة واحدة
     */
    public function view(User $user, Unit $unit): bool
    {
        return $unit->user_id === $user->id;
    }

    /**
     * إنشاء وحدة
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * تعديل وحدة (فقط إذا approved)
     */
    public function update(User $user, Unit $unit): bool
    {
        return
            $unit->user_id === $user->id &&
            $unit->approval_status === 'approved';
    }

    /**
     * حذف وحدة (فقط إذا approved)
     */
    public function delete(User $user, Unit $unit): bool
    {
        return
            $unit->user_id === $user->id &&
            $unit->approval_status === 'approved';
    }

    /**
     * الموافقة / الرفض (SuperAdmin فقط)
     */
    public function approve(User $user, Unit $unit): bool
    {
        return $user->hasRole('SuperAdmin');
    }
}