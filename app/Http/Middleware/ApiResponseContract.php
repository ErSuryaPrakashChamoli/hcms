<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 14: one error shape for every `/api/*` error, whichever layer produced it (key middleware,
 * validation, a controller's business refusal, a 404, throttling):
 *
 *     {"message": "...", "code": "not_found", "request_id": "...", "errors": {...}?}
 *
 * - Not-found messages never name an internal model class.
 * - Server errors never carry exception text outside debug mode.
 * - Existing `message` / `errors` fields stay, so the change is additive (ADR-0014).
 */
class ApiResponseContract
{
    public const CODES = [400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict',
        410 => 'gone', 413 => 'payload_too_large', 415 => 'unsupported_media_type', 422 => 'unprocessable', 423 => 'locked', 429 => 'rate_limited'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }
        $status = $response->getStatusCode();
        $data = $response->getData(true);
        $data = is_array($data) ? $data : ['message' => (string) $data];
        $message = (string) ($data['message'] ?? '');
        if ($status === 404 && ($message === '' || str_contains($message, 'No query results for model') || str_contains($message, '\\'))) {
            $message = 'Not found.';
        }
        if ($status >= 500 && ! config('app.debug')) {
            $message = 'Server error.';
            unset($data['exception'], $data['file'], $data['line'], $data['trace']);
        }
        $data['message'] = $message !== '' ? $message : (Response::$statusTexts[$status] ?? 'Error');
        $data['code'] ??= isset($data['errors']) && $status === 422 ? 'validation_failed' : (self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'error'));
        $data['request_id'] = Context::get('request_id');

        return $response->setData($data);
    }
}
