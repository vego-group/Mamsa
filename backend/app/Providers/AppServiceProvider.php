<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sanctum access tokens expire after the configured access-token lifetime;
        // longer-lived sessions are maintained via custom refresh tokens.
        config(['sanctum.expiration' => (int) config('tokens.access_minutes', 60)]);

        // Partner-dashboard OTP sends: 3 per phone per 10 minutes (contract
        // §0.6); the per-day caps live in OtpService::enforceDailyCaps().
        RateLimiter::for('pd-otp', function (Request $request) {
            $phone = preg_replace('/\D+/', '', (string) ($request->input('phone') ?? $request->input('newPhone') ?? ''));

            return Limit::perMinutes(10, 3)->by('pd-otp:'.($phone ?: $request->ip()));
        });
    }
}
