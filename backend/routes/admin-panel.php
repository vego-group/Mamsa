<?php

declare(strict_types=1);

/**
 * Admin-panel (Next.js) contract API — BACKEND_SPEC.md.
 *
 * Root-mounted under /admin/* so the frontend's documented paths work verbatim
 * against the API host (no /api/v1 prefix). Auth = httpOnly session cookie
 * (guard: admin-panel), OTP only — no passwords. Wrapped in the `admin-panel`
 * middleware group (bootstrap/app.php); envelope is flat { message, code }.
 */

use App\Http\Controllers\AdminPanel;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {

    /* ---- Auth (public) ---- BACKEND_SPEC §5.1 */
    Route::post('auth/request-otp', [AdminPanel\AuthController::class, 'requestOtp'])
        ->middleware('throttle:ap-otp')->name('ap.otp.request');
    Route::post('auth/verify-otp', [AdminPanel\AuthController::class, 'verifyOtp'])
        ->middleware('throttle:10,1')->name('ap.otp.verify');
    Route::post('auth/logout', [AdminPanel\AuthController::class, 'logout'])->name('ap.logout');

    /* ---- Authenticated admin session ---- */
    Route::middleware(['auth:admin-panel', 'throttle:240,1'])->group(function () {

        Route::get('me', [AdminPanel\AuthController::class, 'me'])->name('ap.me');

        /* Dashboard — §5.3 */
        Route::get('dashboard/summary', [AdminPanel\DashboardController::class, 'summary'])->middleware('admin.can:dashboard.view')->name('ap.dashboard.summary');

        /* Reports — §5.10 */
        Route::get('reports/summary', [AdminPanel\ReportsController::class, 'summary'])->middleware('admin.can:reports.financial')->name('ap.reports.summary');

        /* Notifications (admin feed) — §5.11 */
        Route::get('notifications', [AdminPanel\NotificationsController::class, 'index'])->middleware('admin.can:notifications.view')->name('ap.notifications.index');
        Route::get('notifications/unread-count', [AdminPanel\NotificationsController::class, 'unreadCount'])->middleware('admin.can:notifications.view')->name('ap.notifications.unread-count');
        Route::post('notifications/read-all', [AdminPanel\NotificationsController::class, 'readAll'])->middleware('admin.can:notifications.view')->name('ap.notifications.read-all');
        Route::post('notifications/{id}/read', [AdminPanel\NotificationsController::class, 'read'])->middleware('admin.can:notifications.view')->name('ap.notifications.read');

        /* Profile — BACKEND_SPEC §5.2 */
        Route::get('profile', [AdminPanel\ProfileController::class, 'show'])->middleware('admin.can:profile.view')->name('ap.profile.show');
        Route::patch('profile', [AdminPanel\ProfileController::class, 'update'])->middleware('admin.can:profile.view')->name('ap.profile.update');
        Route::get('profile/sessions', [AdminPanel\ProfileController::class, 'sessions'])->middleware('admin.can:profile.view')->name('ap.profile.sessions');
        Route::delete('profile/sessions/{id}', [AdminPanel\ProfileController::class, 'revokeSession'])->middleware('admin.can:profile.view')->name('ap.profile.sessions.revoke');

        /* Users (guests) — §5.4 */
        Route::get('users', [AdminPanel\UsersController::class, 'index'])->middleware('admin.can:users.view')->name('ap.users.index');
        Route::get('users/stats', [AdminPanel\UsersController::class, 'stats'])->middleware('admin.can:users.view')->name('ap.users.stats');
        Route::post('users/invite', [AdminPanel\UsersController::class, 'invite'])->middleware('admin.can:users.manage')->name('ap.users.invite');
        Route::get('users/{id}', [AdminPanel\UsersController::class, 'show'])->middleware('admin.can:users.view')->name('ap.users.show');
        Route::patch('users/{id}/status', [AdminPanel\UsersController::class, 'status'])->middleware('admin.can:users.manage')->name('ap.users.status');
        Route::delete('users/{id}', [AdminPanel\UsersController::class, 'destroy'])->middleware('admin.can:users.manage')->name('ap.users.destroy');

        /* Partners (hosts) — §5.5 */
        Route::get('partners', [AdminPanel\PartnersController::class, 'index'])->middleware('admin.can:partners.view')->name('ap.partners.index');
        Route::get('partners/stats', [AdminPanel\PartnersController::class, 'stats'])->middleware('admin.can:partners.view')->name('ap.partners.stats');
        Route::post('partners/invite', [AdminPanel\PartnersController::class, 'invite'])->middleware('admin.can:partners.manage')->name('ap.partners.invite');
        Route::get('partners/{id}', [AdminPanel\PartnersController::class, 'show'])->middleware('admin.can:partners.view')->name('ap.partners.show');
        Route::post('partners/{id}/approve', [AdminPanel\PartnersController::class, 'approve'])->middleware('admin.can:partners.manage')->name('ap.partners.approve');
        Route::post('partners/{id}/reject', [AdminPanel\PartnersController::class, 'reject'])->middleware('admin.can:partners.manage')->name('ap.partners.reject');
        Route::post('partners/{id}/suspend', [AdminPanel\PartnersController::class, 'suspend'])->middleware('admin.can:partners.manage')->name('ap.partners.suspend');
        Route::post('partners/{id}/verify', [AdminPanel\PartnersController::class, 'verify'])->middleware('admin.can:partners.manage')->name('ap.partners.verify');
        Route::post('partners/{id}/revoke-verification', [AdminPanel\PartnersController::class, 'revokeVerification'])->middleware('admin.can:partners.manage')->name('ap.partners.revoke-verification');
        Route::post('partners/{partnerId}/documents/{documentId}/verify', [AdminPanel\PartnersController::class, 'verifyDocument'])->middleware('admin.can:partners.manage')->name('ap.partners.documents.verify');

        /* Units (properties) — §5.6 */
        Route::get('units', [AdminPanel\UnitsController::class, 'index'])->middleware('admin.can:units.view')->name('ap.units.index');
        Route::get('units/stats', [AdminPanel\UnitsController::class, 'stats'])->middleware('admin.can:units.view')->name('ap.units.stats');
        Route::post('units', [AdminPanel\UnitsController::class, 'store'])->middleware('admin.can:units.manage')->name('ap.units.store');
        Route::get('units/{id}', [AdminPanel\UnitsController::class, 'show'])->middleware('admin.can:units.view')->name('ap.units.show');
        Route::post('units/{id}/unpublish', [AdminPanel\UnitsController::class, 'unpublish'])->middleware('admin.can:units.manage')->name('ap.units.unpublish');

        /* Bookings (read-only) — §5.8 */
        Route::get('bookings', [AdminPanel\BookingsController::class, 'index'])->middleware('admin.can:bookings.view')->name('ap.bookings.index');
        Route::get('bookings/counts', [AdminPanel\BookingsController::class, 'counts'])->middleware('admin.can:bookings.view')->name('ap.bookings.counts');
        Route::get('bookings/stats', [AdminPanel\BookingsController::class, 'stats'])->middleware('admin.can:bookings.view')->name('ap.bookings.stats');
        Route::get('bookings/{id}', [AdminPanel\BookingsController::class, 'show'])->middleware('admin.can:bookings.view')->name('ap.bookings.show');

        /* Cancellations — §5.9 */
        Route::get('cancellations', [AdminPanel\CancellationsController::class, 'index'])->middleware('admin.can:cancellations.view')->name('ap.cancellations.index');
        Route::get('cancellations/stats', [AdminPanel\CancellationsController::class, 'stats'])->middleware('admin.can:cancellations.view')->name('ap.cancellations.stats');
        Route::get('cancellations/high-risk-partners', [AdminPanel\CancellationsController::class, 'highRiskPartners'])->middleware('admin.can:cancellations.view')->name('ap.cancellations.high-risk');
        Route::post('cancellations/{id}/retry-refund', [AdminPanel\CancellationsController::class, 'retryRefund'])->middleware('admin.can:cancellations.manage')->name('ap.cancellations.retry-refund');

        /* Approvals (unit review queue) — §5.7 */
        Route::get('approvals', [AdminPanel\ApprovalsController::class, 'index'])->middleware('admin.can:approvals.view')->name('ap.approvals.index');
        Route::get('approvals/stats', [AdminPanel\ApprovalsController::class, 'stats'])->middleware('admin.can:approvals.view')->name('ap.approvals.stats');
        Route::get('approvals/{id}', [AdminPanel\ApprovalsController::class, 'show'])->middleware('admin.can:approvals.view')->name('ap.approvals.show');
        Route::post('approvals/{id}/approve', [AdminPanel\ApprovalsController::class, 'approve'])->middleware('admin.can:approvals.manage')->name('ap.approvals.approve');
        Route::post('approvals/{id}/reject', [AdminPanel\ApprovalsController::class, 'reject'])->middleware('admin.can:approvals.manage')->name('ap.approvals.reject');

        /* ---- Wallets & Payouts — STUBS (contract v2.2 §5.1/§5.2) ----
         * Fixture-backed, registered on non-production ONLY so prod never
         * serves fixtures. Real controllers replace these in Phase 4/6. */
        if (! app()->isProduction()) {
            Route::get('payouts/eligible', [AdminPanel\Stub\PayoutStubController::class, 'eligible'])->middleware('admin.can:payouts.view')->name('ap.payouts.eligible');
            Route::get('payouts/ineligible', [AdminPanel\Stub\PayoutStubController::class, 'ineligible'])->middleware('admin.can:payouts.view')->name('ap.payouts.ineligible');
            Route::post('payouts/record', [AdminPanel\Stub\PayoutStubController::class, 'record'])->middleware('admin.can:payouts.execute')->name('ap.payouts.record');

            Route::get('wallets', [AdminPanel\Stub\WalletStubController::class, 'index'])->middleware('admin.can:wallets.view')->name('ap.wallets.index');
            Route::get('wallets/{partnerId}/ledger', [AdminPanel\Stub\WalletStubController::class, 'ledger'])->middleware('admin.can:wallets.view')->name('ap.wallets.ledger');
            Route::get('wallets/{partnerId}', [AdminPanel\Stub\WalletStubController::class, 'show'])->middleware('admin.can:wallets.view')->name('ap.wallets.show');
        }
    });
});
