<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The single write path into the audit log.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Guard $auth,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<int, array{field: string, before: mixed, after: mixed, sensitive?: bool}>  $changes
     * @param  array<string, mixed>  $metadata
     * @param  bool  $anonymous  Phase 13: record the event without anything that identifies who caused it
     *                           (no actor, IP, user agent or request id) — used for anonymous survey
     *                           responses and anonymous feedback, whose content must never be linkable to a person
     */
    public function record(
        AuditAction $action,
        string $module,
        ?Model $entity = null,
        array $changes = [],
        ?string $reason = null,
        ?string $approvalReference = null,
        CarbonInterface|string|null $effectiveDate = null,
        array $metadata = [],
        ?string $entityLabel = null,
        ?int $tenantId = null,
        ?User $actor = null,
        ?string $operationId = null,
        bool $anonymous = false,
    ): AuditEvent {
        $tenantId ??= ($entity?->getAttributes()['tenant_id'] ?? null) ?? $this->tenants->id();
        $actor = $anonymous ? null : ($actor ?? $this->resolveActor());

        $normalisedChanges = array_values(array_map(fn (array $change) => [
            'field' => $change['field'],
            'before' => $this->stringify($change['before'] ?? null, $change['sensitive'] ?? false),
            'after' => $this->stringify($change['after'] ?? null, $change['sensitive'] ?? false),
            'is_sensitive' => (bool) ($change['sensitive'] ?? false),
        ], $changes));

        $attributes = [
            'tenant_id' => $tenantId,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'actor_roles' => $actor?->roleSlugs(),
            'action' => $action->value,
            'module' => $module,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity ? (string) $entity->getKey() : null,
            'entity_label' => $entityLabel ?? $this->labelFor($entity),
            'ip_address' => $anonymous ? null : $this->request->ip(),
            'user_agent' => $anonymous ? null : Str::limit((string) $this->request->userAgent(), 500, ''),
            'source' => $anonymous ? 'anonymous' : $this->resolveSource(),
            'request_id' => $anonymous ? null : Context::get('request_id'),
            'operation_id' => $operationId ?? Context::get('audit.operation_id'),
            'reason' => $reason,
            'approval_reference' => $approvalReference,
            'effective_date' => $effectiveDate ? Carbon::parse($effectiveDate)->toDateString() : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ];

        // Writes cross tenant boundaries by design (platform events have no tenant), so the
        // tenant scope is bypassed here and only here.
        return $this->tenants->bypass(fn () => DB::transaction(function () use ($attributes, $normalisedChanges) {
            // Phase 13: chain writers queue on their chain's row in audit_chain_locks first.
            // - Before, they met on the "last audit row FOR UPDATE" read. Its next-key / gap locks let two
            //   writers deadlock on MySQL (one inserting into the gap the other held while waiting).
            // - The tenants row is no alternative: every insert's foreign-key check holds a shared lock on
            //   it.
            // - No foreign key points at audit_chain_locks, so only chain writers ever lock its rows.
            // The serialisation point is unchanged. The last-hash read below stays a locking (current)
            // read, so it never reads from an older snapshot.
            $this->lockChain($attributes['tenant_id']);
            $previous = AuditEvent::query()
                ->when(
                    $attributes['tenant_id'] === null,
                    fn ($q) => $q->whereNull('tenant_id'),
                    fn ($q) => $q->where('tenant_id', $attributes['tenant_id']),
                )
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'hash']);
            $previousHash = $previous?->hash;

            // Phase 13: the id (a time-ordered ULID) and the time are taken here, inside the chain lock.
            // Taken before it, a writer that waited for the lock could carry an earlier id than the event
            // it links to, and the chain (verified in id order) would read as broken under concurrent
            // writes. The id is also kept strictly after the previous event's id.
            $occurredAt = Carbon::now();
            $attributes['id'] = $this->nextId($previous?->id);
            $attributes['occurred_at'] = $occurredAt->format('Y-m-d H:i:s.u');
            $attributes['previous_hash'] = $previousHash;
            $attributes['hash'] = AuditEvent::computeHash(
                $previousHash,
                AuditEvent::canonicalPayload($attributes, $normalisedChanges),
            );
            $attributes['created_at'] = $occurredAt;

            $event = new AuditEvent($attributes);
            $event->save();

            if ($normalisedChanges !== []) {
                $event->fieldChanges()->createMany($normalisedChanges);
            }

            return $event;
        }));
    }

    /** A ULID that sorts after the chain's previous event (a new millisecond is awaited in the rare same-millisecond case). */
    private function nextId(?string $previousId): string
    {
        $id = (string) Str::ulid();
        for ($try = 0; $previousId !== null && strcmp($id, $previousId) <= 0 && $try < 50; $try++) {
            usleep(1000);
            $id = (string) Str::ulid();
        }

        return $id;
    }

    /** One row per chain ("tenant:{id}" or "platform"); created on a chain's first event. */
    private function lockChain(?int $tenantId): void
    {
        $chain = $tenantId === null ? 'platform' : 'tenant:'.$tenantId;
        $lock = fn () => DB::table('audit_chain_locks')->where('chain', $chain)->lockForUpdate()->exists();
        if (! $lock()) {
            DB::table('audit_chain_locks')->insertOrIgnore(['chain' => $chain]);
            $lock();
        }
    }

    /**
     * Run a callback with every audit event inside carrying the given operation id (no summary row).
     * Phase 12: a service request's domain execution shares the request's operation id, so the
     * domain's own audit trail answers "which request caused this change".
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withinOperation(string $operationId, callable $callback): mixed
    {
        $previous = Context::get('audit.operation_id');
        Context::add('audit.operation_id', $operationId);
        try {
            return $callback();
        } finally {
            $previous === null ? Context::forget('audit.operation_id') : Context::add('audit.operation_id', $previous);
        }
    }

    /**
     * Run a bulk operation: every audit event recorded inside carries the same operation id and a
     * BULK_OPERATION summary (counts, entity type, reason) closes it. The callback returns
     * ['succeeded' => n, 'failed' => n] (or an int treated as succeeded) and may throw; the
     * summary is still written with what was counted.
     *
     * @template T
     *
     * @param  callable(string $operationId): (array{succeeded?: int, failed?: int, ids?: list<int|string>}|int|null)  $callback
     */
    public function operation(string $module, string $label, callable $callback, ?string $reason = null, ?string $entityType = null): string
    {
        $operationId = (string) Str::ulid();
        $previous = Context::get('audit.operation_id');
        Context::add('audit.operation_id', $operationId);

        $counts = ['succeeded' => 0, 'failed' => 0];
        $ids = [];

        try {
            $result = $callback($operationId);
            if (is_int($result)) {
                $counts['succeeded'] = $result;
            } elseif (is_array($result)) {
                $counts['succeeded'] = (int) ($result['succeeded'] ?? 0);
                $counts['failed'] = (int) ($result['failed'] ?? 0);
                $ids = array_values($result['ids'] ?? []);
            }
        } catch (\Throwable $e) {
            $counts['failed'] = max($counts['failed'], 1);
            $this->recordOperationSummary($module, $label, $operationId, $counts, $ids, $reason, $entityType, $e->getMessage());
            Context::add('audit.operation_id', $previous);
            throw $e;
        }

        $this->recordOperationSummary($module, $label, $operationId, $counts, $ids, $reason, $entityType);
        Context::add('audit.operation_id', $previous);

        return $operationId;
    }

    /** @param  array{succeeded: int, failed: int}  $counts */
    private function recordOperationSummary(string $module, string $label, string $operationId, array $counts, array $ids, ?string $reason, ?string $entityType, ?string $error = null): void
    {
        $this->record(
            AuditAction::BulkOperation,
            $module,
            null,
            [],
            $reason,
            metadata: array_filter([
                'label' => $label,
                'entity_type' => $entityType,
                'entity_count' => $counts['succeeded'] + $counts['failed'],
                'success_count' => $counts['succeeded'],
                'failure_count' => $counts['failed'],
                'affected_ids' => $ids === [] ? null : $ids,
                'error' => $error,
            ], fn ($v) => $v !== null),
            entityLabel: $label,
            operationId: $operationId,
        );
    }

    private function resolveActor(): ?User
    {
        $user = $this->auth->user();

        return $user instanceof User ? $user : null;
    }

    private function resolveSource(): string
    {
        if ($source = Context::get('audit.source')) {
            return $source;
        }

        if (app()->runningInConsole()) {
            return app()->runningUnitTests() ? 'test' : 'console';
        }

        return $this->request->is('api/*') ? 'api' : 'web';
    }

    private function labelFor(?Model $entity): ?string
    {
        if ($entity === null) {
            return null;
        }

        if (method_exists($entity, 'auditLabel')) {
            return $entity->auditLabel();
        }

        $attributes = $entity->getAttributes();

        foreach (['name', 'title', 'code', 'key', 'email'] as $attribute) {
            if (! empty($attributes[$attribute])) {
                return (string) $attributes[$attribute];
            }
        }

        return null;
    }

    private function stringify(mixed $value, bool $sensitive): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($sensitive) {
            return config('peopleos.audit.mask');
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof UnitEnum => (string) ($value->value ?? $value->name),
            $value instanceof DateTimeInterface => Carbon::instance($value)->toIso8601String(),
            is_array($value) || is_object($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
