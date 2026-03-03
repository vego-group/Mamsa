<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // مثال:
        // \App\Events\UserRegistered::class => [
        //     \App\Listeners\SendWelcomeEmail::class,
        // ],
    ];

    public function boot(): void
    {
        //
    }
}