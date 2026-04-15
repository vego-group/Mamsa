<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * التأكد من امتلاك المستخدم لأحد الأدوار المطلوبة.
     * مثال:
     * RoleMiddleware:SuperAdmin,Admin
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // ✅ لا redirect داخل middleware
        if (!$user) {
            abort(403);
        }

        // ✅ تحقق مباشر بدون تطبيع
        if (!$user->hasAnyRole($roles)) {
            abort(403, 'ليس لديك الصلاحية لدخول هذه الصفحة.');
        }

        // ✅ لازم نرجع Response دائمًا
        return $next($request);
    }
}