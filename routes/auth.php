<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\VerifyEmailController;

/*
|--------------------------------------------------------------------------
| Email Verification (OTP System)
|--------------------------------------------------------------------------
*/

// عرض صفحة إدخال الكود
Route::get('/email/verify', [VerifyEmailController::class, 'show'])
    ->middleware('auth')
    ->name('auth.email.verify.form');

// إرسال الكود والتحقق
Route::post('/email/verify', [VerifyEmailController::class, 'submit'])
    ->middleware('auth')
    ->name('auth.email.verify.submit');
