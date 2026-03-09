<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // هنا ممكن تضعي أي ضبط إضافي لو تحتاجين
        // في الإصدارات الحديثة، تعريف المسارات يتم في routes/*.php مباشرة
    }
}