<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\ResolveTenant;
use App\Support\Tenancy\Exceptions\MissingTenantException;
use App\Support\Tenancy\Exceptions\TenantMismatchException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias(['api.key' => AuthenticateApiKey::class]);
        $middleware->throttleApi();
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
