<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every request. Flows into logs, audit events and queued jobs via Context.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::ulid());

        Context::add('request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
