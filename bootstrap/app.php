<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        // All routes
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // API routes (except auth — those are unauthenticated)
        $middleware->api(append: [
            \App\Http\Middleware\LimitRequestBody::class,
            \App\Http\Middleware\AuditLogger::class,
            \App\Http\Middleware\TenantIsolation::class,
        ]);

        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data' => null,
                    'meta' => [],
                    'errors' => [['message' => 'Resource not found.']],
                ], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data' => null,
                    'meta' => [],
                    'errors' => [['message' => 'Unauthenticated.']],
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data' => null,
                    'meta' => [],
                    'errors' => collect($e->errors())->map(fn ($messages, $field) => [
                        'field' => $field,
                        'message' => $messages[0],
                    ])->values()->all(),
                ], 422);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data' => null,
                    'meta' => [],
                    'errors' => [['message' => 'Forbidden.']],
                ], 403);
            }
        });
    })->create();
