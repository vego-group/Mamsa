<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    /**
     * before(): يتم استدعاؤها قبل كل الدوال
     * - إذا المستخدم سوبر أدمن → كل شيء مسموح
     * - إذا المستخدم مدير ولكن غير نشط is_active != 1 → يمنع كل شيء (ماعدا view/viewAny حسب الدوال الأخرى)
     */
    public function before(User $user, string $ability)
    {
        // سوبر أدمن = جميع الصلاحيات دائمًا
        if ($user->hasRole('SuperAdmin')) {
            return true;
        }

        // مدير غير نشط → يمنع الإضافة/التعديل/الحذف
        if ($user->hasRole('Admin') && intval($user->is_active) !== 1) {
            return false;
        }

        return null; // يكمل باقي الشروط
    }

    /**
     * عرض جميع الحجوزات (index)
     * المدير + السوبر أدمن يشوفون
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['SuperAdmin', 'Admin']);
    }

    /**
     * عرض حجز واحد
     * المدير يشوف فقط حجوزات الوحدات اللي يملكها
     */
    public function view(User $user, Booking $booking): bool
    {
        return $booking->unit && $booking->unit->user_id === $user->id;
    }

    /**
     * إنشاء حجز
     * المدير النشط فقط (is_active = 1)
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin') && intval($user->is_active) === 1;
    }

    /**
     * تعديل حجز
     * فقط لو الحجز يتبع وحدة يملكها — والمدير نشط
     */
    public function update(User $user, Booking $booking): bool
    {
        return $booking->unit &&
               $booking->unit->user_id === $user->id &&
               intval($user->is_active) === 1;
    }

    /**
     * حذف حجز
     * نفس شروط التعديل
     */
    public function delete(User $user, Booking $booking): bool
    {
        return $booking->unit &&
               $booking->unit->user_id === $user->id &&
               intval($user->is_active) === 1;
    }
}