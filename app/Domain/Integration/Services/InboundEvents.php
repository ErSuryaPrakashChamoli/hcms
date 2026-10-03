<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Contracts\InboundEventHandler;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Jobs\ProcessInboundEvent;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Support\PayloadGuard;
use App\Domain\Integration\Support\Signature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 14 (ADR-0012): the inbound side of the Integration Hub.
 *
 * Receive:
 * - Authenticate: the API key binds the tenant; the system belongs to that tenant and, when it names
 *   one, to that key.
 * - Verify the HMAC signature and the timestamp window (replay protection).
 * - Validate the envelope, then store the event once per (tenant, system, idempotency key). A repeat
 *   delivery returns the stored event and never repeats the business action. The same key with a
 *   different body is a conflict.
 * - Processing is queued after the commit, never run inside the receiving request.
 *
 * Process:
 * - Claim the event with a conditional update (leased, so two workers never both run it).
 * - Run its handler inside one transaction together with the "succeeded" state, so the business
 *   effect and the outcome commit or roll back together.
 * - A refusal is final (failed); any other error retries with backoff up to the limit, then dead letter.
 * - The correlation id becomes the request id of everything the handler writes, so the audit trail
 *   and outbound webhooks carry it.
 */
final class InboundEvents
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, string|null>  $headers  lower-cased header => value
     * @return array{duplicate: bool, event: InboundEvent}
     */
    public function receive(IntegrationSystem $system, ApiKey $key, string $rawBody, array $headers): array
    {
        if (! $system->isActive()) {
            throw new IntegrationRejected('This integration is not active.', 'integration_inactive', 409);
        }
        if ($system->api_key_id !== null && (int) $system->api_key_id !== (int) $key->id) {
            throw new IntegrationRejected('This API key may not post events for this integration.', 'forbidden', 403);
        }
        if ($system->require_signature) {
            $check = Signature::check((string) $system->inbound_secret, $headers['x-peopleos-timestamp'] ?? null, $rawBody, $headers['x-peopleos-signature'] ?? null, (int) $system->signature_tolerance_seconds);
            if ($check !== 'valid') {
                $this->audit->record(AuditAction::IntegrationSignatureRejected, 'integration', $system, [], null, metadata: ['reason' => $check, 'api_key_id' => $key->id]);
                throw new IntegrationRejected($check === 'stale_timestamp' ? 'The request timestamp is outside the allowed window.' : 'The request signature is not valid.', $check, 401);
            }
        }
        $envelope = json_decode($rawBody, true);
        if (! is_array($envelope)) {
            throw new IntegrationRejected('The body must be a JSON object.', 'invalid_payload');
        }
        $eventId = is_scalar($envelope['event_id'] ?? null) ? trim((string) $envelope['event_id']) : '';
        $type = is_string($envelope['event_type'] ?? null) ? strtolower(trim($envelope['event_type'])) : '';
        $data = $envelope['data'] ?? null;
        if ($eventId === '' || mb_strlen($eventId) > 191 || ! preg_match('/^[a-z0-9_.-]{1,64}$/', $type) || ! is_array($data)) {
            throw new IntegrationRejected('The event needs event_id (≤191), event_type (letters, digits, _ . -) and a data object.', 'invalid_payload');
        }
        if (! $system->accepts($type) || ! array_key_exists($type, config('peopleos.integration.handlers', []))) {
            throw new IntegrationRejected("This integration does not accept [{$type}] events.", 'unsupported_event_type');
        }
        $idempotencyKey = trim((string) ($headers['idempotency-key'] ?? $eventId));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            throw new IntegrationRejected('The idempotency key must be 1–191 characters.', 'invalid_payload');
        }
        $correlation = $this->correlationId($headers['x-correlation-id'] ?? ($envelope['correlation_id'] ?? null));
        $sha = hash('sha256', $rawBody);

        try {
            $event = DB::transaction(function () use ($system, $type, $eventId, $idempotencyKey, $correlation, $sha, $rawBody, $data) {
                $event = InboundEvent::query()->create([
                    'integration_system_id' => $system->id, 'event_type' => $type, 'external_event_id' => $eventId, 'idempotency_key' => $idempotencyKey,
                    'correlation_id' => $correlation, 'status' => 'received', 'payload' => $data, 'payload_sha256' => $sha, 'payload_size' => strlen($rawBody),
                    'payload_metadata' => ['keys' => PayloadGuard::keys($data)], 'received_at' => now(), 'next_attempt_at' => now(),
                ]);
                $this->audit->record(AuditAction::IntegrationEventReceived, 'integration', $event, [], null, metadata: ['system' => $system->code, 'event_type' => $type, 'correlation_id' => $correlation]);

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = InboundEvent::query()->where('integration_system_id', $system->id)->where('idempotency_key', $idempotencyKey)->firstOrFail();
            if (! hash_equals($existing->payload_sha256, $sha)) {
                throw new IntegrationRejected('This idempotency key was already used with a different body.', 'idempotency_conflict', 409);
            }

            return ['duplicate' => true, 'event' => $existing];
        }

        DB::afterCommit(fn () => ProcessInboundEvent::dispatch((int) $event->tenant_id, (int) $event->id));

        return ['duplicate' => false, 'event' => $event];
    }

    /** @return 'succeeded'|'failed'|'retrying'|'dead_letter'|'skipped' */
    public function process(InboundEvent $event): string
    {
        if (! $this->claim($event)) {
            return 'skipped';
        }
        $event->refresh();
        $system = $event->system()->firstOrFail();
        $previousRequestId = Context::get('request_id');
        Context::add('request_id', $event->correlation_id);

        try {
            $result = DB::transaction(function () use ($event, $system) {
                $current = InboundEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'processing' || (int) $current->attempts !== (int) $event->attempts) {
                    return null; // lease lost to another worker
                }
                if ($current->payload === null) {
                    throw new IntegrationRejected('The payload was purged; the event cannot be applied.', 'payload_purged');
                }
                $handler = $this->handler($current->event_type);
                $result = $handler->handle($current, $system, $current->payload);
                $current->update(['status' => 'succeeded', 'processed_at' => now(), 'last_error' => null, 'result' => PayloadGuard::clean($result), 'next_attempt_at' => null]);
                $this->audit->record(AuditAction::IntegrationEventProcessed, 'integration', $current, [['field' => 'status', 'before' => 'processing', 'after' => 'succeeded']], null,
                    metadata: ['system' => $system->code, 'event_type' => $current->event_type, 'correlation_id' => $current->correlation_id, 'attempt' => $current->attempts]);

                return 'succeeded';
            });

            return $result ?? 'skipped';
        } catch (IntegrationRejected $e) {
            return $this->finish($event, 'failed', $e->getMessage(), AuditAction::IntegrationEventFailed);
        } catch (Throwable $e) {
            report($e);
            $max = (int) config('peopleos.integration.max_attempts', 5);
            if ($event->attempts >= $max) {
                return $this->finish($event, 'dead_letter', $e->getMessage(), AuditAction::IntegrationEventDeadLettered);
            }

            return $this->finish($event, 'retrying', $e->getMessage(), null, now()->addMinutes(min(60, 2 ** $event->attempts)));
        } finally {
            $previousRequestId === null ? Context::forget('request_id') : Context::add('request_id', $previousRequestId);
        }
    }

    /** Failed or dead-lettered → received again (explicit, audited, needs the payload). */
    public function reprocess(InboundEvent $event, User $actor, string $reason): InboundEvent
    {
        if (! $actor->hasPermission('integration.manage')) {
            throw new IntegrationRejected('This needs integration.manage.', 'forbidden', 403);
        }
        if (trim($reason) === '') {
            throw new IntegrationRejected('Reprocessing needs a reason.');
        }

        $event = DB::transaction(function () use ($event, $actor, $reason) {
            $current = InboundEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['failed', 'dead_letter'], true)) {
                throw new IntegrationRejected('Only failed or dead-lettered events are reprocessed.', 'not_reprocessable', 409);
            }
            if ($current->payload_purged_at !== null) {
                throw new IntegrationRejected('The payload was purged; ask the source system to send the event again with a new key.', 'payload_purged', 409);
            }
            $before = $current->status;
            $current->update(['status' => 'received', 'attempts' => 0, 'reprocess_count' => $current->reprocess_count + 1, 'next_attempt_at' => now()]);
            $this->audit->record(AuditAction::IntegrationEventReprocessed, 'integration', $current, [['field' => 'status', 'before' => $before, 'after' => 'received']], $reason, actor: $actor,
                metadata: ['correlation_id' => $current->correlation_id]);

            return $current;
        });
        DB::afterCommit(fn () => ProcessInboundEvent::dispatch((int) $event->tenant_id, (int) $event->id));

        return $event;
    }

    /** Due events of the bound tenant: new, retrying and expired processing leases. @return array<string, int> */
    public function processDue(int $limit = 100): array
    {
        $out = ['succeeded' => 0, 'failed' => 0, 'retrying' => 0, 'dead_letter' => 0, 'skipped' => 0];
        InboundEvent::query()->whereIn('status', ['received', 'retrying', 'processing'])->where('next_attempt_at', '<=', now())->orderBy('id')->limit($limit)->get()
            ->each(function (InboundEvent $event) use (&$out) {
                $out[$this->process($event)]++;
            });

        return $out;
    }

    /** Purge payload bodies of finished events older than the retention window (metadata stays). */
    public function purgePayloads(?int $days = null): int
    {
        $days ??= (int) config('peopleos.integration.payload_retention_days', 7);

        return InboundEvent::query()->whereIn('status', ['succeeded', 'failed', 'dead_letter'])->whereNull('payload_purged_at')
            ->where('updated_at', '<', now()->subDays($days))->update(['payload' => null, 'payload_purged_at' => now()]);
    }

    /** Conditional update: only one worker moves a due event to processing (leased for 10 minutes). */
    private function claim(InboundEvent $event): bool
    {
        return InboundEvent::query()->whereKey($event->id)->whereIn('status', ['received', 'retrying', 'processing'])->where('next_attempt_at', '<=', now())
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'next_attempt_at' => now()->addMinutes(10), 'updated_at' => now()]) === 1;
    }

    private function finish(InboundEvent $event, string $status, string $error, ?AuditAction $action, mixed $next = null): string
    {
        DB::transaction(function () use ($event, $status, $error, $action, $next) {
            $current = InboundEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'processing') {
                return;
            }
            $current->update(['status' => $status, 'last_error' => Str::limit($error, 480, ''), 'next_attempt_at' => $next, 'processed_at' => $status === 'retrying' ? null : now()]);
            if ($action !== null) {
                $this->audit->record($action, 'integration', $current, [['field' => 'status', 'before' => 'processing', 'after' => $status]], Str::limit($error, 250, ''),
                    metadata: ['event_type' => $current->event_type, 'correlation_id' => $current->correlation_id, 'attempts' => $current->attempts]);
            }
        });

        return $status;
    }

    private function handler(string $type): InboundEventHandler
    {
        // Event types contain dots: read the handler map as an array, never with config dot-notation.
        $class = config('peopleos.integration.handlers', [])[$type] ?? null;
        if ($class === null || ! is_subclass_of($class, InboundEventHandler::class)) {
            throw new IntegrationRejected("No handler for [{$type}] events.", 'unsupported_event_type');
        }

        return app($class);
    }

    private function correlationId(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $value) ? $value : (string) Str::ulid();
    }
}
