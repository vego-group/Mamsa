<?php

declare(strict_types=1);

/**
 * Partner-dashboard contract API (mamsa-backend-api-requirements.md v1.2).
 *
 * Root-mounted so the frontend's documented paths work verbatim against
 * https://api.mamsaa.com. Auth = httpOnly session cookie (guard: dashboard).
 * Wrapped in the `dashboard-api` middleware group (bootstrap/app.php).
 */

use App\Http\Controllers\ComplaintAttachmentController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

/* ---- Auth (public) ---- */
Route::post('auth/otp/request', [Dashboard\AuthController::class, 'requestOtp'])
    ->middleware('throttle:pd-otp')->name('pd.otp.request');
Route::post('auth/otp/resend', [Dashboard\AuthController::class, 'requestOtp'])
    ->middleware('throttle:pd-otp')->name('pd.otp.resend');
Route::post('auth/otp/verify', [Dashboard\AuthController::class, 'verifyOtp'])
    ->middleware('throttle:10,1')->name('pd.otp.verify');
Route::post('auth/logout', [Dashboard\AuthController::class, 'logout'])->name('pd.logout');

/* ---- Signed upload target (auth via URL signature, not session) ---- */
Route::put('uploads/{upload}', [Dashboard\UploadController::class, 'receive'])
    ->middleware('signed')->name('pd.uploads.receive');

/* ---- Complaint photos ----
 * Signed, not session-authenticated: the same link is rendered in the guest
 * app (Bearer), the admin console and the partner dashboard — three guards on
 * two hosts. A short-lived signature is the one credential all three can hold,
 * and unlike a session it expires on its own if the link is forwarded. */
/* ---- Compliance documents: signed AND authorised ----
 *
 * Unlike the attachment route above, a signature alone is not enough here. That
 * one is read from three surfaces with three different guards, so a short-lived
 * signature is the only credential all three can carry. These documents —
 * permits, commercial registrations, national IDs — are read by exactly two
 * people, the reviewer and the owner, and both have sessions. Identity is
 * available, so it is required: the controller checks both cookie guards.
 *
 * Sits in this group for EncryptCookies + StartSession; DashboardApi only
 * gates unsafe methods, so a GET passes through it untouched. */
Route::get('documents/{upload}', DocumentController::class)
    ->middleware('signed')->name('documents.show');

Route::get('complaints/attachments/{attachment}', ComplaintAttachmentController::class)
    ->middleware('signed')->name('complaints.attachment');

/* ---- Moyasar webhook (secret-token verified in controller) ---- */
Route::post('webhooks/moyasar', [Dashboard\WebhookController::class, 'moyasar'])->name('pd.webhook.moyasar');

/* ---- Authenticated partner session ---- */
Route::middleware(['auth:dashboard', 'throttle:120,1'])->group(function () {

    /* Complaints against this partner's units — read-only (spec §5.3) */
    Route::get('me/complaints', [Dashboard\ComplaintController::class, 'index'])->name('pd.complaints.index');
    Route::get('me/complaints/{id}', [Dashboard\ComplaintController::class, 'show'])->name('pd.complaints.show');

    /* Profile */
    Route::get('me', [Dashboard\ProfileController::class, 'show'])->name('pd.me');
    Route::patch('me', [Dashboard\ProfileController::class, 'update'])->name('pd.me.update');
    Route::post('me/phone/request', [Dashboard\ProfileController::class, 'requestPhoneChange'])
        ->middleware('throttle:pd-otp')->name('pd.me.phone.request');
    Route::post('me/phone/verify', [Dashboard\ProfileController::class, 'verifyPhoneChange'])
        ->middleware('throttle:10,1')->name('pd.me.phone.verify');
    Route::get('me/company-docs', [Dashboard\ProfileController::class, 'companyDocs'])->name('pd.me.docs');
    Route::put('me/company-docs', [Dashboard\ProfileController::class, 'updateCompanyDocs'])->name('pd.me.docs.update');

    /* Overview */
    Route::get('overview', [Dashboard\OverviewController::class, 'show'])->name('pd.overview');

    /* Units */
    Route::get('units', [Dashboard\UnitController::class, 'index'])->name('pd.units.index');
    Route::post('units', [Dashboard\UnitController::class, 'store'])->name('pd.units.store');
    Route::get('units/{id}', [Dashboard\UnitController::class, 'show'])->name('pd.units.show');
    Route::patch('units/{id}', [Dashboard\UnitController::class, 'update'])->name('pd.units.update');
    Route::delete('units/{id}', [Dashboard\UnitController::class, 'destroy'])->name('pd.units.destroy');
    Route::post('units/{id}/submit', [Dashboard\UnitController::class, 'submit'])->name('pd.units.submit');

    /*
     * Multi-unit buildings. The logic shipped on 2026-08-30 but only on the
     * Bearer /api/v1 surface, so this dashboard — the one partners actually
     * use — had no way to reach it. `count` is a TOTAL, and it is the only
     * input shape here on purpose; door numbers and ranges stay on /api/v1
     * until a screen asks for them.
     */
    Route::post('units/{id}/apartments', [Dashboard\UnitController::class, 'apartments'])->name('pd.units.apartments');

    /* Calendar & availability */
    Route::get('units/{id}/calendar', [Dashboard\CalendarController::class, 'month'])->name('pd.calendar');
    Route::post('units/{id}/calendar/block', [Dashboard\CalendarController::class, 'block'])->name('pd.calendar.block');
    Route::post('units/{id}/calendar/unblock', [Dashboard\CalendarController::class, 'unblock'])->name('pd.calendar.unblock');

    /* iCal feeds */
    Route::get('units/{id}/ical', [Dashboard\IcalController::class, 'index'])->name('pd.ical.index');
    Route::post('units/{id}/ical', [Dashboard\IcalController::class, 'store'])->name('pd.ical.store');
    Route::delete('units/{id}/ical/{feedId}', [Dashboard\IcalController::class, 'destroy'])->name('pd.ical.destroy');
    Route::post('units/{id}/ical/{feedId}/sync', [Dashboard\IcalController::class, 'sync'])->name('pd.ical.sync');
    Route::get('units/{id}/ical/export', [Dashboard\IcalController::class, 'export'])->name('pd.ical.export');

    /* Bookings */
    Route::get('bookings', [Dashboard\BookingController::class, 'index'])->name('pd.bookings.index');
    Route::get('bookings/{id}', [Dashboard\BookingController::class, 'show'])->name('pd.bookings.show');
    Route::post('bookings/{id}/host-cancel', [Dashboard\BookingController::class, 'hostCancel'])->name('pd.bookings.host-cancel');

    /* Reports */
    Route::get('reports/summary', [Dashboard\ReportController::class, 'summary'])->name('pd.reports.summary');
    Route::get('reports/export', [Dashboard\ReportController::class, 'export'])->name('pd.reports.export');

    /* Notifications */
    Route::get('notifications', [Dashboard\NotificationController::class, 'index'])->name('pd.notifications.index');
    Route::get('notifications/unread-count', [Dashboard\NotificationController::class, 'unreadCount'])->name('pd.notifications.unread');
    Route::post('notifications/read-all', [Dashboard\NotificationController::class, 'readAll'])->name('pd.notifications.read-all');
    Route::post('notifications/{id}/read', [Dashboard\NotificationController::class, 'read'])->name('pd.notifications.read');

    /* Uploads */
    Route::post('uploads/presign', [Dashboard\UploadController::class, 'presign'])->name('pd.uploads.presign');

    /* ---- Wallet, payouts & bank details (wallet contract §1–6) ----
     * Real, database-backed. Replaced the non-prod fixture stubs. */
    Route::get('wallet', [Dashboard\WalletController::class, 'summary'])->name('pd.wallet');
    Route::get('wallet/ledger', [Dashboard\WalletController::class, 'ledger'])->name('pd.wallet.ledger');
    Route::get('payouts', [Dashboard\PayoutController::class, 'index'])->name('pd.payouts');
    Route::get('payouts/{id}', [Dashboard\PayoutController::class, 'show'])->name('pd.payouts.show');
    Route::get('me/bank-details', [Dashboard\BankDetailsController::class, 'show'])->name('pd.bank-details.show');
    Route::put('me/bank-details', [Dashboard\BankDetailsController::class, 'update'])->name('pd.bank-details.update');
});
