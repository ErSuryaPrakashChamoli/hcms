<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every request. Flows into logs, audit events, outbound webhooks and queued jobs
 * via Context.
 *
 * Phase 14:
 * - A caller may supply `X-Correlation-Id` or `X-Request-Id`. It is accepted only when it is 8–64
 *   characters of `[A-Za-z0-9._:-]`; anything else is replaced by a fresh ULID, so no header value can
 *   inject into logs.
 * - The id is echoed in both headers.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $supplied = (string) ($request->header('X-Correlation-Id') ?: $request->header('X-Request-Id') ?: '');
        $requestId = preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $supplied) ? $supplied : (string) Str::ulid();

        Context::add('request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $requestId);

        return $response;
    }
}
