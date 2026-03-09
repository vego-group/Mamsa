<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        if (! $request->expectsJson()) {
            // أثناء الاختبار: وجّه غير الموثقين إلى صفحة البريد/كلمة المرور
            return route('login');
        }
        return null;
    }
}