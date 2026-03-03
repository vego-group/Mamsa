<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\Auth\OtpAuthController;
use App\Http\Controllers\Auth\CompleteProfileController;
use App\Http\Controllers\Partner\PartnerUnitController;
use App\Http\Controllers\Partner\PartnerOnboardingController;

/*
|--------------------------------------------------------------------------
| Preview Pages
|--------------------------------------------------------------------------
*/

Route::get('/preview-type', function () {
    return view('pages.partner.type');
});

Route::get('/preview-permit', function () {
    return view('pages.partner.permit');
});

Route::get('/preview-unit', function () {
    return view('pages.partner.unit');
});

/*
|--------------------------------------------------------------------------
| Test Email
|--------------------------------------------------------------------------
*/

Route::get('/test-email', function () {
    Mail::raw('هذا اختبار ارسال من موقع مامسا ✨', function ($message) {
        $message->to('saja_uu7788@hotmail.com')
                ->subject('Test Email from Mamsa');
    });

    return 'Email Sent ✅';
});

/*
|--------------------------------------------------------------------------
| Home
|--------------------------------------------------------------------------
*/

Route::get('/', function () {

    if (!auth()->check()) {
        return redirect()->route('auth.phone');
    }

    if (auth()->user()->isPartner()) {

        $profile = auth()->user()->partner;

        if (!$profile || empty($profile->type)) {
            return redirect()->route('partner.type.form');
        }

        return redirect()->route('partner.dashboard');
    }

    return redirect()->route('auth.phone');

})->name('home');

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
*/

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('auth.phone');
})->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| OTP FLOW
|--------------------------------------------------------------------------
*/

Route::get('/auth', [OtpAuthController::class,'showPhoneForm'])->name('auth.phone');
Route::post('/auth/request', [OtpAuthController::class,'requestCode'])->name('auth.otp.request');
Route::get('/auth/confirm', [OtpAuthController::class,'showConfirmForm'])->name('auth.otp.confirm');
Route::post('/auth/verify', [OtpAuthController::class,'verifyCode'])->name('auth.otp.verify');

/*
|--------------------------------------------------------------------------
| Complete Profile
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get('/complete-profile', [CompleteProfileController::class,'show'])
        ->name('auth.complete-profile');

    Route::post('/complete-profile', [CompleteProfileController::class,'submit'])
        ->name('auth.complete-profile.submit');
});

/*
|--------------------------------------------------------------------------
| Partner Area
|--------------------------------------------------------------------------
*/

Route::prefix('partner')
    ->name('partner.')
    ->middleware(['auth'])
    ->group(function () {

        Route::get('/type', [PartnerOnboardingController::class, 'typeForm'])
            ->name('type.form');

        Route::post('/type', [PartnerOnboardingController::class, 'typeStore'])
            ->name('type.store');

        Route::get('/dashboard', [PartnerOnboardingController::class, 'dashboard'])
            ->name('dashboard');

        Route::get('/license', [PartnerUnitController::class,'licenseForm'])
            ->name('license.form');

        Route::post('/license', [PartnerUnitController::class,'licenseStore'])
            ->name('license.store');

        Route::get('/unit', [PartnerUnitController::class,'create'])
            ->name('unit.create');

        Route::post('/unit', [PartnerUnitController::class,'store'])
            ->name('unit.store');

        Route::get('/review', [PartnerUnitController::class,'review'])
            ->name('review');
});

/*
|--------------------------------------------------------------------------
| Email Verification Routes
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';