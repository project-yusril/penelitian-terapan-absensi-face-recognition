<?php

use App\Exceptions\SpTransitionConflict;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsureEnrollmentApproved;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTrustedBiometricEvidence;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Inertia shares props (auth user, flash) on every web response.
        // EnforceTwoFactor harus setelah session aktif (web stack default sudah punya StartSession).
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnforceTwoFactor::class,
        ]);

        $middleware->alias([
            'role' => CheckRole::class,
            'web.role' => EnsureUserRole::class,
            'enrollment.approved' => EnsureEnrollmentApproved::class,
            'user.active' => EnsureUserIsActive::class,
            'biometric.trusted' => RequireTrustedBiometricEvidence::class,
            '2fa' => EnforceTwoFactor::class,
        ]);

    })

    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Always render JSON for API routes — catch-all for unhandled exceptions
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                // Handle specific exception types
                if ($e instanceof AuthenticationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthenticated',
                    ], 401);
                }

                if ($e instanceof HttpResponseException) {
                    return $e->getResponse();
                }

                if ($e instanceof RouteNotFoundException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthenticated',
                    ], 401);
                }

                if ($e instanceof ValidationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $e->errors(),
                    ], 422);
                }

                if ($e instanceof SpTransitionConflict) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                    ], 409);
                }

                if ($e instanceof ModelNotFoundException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Resource not found',
                    ], 404);
                }

                if ($e instanceof NotFoundHttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Endpoint not found',
                    ], 404);
                }

                if ($e instanceof MethodNotAllowedHttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Method not allowed',
                    ], 405);
                }

                if ($e instanceof AccessDeniedHttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Forbidden',
                    ], 403);
                }

                // Generic 500 for other errors
                return response()->json([
                    'success' => false,
                    'message' => app()->isProduction() ? 'Server error' : $e->getMessage(),
                ], $status >= 400 && $status < 600 ? $status : 500);
            }
        });
    })->create();
