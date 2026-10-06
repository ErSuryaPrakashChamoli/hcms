<?php

namespace App\Http\Middleware;

use App\Domain\Integration\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 14: generic `Idempotency-Key` handling for v1 write endpoints (alias `api.idempotent`, after
 * `api.key`, so the tenant and key are bound). The key is optional; when present:
 * - The first request claims (tenant, API key, key) with a unique insert, then stores its response
 *   (encrypted, 24 hours).
 * - A repeat with the same method, path and body replays the stored response
 *   (`Idempotent-Replayed: true`).
 * - The same key with another body is refused (422).
 * - A duplicate arriving while the first is still in flight gets a 409. Either way the domain action
 *   runs once.
 * - A 5xx outcome releases the key so the caller can retry.
 * - SaaS.2: a key is remembered for `peopleos.api.idempotency_ttl_hours` (24 by default). After that it may
 *   be used again: the expired row is released (only if it is still the expired one) and the request
 *   claims the key afresh. retention:purge deletes expired rows, so the table no longer grows forever.
 */
class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $apiKey = $request->attributes->get('api_key');
        if ($key === null || $apiKey === null || $request->isMethodSafe()) {
            return $next($request);
        }
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 191) {
            return response()->json(['message' => 'The Idempotency-Key header must be 1–191 characters.', 'code' => 'invalid_idempotency_key'], 422);
        }
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());
        $claim = fn () => ApiIdempotencyKey::query()->create([
            'api_key_id' => $apiKey->id, 'idempotency_key' => $key, 'method' => $request->method(), 'path' => mb_substr($request->path(), 0, 255),
            'request_sha256' => $fingerprint, 'status' => 'processing', 'expires_at' => now()->addHours((int) config('peopleos.api.idempotency_ttl_hours')),
        ]);

        try {
            $record = $claim();
        } catch (UniqueConstraintViolationException) {
            $existing = ApiIdempotencyKey::query()->where('api_key_id', $apiKey->id)->where('idempotency_key', $key)->first();
            // An expired key is free again. Delete that exact expired row (never a fresh claim a concurrent retry
            // just made), then claim once more; losing that race is an ordinary in-flight duplicate.
            if ($existing !== null && $existing->expires_at->lte(now())) {
                ApiIdempotencyKey::query()->whereKey($existing->getKey())->where('expires_at', '<=', now())->delete();
                try {
                    $record = $claim();
                } catch (UniqueConstraintViolationException) {
                    return response()->json(['message' => 'A request with this Idempotency-Key is still being processed.', 'code' => 'idempotency_in_progress'], 409);
                }

                return $this->complete($record, $next($request));
            }
            if ($existing === null || ! hash_equals($existing->request_sha256, $fingerprint)) {
                return response()->json(['message' => 'This Idempotency-Key was already used for a different request.', 'code' => 'idempotency_key_reused'], 422);
            }
            if ($existing->status !== 'completed') {
                return response()->json(['message' => 'A request with this Idempotency-Key is still being processed.', 'code' => 'idempotency_in_progress'], 409);
            }

            return response((string) $existing->response_body, (int) $existing->response_status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }

        return $this->complete($record, $next($request));
    }

    private function complete(ApiIdempotencyKey $record, Response $response): Response
    {
        if ($response->getStatusCode() >= 500) {
            $record->delete();

            return $response;
        }
        $record->update(['status' => 'completed', 'response_status' => $response->getStatusCode(), 'response_body' => $response instanceof JsonResponse ? $response->getContent() : (string) $response->getContent()]);

        return $response;
    }
}
