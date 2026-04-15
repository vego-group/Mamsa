<?php

namespace App\Providers;

use App\Models\Unit;
use App\Policies\UnitPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     */
    protected $policies = [
        Unit::class => UnitPolicy::class,
    ];

    public function boot(): void
    {
        //
    }
}