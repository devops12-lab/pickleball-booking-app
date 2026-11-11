<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The browser SPA authenticates with Sanctum's session cookie, so the
        // API must accept stateful (cookie + CSRF) requests, not only tokens.
        $middleware->statefulApi();

        // API clients should receive a JSON 401, not a redirect to a web login
        // route that does not exist in this API-only application.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login',
        );

        $middleware->alias([
            'role' => EnsureRole::class,
            'account.active' => EnsureAccountActive::class,
            'compat.active' => \App\Http\Middleware\EnsureCompatAccountActive::class,
            'tenant.context' => IdentifyTenant::class,
            'tenant.identify' => IdentifyTenant::class,
            'tenant.access' => EnsureTenantAccess::class,
            'staff.permission' => RequirePermission::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Unauthenticated.',
                    'errors' => [],
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'You are not authorized to perform this action.',
                    'errors' => [],
                ], 403);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'You are not authorized to perform this action.',
                    'errors' => [],
                ], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The requested resource was not found.',
                    'errors' => [],
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The requested resource or endpoint was not found.',
                    'errors' => [],
                ], 404);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Too many requests. Please try again later.',
                    'errors' => [],
                ], 429, $e->getHeaders());
            }
        });
    })->create();
