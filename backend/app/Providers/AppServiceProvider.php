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
    /**
     * A host marked as production must never run with debug enabled.
     *
     * The marker is a file OUTSIDE the application directory and outside git,
     * so nothing in `.env`, no deploy, and no environment switch can clear it.
     * That placement is the whole design: on 2026-06-30 an env-switching script
     * put the production box into a `local` profile with APP_DEBUG=true, and it
     * stayed that way for nearly five days. Every guard that lives inside the
     * environment system is turned off by the same act that creates the danger.
     *
     * Debug mode on a production host is not a degraded service — Laravel's
     * exception page renders the loaded environment, so one unhandled error
     * shows the database password, the app key and every service secret to
     * whoever triggered it. Refusing to boot is the correct response: a site
     * that is down is recoverable in a minute, a leaked credential set is not.
     */
    private function refuseDebugOnAProductionHost(): void
    {
        // Deliberately not configurable. A path read from config or env could
        // be pointed at a file that does not exist, which would disable the
        // guard by the same mechanism it exists to survive.
        $marker = dirname(base_path()).'/.mamsa-production';

        if (! is_file($marker) || ! config('app.debug')) {
            return;
        }

        throw new \RuntimeException(
            'Refusing to boot: this host is marked production ('.$marker.') but APP_DEBUG is true. '
            .'Laravel would render the loaded environment — database password, app key, service '
            .'secrets — on the first unhandled exception. Set APP_DEBUG=false and rebuild the '
            .'config cache, or remove the marker if this host is genuinely not production.'
        );
    }

    public function boot(): void
    {
        // Runs first: nothing below it should get the chance to serve traffic
        // from a production host with debug on.
        $this->refuseDebugOnAProductionHost();

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
