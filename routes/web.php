<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

use App\Models\Unit;

use App\Http\Controllers\Auth\OtpAuthController;
use App\Http\Controllers\Auth\CompleteProfileController;
use App\Http\Controllers\Auth\VerifyEmailController;

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\BookingsController;
use App\Http\Controllers\UnitsController;
use App\Http\Controllers\ReportsController;

use App\Http\Controllers\UnitDetailsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserBookingsController;
use App\Http\Controllers\AdminRequestsController;
use App\Http\Controllers\CheckoutController;


/* ================= HOME ================= */
    Route::get('/', function () {

    if (auth()->check() && auth()->user()->isAdmin()) {
        return redirect()->route('Admin.dashboard');
    }

    $units = Unit::with('images')
        ->where('status', 'available')
        ->where('approval_status', 'approved')
        ->latest()
        ->take(12)
        ->get();

    return view('home', compact('units'));

})->name('home');

/* ================= UNITS ================= */
Route::get('/units/all', [UnitsController::class, 'all'])->name('units.all');
Route::get('/units/filter', [UnitsController::class, 'filter'])->name('units.filter');

Route::get('/units/{unit}', [UnitDetailsController::class, 'show'])
    ->name('units.details');


/* ================= CHECKOUT ================= */
Route::get('/checkout', function(Request $request){

    if(!auth()->check()){
        session(['url.intended' => url()->full()]);
        return redirect()->route('login');
    }

    return app(CheckoutController::class)->index($request);

})->name('checkout');


/* ================= PAYMENT SUCCESS ================= */
Route::get('/payment/success', function(Request $request){

    \App\Models\Booking::create([
        'unit_id' => $request->unit,
        'user_id' => auth()->id(),
        'status' => 'confirmed',
        'start_date' => $request->checkin,
        'end_date' => $request->checkout,
        'total_amount' => $request->total,
    ]);

    return view('Payment.redirect');

})->middleware('auth')->name('payment.success');


/* ================= USER ================= */
Route::middleware('auth')->group(function () {

    Route::get('/profile', fn() => view('user.profile'))->name('user.profile');
    Route::put('/profile/update', [UserController::class, 'updateProfile'])->name('user.update');
    Route::get('/my-bookings', [UserBookingsController::class, 'index'])->name('user.bookings');
});


/* ================= OTP LOGIN ================= */
Route::get('/auth', [OtpAuthController::class, 'showPhoneForm'])->name('auth.phone');
Route::middleware('guest')->group(function () {

    Route::get('/login', [OtpAuthController::class, 'showPhoneForm'])->name('login');
    Route::post('/login', [OtpAuthController::class, 'requestCode'])->name('auth.otp.request');

    Route::get('/auth/confirm', [OtpAuthController::class, 'showConfirmForm'])->name('auth.otp.confirm');
    Route::post('/auth/verify', [OtpAuthController::class, 'verifyCode'])->name('auth.otp.verify');

    Route::post('/otp/resend', [OtpAuthController::class, 'resend'])->name('auth.otp.resend');
});


/* ================= EMAIL VERIFY ================= */
Route::middleware('auth')->group(function () {

    Route::get('/email/verify', [VerifyEmailController::class, 'show'])->name('auth.email.verify');
    Route::post('/email/verify', [VerifyEmailController::class, 'submit'])->name('auth.email.verify.submit');
    Route::post('/email/resend', [VerifyEmailController::class, 'resend'])->name('auth.email.resend');
});


/* ================= COMPLETE PROFILE ================= */
Route::middleware('auth')->group(function () {

    Route::get('/complete-profile', [CompleteProfileController::class, 'show'])
        ->name('auth.complete-profile');

    Route::post('/complete-profile', [CompleteProfileController::class, 'submit'])
        ->name('auth.complete-profile.submit');
});


/* ================= REDIRECT ================= */
Route::get('/post-auth-redirect', function () {

    $user = Auth::user();

    if ($user && $user->isAdmin()
) {
        return redirect()->route('Admin.dashboard');
    }

    return redirect()->route('user.profile');

})->middleware('auth')->name('post.auth.redirect');


/* ================= LOGOUT ================= */
Route::post('/logout', function () {

    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('home');

})->middleware('auth')->name('logout');
/* ================= STATIC PAGES ================= */
Route::view('/terms', 'pages.terms')->name('terms');
Route::view('/about', 'pages.about')->name('about');
Route::view('/how-it-works', 'pages.how-it-works')->name('howItWorks');
Route::view('/contact', 'pages.contact')->name('contact');
/* ================= ADMIN ================= */
Route::prefix('Admin')
    ->middleware(['auth', \App\Http\Middleware\RoleMiddleware::class . ':SuperAdmin,Admin'])
    ->name('Admin.')
    ->group(function () {
         Route::get('/requests', [AdminRequestsController::class, 'index'])
        ->name('requests.index');
        Route::get('/requests/{unit}', [AdminRequestsController::class, 'show'])->name('requests.show');

                Route::post('/requests/{unit}/approve', [AdminRequestsController::class, 'approve'])->name('requests.approve');

                Route::post('/requests/{unit}/reject', [AdminRequestsController::class, 'reject'])->name('requests.reject');

    Route::get('/', [AdminDashboardController::class, 'index'])
    ->name('dashboard');

Route::get('/users', [AdminUsersController::class, 'index'])
    ->name('users.index');

Route::get('/units', [UnitsController::class, 'index'])
    ->name('units.index');

Route::get('/bookings', [BookingsController::class, 'index'])
    ->name('bookings.index');

Route::get('/account', fn () => view('Admin.account.index'))
    ->name('account.index');
       Route::get('/reports', [ReportsController::class, 'index'])
    ->name('reports.index');

Route::get('/reports/export/bookings.csv', [ReportsController::class, 'exportBookingsCsv'])
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
     Route::get('/units', [UnitsController::class, 'index'])->name('units.index');
        Route::get('/units/create', [UnitsController::class, 'create'])->name('units.create');
        Route::post('/units', [UnitsController::class, 'store'])->name('units.store');
        Route::get('/users/create', [AdminUsersController::class, 'create'])
    ->name('users.create');
Route::post('/users/{id}/status', [AdminUsersController::class, 'status'])
    ->name('users.status');
    Route::delete('/users/{id}', [AdminUsersController::class, 'delete'])
    ->name('users.delete');

Route::post('/users', [AdminUsersController::class, 'store'])
    ->name('users.store');
        Route::get('/units/{unit}/edit', [UnitsController::class, 'edit'])->name('units.edit');
        Route::put('/units/{unit}', [UnitsController::class, 'update'])->name('units.update');
        Route::delete('/units/{unit}', [UnitsController::class, 'destroy'])->name('units.destroy');
    });


require __DIR__ . '/auth.php';