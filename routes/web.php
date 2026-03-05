<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\CityController;
use App\Http\Controllers\Auth\OtpAuthController;
use App\Http\Controllers\Auth\CompleteProfileController;
use App\Http\Controllers\Partner\PartnerUnitController;
use App\Http\Controllers\Partner\PartnerOnboardingController;

/*
|--------------------------------------------------------------------------
| Test Email (يفضل محلي فقط)
|--------------------------------------------------------------------------
*/
if (app()->environment('local')) {
    Route::get('/test-email', function () {
        Mail::raw('هذا اختبار ارسال من موقع مامسا ✨', function ($message) {
            $message->to('saja_uu7788@hotmail.com')
                    ->subject('Test Email from Mamsa');
        });

        return 'Email Sent ✅';
    });
}

/*
|--------------------------------------------------------------------------
| Home (الصفحة الرئيسية من شغلك)
|--------------------------------------------------------------------------
| خيار A (مُوصى به هنا): عبر كنترولر يجلب المدن
*/

// ❌ هذا يقرأ من DB
// Route::get('/', [CityController::class, 'index'])->name('home');

// ✅ خليه يفتح الصفحة مباشرة بدون DB
Route::view('/', 'home')->name('home');

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
*/
Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('home');
})->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| OTP FLOW (شغل زميلتك)
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
    Route::get('/complete-profile', [CompleteProfileController::class,'show'])->name('auth.complete-profile');
    Route::post('/complete-profile', [CompleteProfileController::class,'submit'])->name('auth.complete-profile.submit');
});

/*
|--------------------------------------------------------------------------
| Partner Area
|--------------------------------------------------------------------------
*/
Route::prefix('partner')
    ->name('partner.')
    ->middleware(['auth','verified'])
    ->group(function () {

        // 1) اختيار النوع (فرد/شركة) - بعد تحقق الإيميل
        Route::get('/type', [PartnerOnboardingController::class, 'typeForm'])->name('type.form');
        Route::post('/type', [PartnerOnboardingController::class, 'typeStore'])->name('type.store');

        // 2) داشبورد الشريك
        Route::get('/dashboard', [PartnerOnboardingController::class, 'dashboard'])->name('dashboard');

        // 3) التصريح
        Route::get('/license', [PartnerUnitController::class,'licenseForm'])->name('license.form');
        Route::post('/license', [PartnerUnitController::class,'licenseStore'])->name('license.store');

        // 4) إضافة وحدة
        Route::get('/unit', [PartnerUnitController::class,'create'])->name('unit.create');
        Route::post('/unit', [PartnerUnitController::class,'store'])->name('unit.store');

        // 5) صفحة انتظار المراجعة
        Route::get('/review', [PartnerUnitController::class,'review'])->name('review');
    });

require __DIR__.'/auth.php';