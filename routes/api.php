<?php

use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\BookingController; // show + cancel only; creation is via PaymentController::initiate
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([ForceJsonResponse::class])->group(function () {

    /* ========== AUTH (public) ========== */
    Route::prefix('auth')->name('api.auth.')->group(function () {
        Route::post('request-otp', [OtpAuthController::class, 'requestOtp'])->name('request-otp')
            ->middleware('throttle:5,1');

        Route::post('verify-otp', [OtpAuthController::class, 'verifyOtp'])->name('verify-otp')
            ->middleware('throttle:10,1');

        Route::post('resend-otp', [OtpAuthController::class, 'resendOtp'])->name('resend-otp')
            ->middleware('throttle:3,1');
    });

    /* ========== UNITS (public) ========== */
    Route::prefix('units')->name('api.units.')->group(function () {
        Route::get('/', [UnitController::class, 'index'])->name('index');
        Route::get('{unit}', [UnitController::class, 'show'])->name('show');
        Route::get('{unit}/availability', [UnitController::class, 'checkAvailability'])->name('availability');
    });

    /* ========== AUTHENTICATED ROUTES ========== */
    Route::middleware('auth:sanctum')->group(function () {

        /* --- Auth --- */
        Route::prefix('auth')->name('api.auth.')->group(function () {
            Route::get('me', [OtpAuthController::class, 'me'])->name('me');
            Route::post('complete-profile', [OtpAuthController::class, 'completeProfile'])->name('complete-profile');
            Route::post('logout', [OtpAuthController::class, 'logout'])->name('logout');
        });

        /* --- User --- */
        Route::prefix('user')->name('api.user.')->group(function () {
            Route::get('profile', [UserController::class, 'profile'])->name('profile');
            Route::put('profile', [UserController::class, 'updateProfile'])->name('update-profile');
            Route::get('bookings', [UserController::class, 'bookings'])->name('bookings');
        });

        /* --- Bookings --- */
        Route::prefix('bookings')->name('api.bookings.')->group(function () {
            Route::get('{booking}', [BookingController::class, 'show'])->name('show');
            Route::post('{booking}/cancel', [BookingController::class, 'cancel'])->name('cancel');
        });

        /* --- Payments --- */
        Route::prefix('payments')->name('api.payments.')->group(function () {
            Route::post('initiate', [PaymentController::class, 'initiate'])->name('initiate');
            Route::get('{payment}', [PaymentController::class, 'show'])->name('show');
        });
    });

    /* ========== PAYMENT CALLBACK (Moyasar webhook — no auth, verified by re-fetch) ========== */
    Route::post('payments/callback', [PaymentController::class, 'callback'])
        ->name('api.payments.callback')
        ->middleware(ForceJsonResponse::class);
});
