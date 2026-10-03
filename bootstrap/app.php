<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\ApiResponseContract;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\ResolveTenant;
use App\Support\Tenancy\Exceptions\MissingTenantException;
use App\Support\Tenancy\Exceptions\TenantMismatchException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Phase 14: liveness and readiness. No session, cookies or tenant: probes never create sessions.
        then: function () {
            Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
            Route::get('/health/ready', [HealthController::class, 'ready'])->middleware('throttle:60,1')->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias(['api.key' => AuthenticateApiKey::class, 'api.idempotent' => EnforceIdempotency::class]);
        $middleware->throttleApi();
        // Phase 14: one error envelope for every /api/* error; idempotency only after the key has bound the tenant.
        $middleware->api(prepend: [ApiResponseContract::class]);
        $middleware->appendToPriorityList(AuthenticateApiKey::class, EnforceIdempotency::class);
        // Bind the tenant before implicit route-model binding so scoped lookups never run unbound.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateApiKey::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Tenancy violations are reported but rendered as a plain 403 without tenant details.
        $exceptions->render(fn (MissingTenantException|TenantMismatchException $e, Request $request) => $request->expectsJson() || $request->is('api/*')
            ? response()->json(['message' => 'Forbidden.'], 403)
            : abort(403));
    })->create();
