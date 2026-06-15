<?php

namespace App\Providers;

use App\Services\Sms\FgcSmsProvider;
use App\Services\Sms\LogSmsProvider;
use App\Services\Sms\SmsProvider;
use App\Services\Sms\TaqnyatSmsProvider;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsProvider::class, function () {
            $driver = config('sms.driver', 'log');

            return match ($driver) {
                'fgc' => new FgcSmsProvider(
                    username:   config('sms.fgc.username'),
                    password:   config('sms.fgc.password'),
                    senderName: config('sms.fgc.sender_name'),
                ),
                'taqnyat' => new TaqnyatSmsProvider(
                    apiKey:        config('sms.taqnyat.api_key'),
                    defaultSender: config('sms.taqnyat.sender_id'),
                ),
                default => new LogSmsProvider(),
            };
        });
    }

    public function boot(): void {}
}
