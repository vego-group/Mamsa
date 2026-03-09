<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // super_admin -> Super Admin (تطبيع)
        $normalized = array_map(function ($r) {
            return ucwords(str_replace('_', ' ', trim($r)));
        }, $roles);

        $has = $user->roles()->whereIn('role_name', $normalized)->exists();

        if (! $has) {
            abort(403, 'ليس لديك الصلاحية لدخول هذه الصفحة.');
        }

        return $next($request);
    }
}