<?php

use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Auth\PartnerAuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\TestimonialController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\Partner;
use App\Http\Controllers\Api\V1\Admin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    /* ===================== AUTH (public) ===================== */
    Route::prefix('auth')->name('api.auth.')->group(function () {
        // Back-office (Admin / SuperAdmin) email + password login
        Route::post('admin/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:5,1')->name('admin.login');

        // Self-service partner onboarding (OTP-verified)
        Route::post('partner/register', [PartnerAuthController::class, 'register'])
            ->middleware('throttle:5,1')->name('partner.register');

        Route::post('request-otp', [OtpAuthController::class, 'requestOtp'])
            ->middleware('throttle:5,1')->name('request-otp');

        Route::post('verify-otp', [OtpAuthController::class, 'verifyOtp'])
            ->middleware('throttle:10,1')->name('verify-otp');

        Route::post('resend-otp', [OtpAuthController::class, 'resendOtp'])
            ->middleware('throttle:3,1')->name('resend-otp');

        Route::post('refresh', [OtpAuthController::class, 'refresh'])
            ->middleware('throttle:10,1')->name('refresh');
    });

    /* ===================== PUBLIC ===================== */
    Route::prefix('units')->name('api.units.')->group(function () {
        Route::get('/', [UnitController::class, 'index'])->name('index');
        Route::get('popular', [UnitController::class, 'popular'])->name('popular');
        Route::get('categories', [UnitController::class, 'categories'])->name('categories');
        Route::get('cities', [UnitController::class, 'cities'])->name('cities');
        Route::get('budgets', [UnitController::class, 'budgets'])->name('budgets');
        Route::get('{unit}', [UnitController::class, 'show'])->name('show');
        Route::get('{unit}/reviews', [UnitController::class, 'reviews'])->name('reviews');
        Route::post('{unit}/availability', [UnitController::class, 'checkAvailability'])->name('availability');
    });

    Route::get('offers', [OfferController::class, 'index'])->name('api.offers.index');
    Route::get('testimonials', [TestimonialController::class, 'index'])->name('api.testimonials.index');

    // §9 — public contact form
    Route::post('contact', [ContactController::class, 'store'])
        ->middleware('throttle:5,1')->name('api.contact.store');

    /* ===================== AUTHENTICATED ===================== */
    Route::middleware('auth:sanctum')->group(function () {

        /* Auth utilities */
        Route::prefix('auth')->name('api.auth.')->group(function () {
            Route::get('me', [OtpAuthController::class, 'me'])->name('me');
            Route::post('complete-profile', [OtpAuthController::class, 'completeProfile'])->name('complete-profile');
            Route::post('logout', [OtpAuthController::class, 'logout'])->name('logout');

            // FR-005 / FR-006 — partner email verification
            Route::post('email/request-otp', [EmailVerificationController::class, 'send'])
                ->middleware('throttle:5,1')->name('email.request');
            Route::post('email/verify', [EmailVerificationController::class, 'verify'])
                ->middleware('throttle:10,1')->name('email.verify');
        });

        /* User (guest role) */
        Route::prefix('user')->name('api.user.')->group(function () {
            Route::get('profile', [UserController::class, 'profile'])->name('profile');
            Route::put('profile', [UserController::class, 'updateProfile'])->name('profile.update');
            Route::get('bookings', [UserController::class, 'bookings'])->name('bookings');

            // §7.2 / §7.3 — account management
            Route::post('change-phone', [UserController::class, 'changePhone'])
                ->middleware('throttle:5,1')->name('change-phone');
            Route::post('change-phone/verify', [UserController::class, 'verifyChangePhone'])
                ->middleware('throttle:10,1')->name('change-phone.verify');
            Route::delete('account', [UserController::class, 'deleteAccount'])->name('account.delete');
        });

        /* Bookings */
        Route::prefix('bookings')->name('api.bookings.')->group(function () {
            Route::post('/', [BookingController::class, 'store'])->name('store');
            Route::get('{booking}', [BookingController::class, 'show'])->name('show');
            Route::get('{booking}/cancellation-preview', [BookingController::class, 'cancellationPreview'])->name('cancellation-preview');
            Route::post('{booking}/cancel', [BookingController::class, 'cancel'])->name('cancel');
        });

        /* Payments — throttled to blunt card-testing / abuse of the charge path */
        Route::prefix('payments')->name('api.payments.')->middleware('throttle:20,1')->group(function () {
            Route::post('initiate', [PaymentController::class, 'initiate'])->name('initiate');
            Route::post('pay', [PaymentController::class, 'pay'])->name('pay');
            Route::post('verify', [PaymentController::class, 'verify'])->name('verify');
            Route::post('apple-pay/validate-merchant', [PaymentController::class, 'applePayValidateMerchant'])->name('apple-pay.validate');
            Route::get('{payment}', [PaymentController::class, 'show'])->name('show');
        });

        /* Reviews */
        Route::post('reviews', [ReviewController::class, 'store'])->name('api.reviews.store');

        /* =============== PARTNER =============== */
        Route::prefix('partner')->name('api.partner.')->middleware('role:Individual|Company')->group(function () {
            Route::get('dashboard', [Partner\DashboardController::class, 'index'])->name('dashboard');
            Route::get('profile', [Partner\ProfileController::class, 'show'])->name('profile');
            Route::put('profile', [Partner\ProfileController::class, 'update'])->name('profile.update');

            Route::prefix('units')->name('units.')->group(function () {
                Route::get('/', [Partner\UnitController::class, 'index'])->name('index');
                Route::post('/', [Partner\UnitController::class, 'store'])->name('store');
                Route::get('{unit}', [Partner\UnitController::class, 'show'])->name('show');
                Route::put('{unit}', [Partner\UnitController::class, 'update'])->name('update');
                Route::delete('{unit}', [Partner\UnitController::class, 'destroy'])->name('destroy');
                Route::post('{unit}/submit', [Partner\UnitController::class, 'submit'])->name('submit');
            });

            Route::get('bookings', [Partner\BookingController::class, 'index'])->name('bookings.index');

            Route::prefix('notifications')->name('notifications.')->group(function () {
                Route::get('/', [NotificationController::class, 'index'])->name('index');
                Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread');
                Route::post('read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
                Route::post('{id}/read', [NotificationController::class, 'markAsRead'])->name('read');
            });
        });

        /* =============== ADMIN =============== */
        Route::prefix('admin')->name('api.admin.')->middleware('role:Admin|SuperAdmin')->group(function () {
            Route::get('dashboard', [Admin\DashboardController::class, 'index'])->name('dashboard');

            Route::prefix('users')->name('users.')->group(function () {
                Route::get('/', [Admin\UserController::class, 'index'])->name('index');
                Route::post('/', [Admin\UserController::class, 'store'])->name('store');
                Route::patch('{user}/status', [Admin\UserController::class, 'updateStatus'])->name('status');
                Route::delete('{user}', [Admin\UserController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('requests')->name('requests.')->group(function () {
                Route::get('/', [Admin\RequestController::class, 'index'])->name('index');
                Route::get('{unit}', [Admin\RequestController::class, 'show'])->name('show');
                Route::post('{unit}/approve', [Admin\RequestController::class, 'approve'])->name('approve');
                Route::post('{unit}/reject', [Admin\RequestController::class, 'reject'])->name('reject');
            });

            Route::get('units', [Admin\UnitController::class, 'index'])->name('units.index');
            Route::get('bookings', [Admin\BookingController::class, 'index'])->name('bookings.index');
            Route::get('reports', [Admin\ReportController::class, 'index'])->name('reports');

            Route::prefix('notifications')->name('notifications.')->group(function () {
                Route::get('/', [NotificationController::class, 'index'])->name('index');
                Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread');
                Route::post('read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
                Route::post('{id}/read', [NotificationController::class, 'markAsRead'])->name('read');
            });
        });
    });

    /* ===================== PAYMENT WEBHOOK (no auth) ===================== */
    Route::post('payments/callback', [PaymentController::class, 'callback'])
        ->name('api.payments.callback');
});
