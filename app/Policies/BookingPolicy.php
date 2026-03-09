<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    // super_admin له كل الصلاحيات
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return null;
    }

    // يستطيع أي admin رؤية حجوزات وحداته فقط (فلترة في Controller + تحقق هنا عند العرض المفرد)
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin','admin']);
    }

    public function view(User $user, Booking $booking): bool
    {
        // المالك هو مالك الوحدة المرتبطة بالحجز
        return $booking->unit && $booking->unit->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin','admin']);
    }

    public function update(User $user, Booking $booking): bool
    {
        return $booking->unit && $booking->unit->user_id === $user->id;
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $booking->unit && $booking->unit->user_id === $user->id;
    }
}