<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\InboundEvents;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/bgv/cases/{reference}/checks: vendor results (§22), secured in the production
 * readiness closure. Nothing in the body is read, validated or applied until all of these pass:
 *
 * 1. the API key (scope `bgv.write`) has bound the tenant;
 * 2. an active `bgv` integration of that tenant is bound to this exact key (integration identity);
 * 3. the HMAC-SHA256 signature over "timestamp.body" with that integration's secret is valid, compared
 *    in constant time, with the timestamp inside the integration's window (stale → 401). It is checked
 *    even if the integration does not require signatures elsewhere.
 *
 * The results are then stored once as an Integration Hub event (`bgv.results`, audited, payload
 * encrypted) under `Idempotency-Key`, else `X-PeopleOS-Event-Id`, else a digest of the signed timestamp
 * and body. A replayed or duplicate request returns the stored outcome without applying anything again.
 * The event is applied in the hub's processing transaction.
 *
 * Provider-specific signature schemes are not implemented; no provider contract is available. A
 * provider must sign with the PeopleOS scheme above, or an adapter must be added when its contract is
 * known.
 */
class BgvCallbackController extends Controller
{
    public function store(Request $request, string $reference, InboundEvents $events, AuditRecorder $audit): JsonResponse
    {
        $key = $request->attributes->get('api_key');
        $system = IntegrationSystem::query()->where('kind', 'bgv')->where('api_key_id', $key->id)->first();
        if ($system === null) {
            $audit->record(AuditAction::IntegrationSignatureRejected, 'integration', null, [], null, metadata: ['reason' => 'no_bound_bgv_integration', 'api_key_id' => $key->id, 'endpoint' => 'bgv.checks']);

            return $this->error('This API key is not bound to an active background-verification integration.', 'integration_required', 403);
        }
        $raw = $request->getContent();
        $headers = [
            'x-peopleos-timestamp' => $request->header('X-PeopleOS-Timestamp'), 'x-peopleos-signature' => $request->header('X-PeopleOS-Signature'),
            'x-correlation-id' => $request->header('X-Correlation-Id'),
        ];

        try {
            $events->authenticate($system, $key, $raw, $headers, forceSignature: true);
            $payload = json_decode($raw, true);
            $validator = Validator::make(is_array($payload) ? $payload : [], [
                'checks' => ['required', 'array', 'min:1', 'list'],
                'checks.*.type' => ['required', Rule::in(array_keys(config('peopleos.bgv.check_types')))],
                'checks.*.status' => ['required', Rule::in(array_keys(BgvCheck::STATUSES))],
                'checks.*.notes' => ['nullable', 'string', 'max:2000'],
            ]);
            if (! is_array($payload) || $validator->fails()) {
                return response()->json(['message' => 'The results are not valid.', 'code' => 'invalid_payload',
                    'errors' => is_array($payload) ? $validator->errors()->toArray() : ['body' => ['The body must be a JSON object.']]], 422);
            }
            $idempotencyKey = trim((string) ($request->header('Idempotency-Key') ?: $request->header('X-PeopleOS-Event-Id') ?: 'sig-'.hash('sha256', $headers['x-peopleos-timestamp'].'.'.$raw)));
            $recorded = $events->record($system, 'bgv.results', $idempotencyKey, $idempotencyKey, ['case_reference' => $reference, 'checks' => $validator->validated()['checks']], $raw, $headers['x-correlation-id']);
        } catch (IntegrationRejected $e) {
            return $this->error($e->getMessage(), $e->reason, $e->status);
        }

        $event = $recorded['event'];
        if (! $recorded['duplicate']) {
            $events->process($event);
        }
        $event->refresh();
        $response = match ($event->status) {
            'succeeded' => response()->json(['data' => $event->result]),
            'failed' => $this->error((string) $event->last_error, (string) ($event->result['rejected'] ?? 'rejected'), (int) ($event->result['http_status'] ?? 422)),
            default => response()->json(['data' => ['status' => $event->status, 'event_id' => $event->external_event_id]], 202),
        };

        return $response->header('X-Correlation-Id', $event->correlation_id)->header('Idempotent-Replayed', $recorded['duplicate'] ? 'true' : 'false');
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status); // request_id added by ApiResponseContract
    }
}
