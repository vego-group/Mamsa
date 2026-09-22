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

        // A finished stay credits the partner's wallet (wallet contract §5).
        \App\Models\Booking::observe(\App\Observers\BookingEarningObserver::class);

        // Partner-dashboard OTP sends: 3 per phone per 10 minutes (contract
        // §0.6); the per-day caps live in OtpService::enforceDailyCaps().
        RateLimiter::for('pd-otp', function (Request $request) {
            return Limit::perMinutes(10, 3)->by('pd-otp:'.self::otpKey($request, 'newPhone'));
        });

        // Admin-panel OTP sends: 3 per phone per 10 minutes (BACKEND_SPEC §3);
        // the per-day caps live in OtpService::enforceDailyCaps().
        RateLimiter::for('ap-otp', function (Request $request) {
            return Limit::perMinutes(10, 3)->by('ap-otp:'.self::otpKey($request));
        });
    }

    /**
     * One bucket per PHONE, whatever format it arrived in.
     *
     * This keyed on digits-only, so `+966555000003`, `0555000003` and
     * `555000003` produced three DIFFERENT buckets for one person — 9 sends per
     * 10 minutes instead of 3, by doing nothing more than varying the format.
     * Normalising to E.164 first collapses them onto one key.
     *
     * The IP fallback only ever catches requests carrying no usable phone at
     * all (a malformed body). A well-formed request always keys on its own
     * phone, so admins sharing an office NAT never share a bucket.
     */
    private static function otpKey(Request $request, ?string $altField = null): string
    {
        $raw = (string) ($request->input('phone') ?? ($altField ? $request->input($altField) : null) ?? '');

        try {
            $phone = trim($raw) !== '' ? \App\Support\PhoneNumber::toE164Ksa($raw) : '';
        } catch (\Throwable) {
            $phone = preg_replace('/\D+/', '', $raw) ?: '';
        }

        return $phone !== '' ? $phone : (string) $request->ip();
    }
}
