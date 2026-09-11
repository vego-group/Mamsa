<?php

use App\Exceptions\AdminPanelException;
use App\Exceptions\DashboardException;
use App\Exceptions\OtpException;
use App\Http\Middleware\AdminPanelApi;
use App\Http\Middleware\DashboardApi;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
            Route::middleware('dashboard-api')
                ->group(base_path('routes/dashboard.php'));

            // Admin-panel (Next.js) contract API: root-mounted under /admin/*,
            // cookie sessions, OTP auth — routes/admin-panel.php.
            Route::middleware('admin-panel')
                ->group(base_path('routes/admin-panel.php'));
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
            ForceJsonResponse::class,
        ]);

        // Partner-dashboard group: cookie session (httpOnly) without the web
        // CSRF-token middleware — mutations are guarded by SameSite=Lax plus
        // the Origin allowlist inside DashboardApi.
        //
        // SubstituteBindings was missing here until 2026-09-08, and its absence
        // does not fail loudly: a route parameter type-hinted as a Model is not
        // resolved and not rejected — the container simply constructs an EMPTY
        // model. Every property reads null, and the request dies somewhere far
        // from the cause. It cost a 500 on a valid signed attachment link, where
        // the error surfaced as a Flysystem TypeError about a null path.
        //
        // Adding it changes nothing for the routes already here: substitution
        // only applies to parameters type-hinted as Models, and every dashboard
        // controller takes a `string $id` and resolves it by hand. What it does
        // is make the next model type-hint work instead of failing obscurely.
        $middleware->group('dashboard-api', [
            DashboardApi::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            SubstituteBindings::class,
        ]);

        // Admin-panel (Next.js) BFF group: same cookie-session stack, distinct
        // marker middleware so the exception renderer uses the flat envelope.
        $middleware->group('admin-panel', [
            AdminPanelApi::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            // Same omission, same reasoning — see the dashboard group above.
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            // Per-endpoint authz for the admin-panel BFF (contract §4.3).
            'admin.can' => EnsureAdminPermission::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->attributes->get('dashboard_api')
                || $request->attributes->get('admin_panel_api'),
        );

        // Admin-panel (Next.js) envelope: flat { message, code } — distinct from
        // the partner-dashboard { error: { code, message } }. Runs before the
        // dashboard renderer so admin requests never fall into it.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->attributes->get('admin_panel_api')) {
                return null; // not an admin-panel request — fall through
            }

            if ($e instanceof AdminPanelException) {
                return $e->render();
            }

            $flat = fn (string $code, string $message, int $status, array $headers = []) => response()->json(
                ['message' => $message, 'code' => $code], $status, $headers,
            );

            if ($e instanceof OtpException) {
                $code = match ($e->otpCode) {
                    'OTP_EXPIRED' => 'OTP_EXPIRED',
                    'OTP_LOCKED' => 'OTP_MAX_ATTEMPTS',
                    default => 'OTP_INVALID',
                };
                $status = $code === 'OTP_MAX_ATTEMPTS' ? 429 : 422;

                return $flat($code, collect($e->errors())->flatten()->first() ?: 'رمز غير صحيح', $status);
            }

            if ($e instanceof ValidationException) {
                // Every failing field, not just the first.
                //
                // A validation error reached through here — a FormRequest, or a
                // bare $request->validate() — used to arrive as one sentence,
                // while the same failure through AdminPanel\Controller::validate()
                // arrived with every field named. So the answer depended on how
                // the check happened to be written, and the thin version sends
                // an admin round the form once per mistake: fix the permit
                // number, resubmit, discover the coordinates, resubmit again.
                //
                // Shape matches AdminPanelException::render() exactly, so a
                // client cannot tell which path produced the response.
                $fields = collect($e->errors())->map(fn (array $msgs) => (string) ($msgs[0] ?? ''))->all();

                return response()->json([
                    'message' => $e->validator->errors()->first() ?: 'بيانات غير صالحة',
                    'code' => 'VALIDATION_ERROR',
                    // Omitted rather than sent empty, exactly as
                    // AdminPanelException::render() does: a key that is always
                    // present but sometimes meaningless is one a client has to
                    // test anyway.
                ] + ($fields === [] ? [] : ['fields' => $fields]), 422);
            }

            if ($e instanceof AuthenticationException) {
                return $flat('UNAUTHENTICATED', 'يجب تسجيل الدخول للمتابعة', 401);
            }

            if ($e instanceof ThrottleRequestsException) {
                return $flat('RATE_LIMITED', 'محاولات كثيرة، حاول لاحقاً', 429, $e->getHeaders());
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return $flat('NOT_FOUND', 'المورد غير موجود', 404);
            }

            // Any other exception that already carries an HTTP status keeps it.
            //
            // Without this the catch-all below flattened every unlisted case to
            // 500 — including an expired signed URL, which is a 403 and an
            // entirely ordinary event: attachment links live fifteen minutes, so
            // a page left open produces one routinely. Reporting that as a
            // server error sends people to look for a fault that is not there.
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return $flat(
                    $status === 403 ? 'FORBIDDEN' : 'HTTP_ERROR',
                    $status === 403 ? 'الرابط غير صالح أو انتهت صلاحيته' : 'تعذّر تنفيذ الطلب',
                    $status,
                    $e->getHeaders(),
                );
            }

            report($e);

            return $flat('SERVER_ERROR', 'حدث خطأ غير متوقع', 500);
        });

        // Partner-dashboard envelope: { error: { code, message, fields? } }.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->attributes->get('dashboard_api')) {
                return null; // fall through to default rendering
            }

            if ($e instanceof DashboardException) {
                return $e->render();
            }

            if ($e instanceof OtpException) {
                $status = $e->otpCode === 'OTP_LOCKED' ? 429 : 401;

                return response()->json([
                    'error' => ['code' => $e->otpCode, 'message' => collect($e->errors())->flatten()->first()],
                ], $status);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION',
                        'message' => 'بيانات غير صالحة',
                        'fields' => collect($e->errors())->map(fn ($msgs) => $msgs[0])->all(),
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

            // Keep a status the exception already carries. The catch-all below
            // used to flatten every unlisted case to 500, including an expired
            // signed URL — a 403, and a routine event: attachment links live
            // fifteen minutes, so a page left open produces one. Reporting it as
            // a server error sends people hunting for a fault that is not there.
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return response()->json([
                    'error' => [
                        'code' => $status === 403 ? 'FORBIDDEN' : 'HTTP_ERROR',
                        'message' => $status === 403
                            ? 'الرابط غير صالح أو انتهت صلاحيته'
                            : 'تعذّر تنفيذ الطلب',
                    ],
                ], $status, $e->getHeaders());
            }

            report($e);

            return response()->json([
                'error' => ['code' => 'SERVER_ERROR', 'message' => 'حدث خطأ غير متوقع'],
            ], 500);
        });

        /*
         * Guest API (/api/v1) — the only surface without a renderer of its own,
         * so it fell through to Laravel's default and answered a missing unit
         * with "No query results for model [App\Models\Unit] 999999".
         *
         * Clients display `message` to the visitor, so that string put our
         * model names and namespace layout on a public page. The detail belongs
         * in the log; the caller gets a stable `code` to branch and translate
         * on.
         *
         * Deliberately narrow: only not-found and otherwise-unhandled errors.
         * Validation and auth responses keep their existing shapes, which
         * clients already parse.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')
                || $request->attributes->get('dashboard_api')
                || $request->attributes->get('admin_panel_api')) {
                return null;
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json(['message' => 'المورد غير موجود', 'code' => 'NOT_FOUND'], 404);
            }

            if ($e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof ThrottleRequestsException
                || $e instanceof OtpException
                || $e instanceof HttpExceptionInterface) {
                return null; // keep the shapes clients already handle
            }

            report($e);

            return response()->json(['message' => 'حدث خطأ غير متوقع', 'code' => 'SERVER_ERROR'], 500);
        });
    })->create();
