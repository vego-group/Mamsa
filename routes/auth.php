<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\VerifyEmailController;

/*
|--------------------------------------------------------------------------
| Email Verification Notice (يتطلب تسجيل دخول)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get('/verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::post('/email/verification-notification',
        [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

/*
|--------------------------------------------------------------------------
| Email Verification Link (يعمل من أي جهاز)
|--------------------------------------------------------------------------
*/

Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');