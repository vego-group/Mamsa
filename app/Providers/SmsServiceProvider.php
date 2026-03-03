<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Sms\SmsProvider;
use App\Services\Sms\LogSmsProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind interface -> implementation (stub for now)
        $this->app->singleton(SmsProvider::class, function () {
            return new LogSmsProvider();
        });
    }

    public function boot(): void
    {
        //
    }
}