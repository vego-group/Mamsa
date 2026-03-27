<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// Controllers
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\BookingsController;
use App\Http\Controllers\UnitsController;
use App\Http\Controllers\ReportsController;

use App\Http\Controllers\Auth\OtpAuthController;
use App\Http\Controllers\Auth\CompleteProfileController;

use App\Http\Controllers\Partner\PartnerOnboardingController;
use App\Http\Controllers\Partner\PartnerUnitController;

use App\Http\Controllers\UnitDetailsController;
use App\Http\Controllers\UserController;

use App\Models\Unit;

/*
|--------------------------------------------------------------------------
| الصفحة الرئيسية (Homepage)
|--------------------------------------------------------------------------
*/
Route::get('/', function () {

    // ✔ جلب الوحدات (من قاعدة بياناتك أنت)
    $units = Unit::with('images')
        ->where('status', 'available')   // الوحدات المتاحة فقط
        ->latest()
        ->take(12)
        ->get();

    return view('home', compact('units'));

})->name('home');
 Route::get('/units/filter', [UnitsController::class, 'filter'])->name('units.filter'); 
 Route::get('/profile', function () {
    return view('user.profile');
})->middleware('auth')->name('user.profile');
Route::put('/profile/update', [UserController::class, 'updateProfile'])
     ->middleware('auth')
     ->name('user.update');
/*
|--------------------------------------------------------------------------
| تسجيل الدخول
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

/*
|--------------------------------------------------------------------------
| توجيه بعد تسجيل الدخول
|--------------------------------------------------------------------------
*/
Route::get('/post-auth-redirect', function () {

    /** @var \App\Models\User|null $user */
    $user = Auth::user();

    if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }

    if ($user && method_exists($user, 'isPartner') && $user->isPartner()) {
        return redirect()->route('partner.type.form');
    }

    return redirect()->route('user.profile');

})->middleware('auth')->name('post.auth.redirect');

/*
|--------------------------------------------------------------------------
| Dashboard عام
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', function () {

    $user = Auth::user();

    // لو المستخدم أدمن → يروح للوحة التحكم
    if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }

    // لو مستخدم عادي → يروح لصفحته
    return redirect()->route('user.profile');

})->middleware(['auth', 'verified'])->name('dashboard');

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
| تدفّق OTP
|--------------------------------------------------------------------------
*/
Route::get('/auth', [OtpAuthController::class, 'showPhoneForm'])->name('auth.phone');
Route::post('/auth/request', [OtpAuthController::class, 'requestCode'])->name('auth.otp.request');
Route::get('/auth/confirm', [OtpAuthController::class, 'showConfirmForm'])->name('auth.otp.confirm');
Route::post('/auth/verify', [OtpAuthController::class, 'verifyCode'])->name('auth.otp.verify');

/*
|--------------------------------------------------------------------------
| إكمال الملف الشخصي
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/complete-profile', [CompleteProfileController::class, 'show'])->name('auth.complete-profile');
    Route::post('/complete-profile', [CompleteProfileController::class, 'submit'])->name('auth.complete-profile.submit');
});

/*
|--------------------------------------------------------------------------
| منطقة الشريك Partner
|--------------------------------------------------------------------------
*/
Route::prefix('partner')
    ->name('partner.')
    ->middleware([
        'auth',
        'verified',
        \App\Http\Middleware\RoleMiddleware::class . ':Partner',
    ])
    ->group(function () {

        Route::get('/type', [PartnerOnboardingController::class, 'typeForm'])->name('type.form');
        Route::post('/type', [PartnerOnboardingController::class, 'typeStore'])->name('type.store');

        Route::get('/dashboard', [PartnerOnboardingController::class, 'dashboard'])->name('dashboard');

        Route::get('/license', [PartnerUnitController::class, 'licenseForm'])->name('license.form');
        Route::post('/license', [PartnerUnitController::class, 'licenseStore'])->name('license.store');

        Route::get('/unit', [PartnerUnitController::class, 'create'])->name('unit.create');
        Route::post('/unit', [PartnerUnitController::class, 'store'])->name('unit.store');

        Route::get('/review', [PartnerUnitController::class, 'review'])->name('review');
    });

/*
|--------------------------------------------------------------------------
| منطقة الأدمن Admin
|--------------------------------------------------------------------------
*/
Route::prefix('admin')
    ->middleware([
        'auth',
        \App\Http\Middleware\RoleMiddleware::class . ':Super Admin,Admin',
    ])
    ->name('admin.')
    ->group(function () {

        // Dashboard
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        // إدارة المستخدمين (Super admin فقط)
        Route::middleware([\App\Http\Middleware\RoleMiddleware::class . ':Super Admin'])->group(function () {
            Route::get('/users', [AdminUsersController::class, 'index'])->name('users.index');
            Route::get('/users/create', [AdminUsersController::class, 'create'])->name('users.create');
            Route::post('/users', [AdminUsersController::class, 'store'])->name('users.store');
            Route::post('/users/{id}/status', [AdminUsersController::class, 'status'])->name('users.status');
            Route::delete('/users/{id}/delete', [AdminUsersController::class, 'delete'])->name('users.delete');
        });

        // الوحدات
        Route::get('/units', [UnitsController::class, 'index'])->name('units.index');
        Route::get('/units/create', [UnitsController::class, 'create'])->name('units.create');
        Route::post('/units', [UnitsController::class, 'store'])->name('units.store');
        Route::get('/units/{unit}/edit', [UnitsController::class, 'edit'])->name('units.edit');
        Route::put('/units/{unit}', [UnitsController::class, 'update'])->name('units.update');
        Route::delete('/units/{unit}', [UnitsController::class, 'destroy'])->name('units.destroy');

        Route::put('/units/{unit}/calendar/rotate', [UnitsController::class, 'rotateCalendarToken'])
            ->name('units.calendar.rotate');

        // الحجوزات
        Route::get('/bookings', [BookingsController::class, 'index'])->name('bookings.index');
        Route::post('/bookings', [BookingsController::class, 'store'])->name('bookings.store');
        Route::put('/bookings/{booking}', [BookingsController::class, 'update'])->name('bookings.update');
        Route::delete('/bookings/{booking}', [BookingsController::class, 'destroy'])->name('bookings.destroy');

        // التقارير
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    });

/*
|--------------------------------------------------------------------------
| صفحة تفاصيل الوحدة
|--------------------------------------------------------------------------
*/
Route::get('/units/{unit}', [UnitDetailsController::class, 'show'])->name('units.details');

/*
|--------------------------------------------------------------------------
| مسار التقويم العام (ICS)
|--------------------------------------------------------------------------
*/
Route::get('/calendar/unit/{unit}/{token}.ics', [UnitsController::class, 'calendarIcs'])
    ->name('units.calendar.ics');

/*
|--------------------------------------------------------------------------
| Auth Scaffolding
|--------------------------------------------------------------------------
*/
require __DIR__ . '/auth.php';