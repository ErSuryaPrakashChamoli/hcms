<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class SetAuditSource
{
    public function handle(Request $request, Closure $next, string $source): Response
    {
        Context::add('audit.source', $source);

        return $next($request);
    }
}
