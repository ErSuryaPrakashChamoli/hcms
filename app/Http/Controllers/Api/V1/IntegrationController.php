<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\ExternalReferences;
use App\Domain\Integration\Services\InboundEvents;
use App\Domain\Integration\Support\LinkableEntities;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 14 Integration Hub API (`/api/v1/integrations/{system}/*`).
 *
 * Inbound events (scope integrations.write) are:
 * - signed: HMAC over "timestamp.body" with the integration's own secret, within the timestamp window;
 * - idempotent: `Idempotency-Key` header, else the event_id;
 * - processed asynchronously.
 *
 * Lookups (scope integrations.read) return PeopleOS codes and ids for the integration's own references
 * only. A system of another tenant does not exist here (404).
 */
class IntegrationController extends Controller
{
    public function receive(Request $request, string $system, InboundEvents $events): JsonResponse
    {
        $integration = $this->system($system);
        try {
            $result = $events->receive($integration, $request->attributes->get('api_key'), $request->getContent(), [
                'x-peopleos-timestamp' => $request->header('X-PeopleOS-Timestamp'), 'x-peopleos-signature' => $request->header('X-PeopleOS-Signature'),
                'idempotency-key' => $request->header('Idempotency-Key'), 'x-correlation-id' => $request->header('X-Correlation-Id'),
            ]);
        } catch (IntegrationRejected $e) {
            return $this->error($e);
        }
        $event = $result['event'];

        return response()->json(['data' => $this->summary($event) + ['duplicate' => $result['duplicate']]], $result['duplicate'] ? 200 : 202)
            ->header('X-Correlation-Id', $event->correlation_id);
    }

    public function event(string $system, string $eventId): JsonResponse
    {
        $integration = $this->system($system);
        $event = InboundEvent::query()->where('integration_system_id', $integration->id)->where('external_event_id', $eventId)->latest('id')->first();
        abort_if($event === null, 404);

        return response()->json(['data' => $this->summary($event)]);
    }

    public function reference(Request $request, string $system, ExternalReferences $references, LinkableEntities $entities): JsonResponse
    {
        $integration = $this->system($system);
        try {
            $entity = $references->resolve($integration, (string) $request->query('external_entity_type', ''), (string) $request->query('external_entity_id', ''));
        } catch (IntegrationRejected $e) {
            return $this->error($e);
        }
        abort_if($entity === null, 404);
        $alias = $entities->aliasFor($entity);
        $codeColumn = LinkableEntities::TYPES[$alias][1];

        return response()->json(['data' => ['entity_type' => $alias, 'peopleos_id' => $entity->getKey(), 'peopleos_code' => $codeColumn ? $entity->getAttribute($codeColumn) : null]]);
    }

    private function system(string $code): IntegrationSystem
    {
        return IntegrationSystem::query()->where('code', strtolower($code))->firstOrFail();
    }

    private function summary(InboundEvent $event): array
    {
        return [
            'id' => $event->id, 'event_id' => $event->external_event_id, 'event_type' => $event->event_type, 'status' => $event->status,
            'idempotency_key' => $event->idempotency_key, 'correlation_id' => $event->correlation_id, 'attempts' => $event->attempts,
            'received_at' => $event->received_at?->toIso8601String(), 'processed_at' => $event->processed_at?->toIso8601String(),
            'error' => in_array($event->status, ['failed', 'dead_letter', 'retrying'], true) ? $event->last_error : null,
        ];
    }

    private function error(IntegrationRejected $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
    }
}
