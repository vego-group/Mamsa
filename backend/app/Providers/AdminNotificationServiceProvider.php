<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Refund;
use App\Models\Unit;
use App\Observers\AdminPanel\BookingCancellationObserver;
use App\Observers\AdminPanel\PartnerApplicationObserver;
use App\Observers\AdminPanel\RefundFailureObserver;
use App\Observers\AdminPanel\UnitApprovalObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Fans platform events out to the admin notification feed (BACKEND_SPEC §5.11)
 * via model observers, so an admin is alerted regardless of which frontend
 * triggered the event. Strictly additive: the observers only create admin DB
 * notifications — they never change the domain writes or the existing
 * partner/customer notification flows.
 */
class AdminNotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Unit::observe(UnitApprovalObserver::class);
        Booking::observe(BookingCancellationObserver::class);
        Refund::observe(RefundFailureObserver::class);
        PartnerDetail::observe(PartnerApplicationObserver::class);
    }
}
