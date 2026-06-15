<?php

namespace App\Providers;

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
    }
}
