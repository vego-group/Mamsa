<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\BookingsController;
use App\Http\Controllers\UnitsController;
use App\Http\Controllers\ReportsController;

use App\Http\Controllers\Auth\OtpAuthController;
use App\Http\Controllers\Auth\CompleteProfileController;
use Illuminate\Http\Request;

use App\Http\Controllers\Partner\PartnerOnboardingController;
use App\Http\Controllers\Partner\PartnerUnitController;

use App\Http\Controllers\UnitDetailsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserBookingsController;

use App\Models\Unit;

/*
|--------------------------------------------------------------------------
| الصفحة الرئيسية
|--------------------------------------------------------------------------
*/
Route::get('/', function () {

    $units = Unit::with('images')
        ->where('status', 'available')
        ->latest()
        ->take(12)
        ->get();

    return view('home', compact('units'));

})->name('home');

/*
|--------------------------------------------------------------------------
| عرض الجميع + الفلترة
|--------------------------------------------------------------------------
*/
Route::get('/units/all', [UnitsController::class, 'all'])->name('units.all');
Route::get('/units/filter', [UnitsController::class, 'filter'])->name('units.filter');

/*
|--------------------------------------------------------------------------
| صفحة البروفايل
|--------------------------------------------------------------------------
*/
Route::get('/profile', function () {
    return view('user.profile');
})->middleware('auth')->name('user.profile');

Route::put('/profile/update', [UserController::class, 'updateProfile'])
    ->middleware('auth')
    ->name('user.update');



Route::get('/my-bookings', [UserBookingsController::class, 'index'])
    ->middleware('auth')
    ->name('user.bookings');

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
| إعادة توجيه بعد تسجيل الدخول
|--------------------------------------------------------------------------
*/
Route::get('/post-auth-redirect', function () {

    /** @var \App\Models\User $user */
    $user = Auth::user();

    if ($user && $user->isAdmin()) {

        // 🔥 أهم شيء: نضمن وجود السجل
        $details = $user->adminDetails;

        if (!$details) {
            $details = \App\Models\AdminDetail::create([
                'user_id' => $user->id,
                'verification_status' => 'pending',
            ]);
        }

        // ✅ 1. دايم أول خطوة → type
        if (empty($details->type)) {
            return redirect()->route('admin.type.form');
        }

        // ✅ 2. بعد type → الكل لازم تصريح
        if (empty($details->tourism_permit_no)) {
            return redirect()->route('admin.license.form');
        }

        // ✅ 3. فرد → لازم يضيف وحدة
        if ($details->type === 'individual') {
            if (!\App\Models\Unit::where('admin_detail_id', $details->id)->exists()) {
                return redirect()->route('admin.unit.create');
            }
        }

        // ✅ 4. الكل pending
        if ($details->verification_status !== 'approved') {
            return redirect()->route('admin.review');
        }

        // ✅ 5. بعد القبول
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route('user.profile');

})->middleware('auth')->name('post.auth.redirect');

/*
|--------------------------------------------------------------------------
| Dashboard عام
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', function () {

    /** @var \App\Models\User $user */
    $user = Auth::user();

    if ($user && $user->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route('user.profile');

})->middleware(['auth'])->name('dashboard');
/*
|--------------------------------------------------------------------------
| تسجيل الخروج
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
| نظام OTP
|--------------------------------------------------------------------------
*/
Route::get('/auth', [OtpAuthController::class, 'showPhoneForm'])->name('auth.phone');
Route::post('/auth/request', [OtpAuthController::class, 'requestCode'])->name('auth.otp.request');
Route::get('/auth/confirm', [OtpAuthController::class, 'showConfirmForm'])->name('auth.otp.confirm');
Route::post('/auth/verify', [OtpAuthController::class, 'verifyCode'])->name('auth.otp.verify');

Route::get('/email-verify', function(){
    return view('pages.Auth.email-verify');
})->name('auth.email.verify.form');

Route::post('/email-verify', function (Illuminate\Http\Request $request) {

    if ($request->code == session('email_verify_code')) {

        $user = \App\Models\User::find(session('email_verify_user'));

        if ($user) {
            $user->email_verified_at = now();
            $user->save();
        }

        return redirect()->route('post.auth.redirect');
    }

    return back()->withErrors(['code' => 'رمز التحقق غير صحيح']);

})->name('auth.email.verify.submit'); 
/*
|--------------------------------------------------------------------------
| إكمال الملف الشخصي
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/complete-profile', [CompleteProfileController::class, 'show'])
        ->name('auth.complete-profile');

    Route::post('/complete-profile', [CompleteProfileController::class, 'submit'])
        ->name('auth.complete-profile.submit');
});


/*
|--------------------------------------------------------------------------
| لوحة التحكم Admin
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
        Route::get('/type', [PartnerOnboardingController::class, 'typeForm'])->name('type.form');
        Route::post('/type', [PartnerOnboardingController::class, 'typeStore'])->name('type.store');

        Route::get('/license', [PartnerUnitController::class, 'licenseForm'])->name('license.form');
        Route::post('/license', [PartnerUnitController::class, 'licenseStore'])->name('license.store');

        Route::get('/unit', [PartnerUnitController::class, 'create'])->name('unit.create');
        Route::post('/unit', [PartnerUnitController::class, 'store'])->name('unit.store');

        Route::get('/review', [PartnerUnitController::class, 'review'])->name('review');
        // المستخدمين (سوبر فقط)
        Route::middleware([\App\Http\Middleware\RoleMiddleware::class . ':Super Admin'])
            ->group(function () {

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

        /*
        |--------------------------------------------------------------------------
        | التقارير Reports
        |--------------------------------------------------------------------------
        */
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');

        Route::get('/reports/export/bookings.csv',  [ReportsController::class, 'exportBookingsCsv'])
            ->name('reports.export.bookings.csv');

        Route::get('/reports/export/bookings.excel', [ReportsController::class, 'exportBookingsExcel'])
            ->name('reports.export.bookings.excel');

        Route::get('/reports/export/bookings.pdf', [ReportsController::class, 'exportBookingsPdf'])
            ->name('reports.export.bookings.pdf');

        Route::get('/reports/export/summary.csv', [ReportsController::class, 'exportSummaryCsv'])
            ->name('reports.export.summary.csv');

        Route::get('/reports/export/summary.excel', [ReportsController::class, 'exportSummaryExcel'])
            ->name('reports.export.summary.excel');

        Route::get('/reports/export/summary.pdf', [ReportsController::class, 'exportSummaryPdf'])
            ->name('reports.export.summary.pdf');
    });

/*
|--------------------------------------------------------------------------
| صفحة تفاصيل الوحدة
|--------------------------------------------------------------------------
*/
Route::get('/units/{unit}', [UnitDetailsController::class, 'show'])
    ->name('units.details');

/*
|--------------------------------------------------------------------------
| Calendar ICS
|--------------------------------------------------------------------------
*/
Route::get('/calendar/unit/{unit}/{token}.ics', [UnitsController::class, 'calendarIcs'])
    ->name('units.calendar.ics');


require __DIR__ . '/auth.php';