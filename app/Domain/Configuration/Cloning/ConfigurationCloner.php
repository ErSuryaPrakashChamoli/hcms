<?php

namespace App\Domain\Configuration\Cloning;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Models\ConfigurationCloneOperation;
use App\Domain\Configuration\Models\ConfigurationLineage;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The clone engine (configuration cloning, docs/architecture/configuration-cloning.md). plan() is read-only: it reads
 * the source, checks who may clone it where, rewrites its applicability for each destination, classifies its
 * dependencies and finds the conflicts. execute() makes the copies:
 *
 * 1. a wizard's idempotency key that already made a clone returns that clone (a double submit never copies twice);
 * 2. the plan is rebuilt; it is refused (and the refusal recorded) when the source changed since it was reviewed, a
 *    blocker remains or a warning was not acknowledged;
 * 3. one AuditRecorder::operation() wraps one database transaction (the transaction inside, so a failure rolls back
 *    first and its audit summary survives): the source is re-read under lock and must still match, each copy is made
 *    INACTIVE by its adapter through the module's own service, lineage is written and a CLONE event holds the manifest;
 * 4. any failure leaves no copy, marks the clone record failed and frees the key; a code another clone took meanwhile is
 *    reported as a conflict, never a server error.
 *
 * Nothing is ever merged into an existing record, and nothing propagates from a source to its copies later.
 */
final class ConfigurationCloner
{
    public function __construct(
        private readonly CloneRegistry $registry,
        private readonly CloneScope $scope,
        private readonly ApplicabilityRewriter $rewriter,
        private readonly ConflictDetector $detector,
        private readonly AuditRecorder $audit,
    ) {}

    public function plan(CloneRequest $request, User $actor): ClonePlan
    {
        $adapter = $this->registry->for($request->adapter);
        $source = $adapter->find($request->sourceId) ?? throw new CloneRefusedException('The record to clone no longer exists.');

        return $this->planFor($adapter, $source, $request, $actor);
    }

    /** @return list<int>|null the companies the source reaches today (null = every company) */
    public function sourceCompanies(CloneAdapter $adapter, Model $source, array $options = []): ?array
    {
        return $this->rewriter->reach($adapter->applicability(), $adapter->snapshot($source, $options)->applicability, $this->scope->allCompanyIds());
    }

    public function execute(CloneRequest $request, User $actor): CloneResult
    {
        $adapter = $this->registry->for($request->adapter);
        if (($done = $this->previous($request)) !== null) {
            return CloneResult::fromOperation($done, $adapter);
        }
        $source = $adapter->find($request->sourceId) ?? throw new CloneRefusedException('The record to clone no longer exists.');

        try {
            $plan = $this->planFor($adapter, $source, $request, $actor);
            if ($request->expectedChecksum !== null && ! hash_equals($request->expectedChecksum, $plan->checksum())) {
                throw new CloneRefusedException("{$plan->snapshot->label} changed since you started cloning it. Start the clone again to copy it as it is now.");
            }
            if ($plan->blockers() !== []) {
                throw CloneRefusedException::blocked($plan->blockers());
            }
            if ($plan->warnings() !== [] && ! $request->acknowledged) {
                throw new CloneRefusedException('Read the warnings and tick that you have, then create the copy.', $plan->warnings());
            }
        } catch (CloneRefusedException $refused) {
            $this->refuse($adapter, $source, $request, $actor, $plan ?? null, $refused);

            throw $refused;
        }

        try {
            $operation = $this->start($adapter, $source, $request, $actor, $plan);
        } catch (UniqueConstraintViolationException) {
            // The same wizard submitted twice at once: the other submit owns the clone.
            return CloneResult::fromOperation($this->previous($request) ?? throw new CloneRefusedException('This clone is already being made.'), $adapter);
        }

        $auditOperation = null;
        $copies = [];
        try {
            $this->audit->operation('configuration', 'Clone '.$plan->snapshot->label, function (string $operationId) use (&$auditOperation, &$copies, $adapter, $source, $plan, $operation, $actor) {
                $auditOperation = $operationId;

                return DB::transaction(function () use (&$copies, $adapter, $source, $plan, $operation, $actor, $operationId) {
                    $copies = $this->write($adapter, $source, $plan, $operation, $actor, $operationId);

                    return ['succeeded' => count($copies), 'ids' => array_map(fn (Model $m) => $m->getKey(), $copies)];
                });
            }, $plan->auditReason(), $adapter->key());
        } catch (UniqueConstraintViolationException) {
            $this->finish($operation, ConfigurationCloneOperation::FAILED, 'Another record took one of these codes while the copies were being made.', $auditOperation);

            throw new CloneRefusedException('Another record took one of these codes while the copies were being made. Choose another code and try again.');
        } catch (CloneRefusedException $refused) {
            $this->finish($operation, ConfigurationCloneOperation::REFUSED, $refused->getMessage(), $auditOperation);

            throw $refused;
        } catch (Throwable $failure) {
            $this->finish($operation, ConfigurationCloneOperation::FAILED, $failure->getMessage(), $auditOperation);

            throw $failure;
        }

        return new CloneResult($operation->refresh(), $copies);
    }

    private function planFor(CloneAdapter $adapter, Model $source, CloneRequest $request, User $actor): ClonePlan
    {
        $snapshot = $adapter->snapshot($source, $request->options);
        $companies = $this->scope->allCompanyIds();
        $kind = $adapter->applicability();
        $reach = $this->rewriter->reach($kind, $snapshot->applicability, $companies);
        $mode = $this->scope->authorise($actor, $adapter, $reach, $request->companyIds());

        $applicability = [];
        $blockers = [];
        foreach ($request->destinations as $destination) {
            $company = $destination->companyId;
            $variation = $reach === null || in_array($company, $reach, true);
            $rewritten = $this->rewriter->rewrite($kind, $snapshot->applicability, $company, $variation, $request->unitChoicesFor($company), $destination->priority, $companies);
            if ($rewritten['blockers'] === []) {
                $this->rewriter->assertBounded($kind, $rewritten['after'], [$company]);
            }
            $blockers = [...$blockers, ...$rewritten['blockers']];
            $applicability[$company] = ['before' => $snapshot->applicability, 'after' => $rewritten['after'], 'notes' => $rewritten['notes'],
                'mapped' => $rewritten['mapped'], 'unresolved' => $rewritten['unresolved']];
        }
        $plan = new ClonePlan($request, $snapshot, $mode, $reach, $adapter->references($snapshot), $applicability);

        $impact = [];
        foreach ($request->destinations as $destination) {
            if (($found = $adapter->impact($snapshot, $destination, $plan)) !== null) {
                $impact[$destination->companyId] = $found;
            }
        }
        $plan = $plan->withImpact($impact);

        return $plan->withConflicts([...$blockers, ...$this->detector->detect($adapter, $plan)]);
    }

    /** @return list<Model> the copies */
    private function write(CloneAdapter $adapter, Model $source, ClonePlan $plan, ConfigurationCloneOperation $operation, User $actor, string $operationId): array
    {
        // The source as it is now, read under lock: it must still be what the plan copied.
        $locked = $adapter->model()::query()->whereKey($source->getKey())->lockForUpdate()->first();
        if ($locked === null || $adapter->snapshot($locked, $plan->request->options)->checksum() !== $plan->checksum()) {
            throw new CloneRefusedException("{$plan->snapshot->label} changed while the copies were being made. Start the clone again.");
        }

        $copies = [];
        $created = [];
        foreach ($plan->request->destinations as $destination) {
            $company = $destination->companyId;
            $this->rewriter->assertBounded($adapter->applicability(), $plan->applicabilityFor($company), [$company]);
            $made = $adapter->materialise($plan->snapshot, $destination, $plan, $actor);
            $copies[] = $made->root;
            $created[$company] = $made->root->getKey();

            $this->lineage($operation, $adapter->key(), (int) $source->getKey(), (int) $made->root->getKey(), $company, ConfigurationLineage::ROOT, ConfigurationLineage::CLONED, $plan->checksum());
            foreach ($made->children as $child) {
                $this->lineage($operation, $child['adapter'], $child['source_id'], $child['target_id'], $company, ConfigurationLineage::DEPENDENCY, ConfigurationLineage::CLONED);
            }
            foreach ($plan->references as $reference) {
                if ($reference->strategy === DependencyStrategy::Reuse && $reference->targetId !== null) {
                    $this->lineage($operation, $reference->type, $reference->targetId, $reference->targetId, $company, ConfigurationLineage::DEPENDENCY, ConfigurationLineage::REUSED);
                }
            }
            foreach ((array) ($plan->applicability[$company]['mapped'] ?? []) as $mapped) {
                $this->lineage($operation, $mapped['field'], $mapped['source_id'], $mapped['target_id'], $company, ConfigurationLineage::DEPENDENCY, ConfigurationLineage::MAPPED);
            }
        }

        $result = ['created' => $created, 'reused' => count(array_filter($plan->references, fn (Reference $r) => $r->strategy === DependencyStrategy::Reuse)),
            'mapped' => array_sum(array_map(fn (array $a) => count($a['mapped'] ?? []), $plan->applicability)),
            'notes' => array_map(fn (array $a) => $a['notes'] ?? [], $plan->applicability)];
        $this->audit->record(AuditAction::Clone, 'configuration', $source, [], $plan->auditReason(), metadata: [
            'status' => ConfigurationCloneOperation::COMPLETED, 'clone' => $operation->ulid, 'adapter' => $adapter->key(), 'source' => $plan->snapshot->label,
            'source_checksum' => $plan->checksum(), 'created' => $created,
        ] + $plan->toArray());
        $operation->update(['status' => ConfigurationCloneOperation::COMPLETED, 'result' => $result, 'audit_operation_id' => $operationId, 'completed_at' => now()]);

        return $copies;
    }

    private function lineage(ConfigurationCloneOperation $operation, string $adapter, ?int $sourceId, ?int $targetId, int $company, string $role, string $action, ?string $checksum = null): void
    {
        ConfigurationLineage::create(['operation_id' => $operation->id, 'adapter' => $adapter, 'source_id' => $sourceId, 'target_id' => $targetId,
            'destination_company_id' => $company, 'role' => $role, 'action' => $action, 'source_checksum' => $checksum]);
    }

    private function previous(CloneRequest $request): ?ConfigurationCloneOperation
    {
        return $request->idempotencyKey === null ? null
            : ConfigurationCloneOperation::query()->where('idempotency_key', $request->idempotencyKey)->first();
    }

    private function start(CloneAdapter $adapter, Model $source, CloneRequest $request, User $actor, ClonePlan $plan): ConfigurationCloneOperation
    {
        // Its own short transaction, so a second submit of the same wizard finds it (or collides on the key).
        return DB::transaction(fn () => ConfigurationCloneOperation::create($this->record($adapter, $source, $request, $actor, $plan->snapshot, $plan) + [
            'idempotency_key' => $request->idempotencyKey, 'status' => ConfigurationCloneOperation::RUNNING,
        ]));
    }

    /** A refused attempt is kept too (without the key, so the wizard may try again once it is fixed), and audited. */
    private function refuse(CloneAdapter $adapter, Model $source, CloneRequest $request, User $actor, ?ClonePlan $plan, CloneRefusedException $refused): void
    {
        $snapshot = $plan?->snapshot ?? $adapter->snapshot($source, $request->options);
        $operation = ConfigurationCloneOperation::create($this->record($adapter, $source, $request, $actor, $snapshot, $plan) + [
            'idempotency_key' => null, 'status' => ConfigurationCloneOperation::REFUSED, 'error' => $refused->getMessage(), 'completed_at' => now(),
            'result' => ['conflicts' => array_map(fn (Conflict $c) => $c->toArray(), $refused->conflicts)],
        ]);
        $this->audit->record(AuditAction::Clone, 'configuration', $source, [], trim($request->reason) ?: null, metadata: [
            'status' => ConfigurationCloneOperation::REFUSED, 'clone' => $operation->ulid, 'adapter' => $adapter->key(), 'source' => $snapshot->label,
            'destinations' => $request->companyIds(), 'error' => $refused->getMessage(),
        ]);
    }

    private function finish(ConfigurationCloneOperation $operation, string $status, string $error, ?string $auditOperation): void
    {
        $operation->update(['status' => $status, 'error' => Str::limit($error, 2000), 'idempotency_key' => null, 'audit_operation_id' => $auditOperation, 'completed_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function record(CloneAdapter $adapter, Model $source, CloneRequest $request, User $actor, Snapshot $snapshot, ?ClonePlan $plan): array
    {
        $reach = $plan?->sourceCompanies ?? $this->rewriter->reach($adapter->applicability(), $snapshot->applicability, $this->scope->allCompanyIds());

        return [
            'ulid' => (string) Str::ulid(),
            'adapter' => $adapter->key(),
            'mode' => ($plan?->mode ?? $this->scope->mode($reach, $request->companyIds() ?: [0]))->value,
            'source_id' => (int) $source->getKey(),
            'source_label' => Str::limit($snapshot->label, 250),
            'source_company_ids' => $reach,
            'source_checksum' => $snapshot->checksum(),
            'source_snapshot' => $snapshot->toArray(),
            'destination_company_ids' => $request->companyIds(),
            'plan' => $plan?->toArray() ?? ['request' => $request->toArray()],
            'initiated_by' => $actor->getKey(),
            'started_at' => now(),
        ];
    }
}
