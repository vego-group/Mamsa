<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewBooking;
use App\Notifications\NewUnitRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds in-app notifications so the NotificationBell has real content for
 * admins and partners. Writes the database channel directly (via each
 * notification's toArray) to avoid firing mail/SMS during seeding.
 * Idempotent: skips any user that already has notifications.
 */
class NotificationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdmins();
        $this->seedPartners();
    }

    /** Admins: recent "unit awaiting review" requests. */
    private function seedAdmins(): void
    {
        $admins = User::role(['Admin', 'SuperAdmin'])->get();
        $units  = Unit::with('owner')->latest('id')->take(3)->get();

        if ($units->isEmpty()) {
            return;
        }

        foreach ($admins as $admin) {
            if ($admin->notifications()->exists()) {
                continue;
            }

            foreach ($units as $i => $unit) {
                $this->store($admin, NewUnitRequest::class, (new NewUnitRequest($unit))->toArray($admin), $i);
            }
        }
    }

    /** Partners: recent bookings on their own units. */
    private function seedPartners(): void
    {
        $partners = User::role(['Individual', 'Company'])->get();

        foreach ($partners as $partner) {
            if ($partner->notifications()->exists()) {
                continue;
            }

            $bookings = Booking::whereHas('unit', fn ($q) => $q->where('user_id', $partner->id))
                ->with(['unit', 'user'])
                ->latest('id')
                ->take(3)
                ->get();

            foreach ($bookings as $i => $booking) {
                $this->store($partner, NewBooking::class, (new NewBooking($booking))->toArray($partner), $i);
            }
        }
    }

    /**
     * Insert one database notification. The newest (i === 0) stays unread so the
     * bell shows a badge; older ones are marked read for realism.
     *
     * @param array<string, mixed> $data
     */
    private function store(User $user, string $type, array $data, int $i): void
    {
        $user->notifications()->create([
            'id'         => (string) Str::uuid(),
            'type'       => $type,
            'data'       => $data,
            'read_at'    => $i === 0 ? null : Carbon::now()->subDays($i),
            'created_at' => Carbon::now()->subDays($i)->subHours($i * 2),
            'updated_at' => Carbon::now()->subDays($i),
        ]);
    }
}
