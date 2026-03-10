<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * التأكد من امتلاك المستخدم لأحد الأدوار المطلوبة.
     * مثال الاستعمال:
     *   RoleMiddleware:Super Admin,Admin
     *   RoleMiddleware:Partner
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // تطبيع أسماء الأدوار
        $normalized = array_map(function ($r) {
            return ucwords(str_replace('_', ' ', trim($r)));
        }, $roles);

        // استخدم دالة الموديل
        if (!$user->hasAnyRole($normalized)) {
            abort(403, 'ليس لديك الصلاحية لدخول هذه الصفحة.');
        }

        return $next($request);
    }
}