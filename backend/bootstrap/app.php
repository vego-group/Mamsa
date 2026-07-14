<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Partner-dashboard contract API: root-mounted paths (/auth/otp/*,
            // /me, /units, …) served with cookie sessions — routes/dashboard.php.
            \Illuminate\Support\Facades\Route::middleware('dashboard-api')
                ->group(base_path('routes/dashboard.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the reverse proxy / load balancer so the app sees the real
        // client IP and https scheme (TLS terminated at the edge).
        $trustedProxies = env('TRUSTED_PROXIES', '');
        $middleware->trustProxies(
            at: $trustedProxies === '*'
                ? '*'
                : array_values(array_filter(explode(',', (string) $trustedProxies))),
        );

        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // Partner-dashboard group: cookie session (httpOnly) without the web
        // CSRF-token middleware — mutations are guarded by SameSite=Lax plus
        // the Origin allowlist inside DashboardApi.
        $middleware->group('dashboard-api', [
            \App\Http\Middleware\DashboardApi::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);

        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->attributes->get('dashboard_api'),
        );

        // Partner-dashboard envelope: { error: { code, message, fields? } }.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->attributes->get('dashboard_api')) {
                return null; // fall through to default rendering
            }

            if ($e instanceof \App\Exceptions\DashboardException) {
                return $e->render();
            }

            if ($e instanceof \App\Exceptions\OtpException) {
                $status = $e->otpCode === 'OTP_LOCKED' ? 429 : 401;

                return response()->json([
                    'error' => ['code' => $e->otpCode, 'message' => collect($e->errors())->flatten()->first()],
                ], $status);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'error' => [
                        'code'    => 'VALIDATION',
                        'message' => 'بيانات غير صالحة',
                        'fields'  => collect($e->errors())->map(fn ($msgs) => $msgs[0])->all(),
                    ],
                ], 400);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'يرجى تسجيل الدخول'],
                ], 401);
            }

            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'error' => ['code' => 'RATE_LIMITED', 'message' => 'محاولات كثيرة، حاول لاحقاً'],
                ], 429, $e->getHeaders());
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'المورد غير موجود'],
                ], 404);
            }

            report($e);

            return response()->json([
                'error' => ['code' => 'SERVER_ERROR', 'message' => 'حدث خطأ غير متوقع'],
            ], 500);
        });
    })->create();
