<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Enums\ExportStatus;
use App\Domain\Offboarding\Enums\OffboardingStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Offboarding\Support\OffboardingAudit;
use App\Domain\Offboarding\Support\SchemaGraph;
use App\Domain\Offboarding\Support\Signatures;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * SaaS.9: executes an approved deletion, step by step, safely resumable.
 *
 * Before anything: deletion must be enabled for the environment; the deletion is claimed (approved, due → in_progress)
 * with a conditional update, so two workers never both run it; the start is recorded under the exit lock (the tenant can
 * no longer be reopened); and the plan is evaluated again: any gate stops the run (failed, with the reason).
 *
 * Each step deletes only what BOTH the approved plan and today's plan allow for its category; otherwise it is skipped
 * and says why. Every batch is one short transaction that first takes the exit lock and re-checks the legal holds: a
 * hold placed meanwhile stops the run before the next row (failed; a retry plans again around the hold). Batches are
 * bounded (peopleos.offboarding.batch_size rows), constraint checks stay on, and each step records its progress, so a
 * worker that dies is resumed by a retry from where it stopped; a finished step is never repeated, and repeating a
 * half-finished one deletes only what is left.
 *
 * The audit chain is deleted before the accounts it names (so no reference on a remaining chain row is ever nulled),
 * newest event first (what remains is always a valid prefix of the chain), after its final hash is recorded. The run ends with a verification that no reference into a deleted table is left dangling, then the
 * certificate (signed). The tenant becomes `deleted` only when nothing of the customer's data remains.
 */
final class DeletionExecutor
{
    public function __construct(
        private readonly DeletionPlanner $planner,
        private readonly OffboardingAudit $audit,
        private readonly TenantContext $tenants,
    ) {}

    public function execute(int $deletionId): bool
    {
        if (! TenantDeletions::enabled()) {
            return false;
        }
        $claimed = $this->tenants->bypass(fn () => TenantDeletion::query()->whereKey($deletionId)->where('status', DeletionStatus::Approved->value)
            ->where('scheduled_for', '<=', now())
            ->update(['status' => DeletionStatus::InProgress->value, 'attempts' => DB::raw('attempts + 1'), 'heartbeat_at' => now(), 'updated_at' => now()]));
        if ($claimed !== 1) {
            return false;
        }
        $deletion = $this->find($deletionId);
        $tenant = Tenant::query()->findOrFail($deletion->tenant_id);

        try {
            $first = $this->start($deletion, $tenant);
            if ($first) {
                $this->audit->record(AuditAction::DeletionStarted, $tenant, $deletion, 'Deletion started', null, ['deletion' => $deletion->reference]);
            }
            $case = $this->tenants->bypass(fn () => TenantOffboarding::query()->findOrFail($deletion->offboarding_id));
            $current = $this->planner->plan($tenant, $case, $deletion);
            if ($current['gates'] !== []) {
                throw new RuntimeException('Stopped before deleting: '.implode(' ', array_column($current['gates'], 'detail')));
            }
            foreach ($this->pendingSteps($deletion) as $step) {
                $this->runStep($deletion, $tenant, $step, $current);
            }
            $this->finish($deletion, $tenant, $case);
        } catch (Throwable $e) {
            report($e);
            $this->fail($deletion, $tenant, $e);
        }

        return true;
    }

    /**
     * Records the first start under the exit lock; refuses a tenant that is no longer closed, and an export still being
     * generated (it would package data while it is deleted). Once started, no export is requested and no reopening.
     */
    private function start(TenantDeletion $deletion, Tenant $tenant): bool
    {
        return DB::transaction(function () use ($deletion, $tenant) {
            $case = LegalHolds::lockFor($tenant->getKey());
            if ($case === null || $case->getKey() !== (int) $deletion->offboarding_id || $case->status !== OffboardingStatus::Closed
                || Tenant::query()->whereKey($tenant->getKey())->value('status') !== TenantStatus::Closed) {
                throw new RuntimeException('The tenant is no longer closed under this offboarding: nothing deleted.');
            }
            if ($this->tenants->bypass(fn () => TenantExport::query()->where('tenant_id', $tenant->getKey())->whereIn('status', ExportStatus::inFlightValues())->exists())) {
                throw new RuntimeException('An export of this tenant is being generated: nothing deleted; retry once it is ready.');
            }
            $first = $case->deletion_started_at === null;
            $this->tenants->bypass(fn () => TenantOffboarding::query()->whereKey($case->getKey())->whereNull('deletion_started_at')->update(['deletion_started_at' => now()]));
            $this->tenants->bypass(fn () => TenantDeletion::query()->whereKey($deletion->getKey())->whereNull('started_at')->update(['started_at' => now()]));

            return $first;
        });
    }

    /** @return list<TenantDeletionStep> */
    private function pendingSteps(TenantDeletion $deletion): array
    {
        return $this->tenants->bypass(fn () => TenantDeletionStep::query()->where('tenant_deletion_id', $deletion->getKey())
            ->where('status', TenantDeletionStep::PENDING)->orderBy('position')->get()->all());
    }

    private function runStep(TenantDeletion $deletion, Tenant $tenant, TenantDeletionStep $step, array $current): void
    {
        $approved = $deletion->plan['categories'][$step->category]['action'] ?? null;
        $now = $current['categories'][$step->category]['action'] ?? null;
        if ($step->kind !== 'verify' && $now === 'none' && in_array($approved, [DataInventory::DELETE, DataInventory::ANONYMISE], true)) {
            // Resumed after this category was already emptied: nothing is left to do.
            $this->updateStep($step, ['status' => TenantDeletionStep::DONE, 'completed_at' => now()]);

            return;
        }
        if ($step->kind !== 'verify' && ($approved === null || $approved !== $now || ! in_array($now, [DataInventory::DELETE, DataInventory::ANONYMISE], true))) {
            $this->updateStep($step, ['status' => TenantDeletionStep::SKIPPED, 'completed_at' => now(),
                'detail' => json_encode(['reason' => 'Category no longer eligible: '.($current['categories'][$step->category]['reason'] ?? 'unknown').' — '.($current['categories'][$step->category]['detail'] ?? '')])]);

            return;
        }
        $this->updateStep($step, ['started_at' => $step->started_at ?? now()]);
        match ($step->kind) {
            'files' => $this->deleteReferencedFiles($tenant, $step),
            'children' => $this->deleteRows($tenant, $step, $step->target),
            'nullify' => $this->nullify($tenant, $step),
            'rows' => $this->deleteRows($tenant, $step, $step->target),
            'anonymise' => $this->anonymise($tenant, $step),
            'stored' => $this->deleteStored($tenant, $step),
            'audit' => $this->deleteAuditChain($tenant, $step),
            'verify' => $this->verify($tenant, $deletion, $step),
            default => throw new RuntimeException("Unknown deletion step [{$step->kind}]."),
        };
        $this->updateStep($step, ['status' => TenantDeletionStep::DONE, 'completed_at' => now()]);
    }

    /**
     * One batch = one short transaction: the exit lock, the hold check for the step's category, then the work.
     *
     * @param  callable(): int  $work  returns how many rows / files it processed (0 = done)
     */
    private function batch(Tenant $tenant, TenantDeletionStep $step, callable $work): int
    {
        return DB::transaction(function () use ($tenant, $step, $work) {
            LegalHolds::lockFor($tenant->getKey());
            $held = app(LegalHolds::class)->covering($tenant->getKey(), $step->category);
            if ($held !== []) {
                throw new LegalHoldEncountered('A legal hold ('.implode(', ', $held).") now covers {$step->category}: the deletion stopped before deleting more.");
            }
            $n = $work();
            if ($n > 0) {
                $this->tenants->bypass(fn () => TenantDeletionStep::query()->whereKey($step->getKey())->update(['processed_count' => DB::raw('processed_count + '.$n), 'updated_at' => now()]));
                $this->tenants->bypass(fn () => TenantDeletion::query()->whereKey($step->tenant_deletion_id)->update(['heartbeat_at' => now()]));
            }

            return $n;
        });
    }

    private function deleteRows(Tenant $tenant, TenantDeletionStep $step, string $table): void
    {
        $size = (int) config('peopleos.offboarding.batch_size', 1000);
        $hasId = Schema::hasColumn($table, 'id');
        do {
            $n = $this->batch($tenant, $step, function () use ($tenant, $table, $size, $hasId) {
                if (! $hasId) {
                    return (int) ExportPackager::rowsOf($tenant, $table)->limit($size)->delete();
                }
                $ids = ExportPackager::rowsOf($tenant, $table)->orderBy('id')->limit($size)->pluck('id');

                return $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids->all())->delete();
            });
        } while ($n > 0);
    }

    private function nullify(Tenant $tenant, TenantDeletionStep $step): void
    {
        [$table, $column] = explode('.', $step->target, 2);
        $size = (int) config('peopleos.offboarding.batch_size', 1000);
        do {
            $n = $this->batch($tenant, $step, function () use ($tenant, $table, $column, $size) {
                $ids = ExportPackager::rowsOf($tenant, $table)->whereNotNull($column)->orderBy('id')->limit($size)->pluck('id');

                return $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids->all())->update([$column => null]);
            });
        } while ($n > 0);
    }

    private function anonymise(Tenant $tenant, TenantDeletionStep $step): void
    {
        $table = $step->target;
        $spec = DataInventory::ANONYMISED_COLUMNS[$table] ?? throw new RuntimeException("No anonymisation for {$table}.");
        $size = (int) config('peopleos.offboarding.batch_size', 1000);
        $unusable = Hash::make(Str::random(64)); // one unusable password for the batch: nobody knows its secret
        do {
            $n = $this->batch($tenant, $step, function () use ($tenant, $table, $spec, $size, $unusable) {
                $rows = ExportPackager::rowsOf($tenant, $table)->where('email', 'not like', 'deleted-user-%@deleted.invalid')->orderBy('id')->limit($size)->pluck('id');
                foreach ($rows as $id) {
                    $values = [];
                    foreach ($spec as $column => $rule) {
                        $values[$column] = match (true) {
                            $rule === 'null' => null,
                            $rule === 'random' => $unusable,
                            str_starts_with($rule, 'fixed:') => substr($rule, 6),
                            str_starts_with($rule, 'id:') => str_replace('{id}', (string) $id, substr($rule, 3)),
                            default => throw new RuntimeException("Unknown anonymisation rule [{$rule}]."),
                        };
                    }
                    DB::table($table)->where('id', $id)->update($values + ['updated_at' => now()]);
                }

                return $rows->count();
            });
        } while ($n > 0);
    }

    private function deleteReferencedFiles(Tenant $tenant, TenantDeletionStep $step): void
    {
        $table = $step->target;
        $columns = DataInventory::FILES[$table];
        $lastId = (int) ($step->detail['last_id'] ?? 0);
        $missing = (int) ($step->detail['missing'] ?? 0);
        do {
            $n = $this->batch($tenant, $step, function () use ($tenant, $table, $columns, &$lastId, &$missing) {
                $select = array_values(array_unique(array_merge(['id'], array_column($columns, 'path'), array_filter(array_column($columns, 'disk_column')))));
                $rows = ExportPackager::rowsOf($tenant, $table)->where('id', '>', $lastId)->orderBy('id')->limit(200)->get($select);
                foreach ($rows as $row) {
                    foreach ($columns as $column) {
                        $path = $row->{$column['path']} ?? null;
                        if ($path === null || $path === '') {
                            continue;
                        }
                        $disk = isset($column['disk_column']) ? (string) ($row->{$column['disk_column']} ?: DataInventory::disk('documents')) : DataInventory::disk($column['disk']);
                        if (Storage::disk($disk)->exists($path)) {
                            if (! Storage::disk($disk)->delete($path)) {
                                throw new RuntimeException("A file of {$table} #{$row->id} could not be deleted.");
                            }
                        } else {
                            $missing++;
                        }
                    }
                    $lastId = (int) $row->id;
                }

                return $rows->count();
            });
            $this->updateStep($step, ['detail' => json_encode(['last_id' => $lastId, 'missing' => $missing])]);
        } while ($n > 0);
    }

    private function deleteStored(Tenant $tenant, TenantDeletionStep $step): void
    {
        [$disk, $prefix] = explode('::', $step->target, 2);
        do {
            $n = $this->batch($tenant, $step, function () use ($disk, $prefix) {
                $files = array_slice(Storage::disk($disk)->allFiles($prefix), 0, 500);
                if ($files !== [] && ! Storage::disk($disk)->delete($files)) {
                    throw new RuntimeException("Stored files under {$prefix} could not be deleted.");
                }

                return count($files);
            });
        } while ($n > 0);
        Storage::disk($disk)->deleteDirectory($prefix);
    }

    /** The tenant's audit chain, newest first, after its final hash is recorded. Through the query builder: the one sanctioned delete of audit rows. */
    private function deleteAuditChain(Tenant $tenant, TenantDeletionStep $step): void
    {
        if (! isset($step->detail['final_hash'])) {
            $head = DB::table('audit_events')->where('tenant_id', $tenant->getKey())->orderByDesc('id')->first(['id', 'hash']);
            $this->updateStep($step, ['detail' => json_encode(['final_event' => $head?->id, 'final_hash' => $head?->hash,
                'events' => DB::table('audit_events')->where('tenant_id', $tenant->getKey())->count()])]);
        }
        $size = (int) config('peopleos.offboarding.batch_size', 1000);
        $chain = 'tenant:'.$tenant->getKey();
        do {
            $n = $this->batch($tenant, $step, function () use ($tenant, $size, $chain) {
                DB::table('audit_chain_locks')->where('chain', $chain)->lockForUpdate()->exists();
                $ids = DB::table('audit_events')->where('tenant_id', $tenant->getKey())->orderByDesc('id')->limit($size)->pluck('id');
                if ($ids->isEmpty()) {
                    return 0;
                }
                DB::table('audit_event_changes')->whereIn('audit_event_id', $ids->all())->delete();

                return DB::table('audit_events')->whereIn('id', $ids->all())->delete();
            });
        } while ($n > 0);
    }

    /** No reference into a table this deletion emptied may be left dangling, anywhere. */
    private function verify(Tenant $tenant, TenantDeletion $deletion, TenantDeletionStep $step): void
    {
        $deletedTables = $this->tenants->bypass(fn () => TenantDeletionStep::query()->where('tenant_deletion_id', $deletion->getKey())
            ->whereIn('kind', ['rows', 'children'])->where('status', TenantDeletionStep::DONE)->pluck('target')->all());
        $checked = 0;
        $orphans = [];
        foreach (SchemaGraph::edges() as $edge) {
            if (! in_array($edge['parent'], $deletedTables, true)) {
                continue;
            }
            $checked++;
            $query = DB::table($edge['child'].' as c')->whereNotNull('c.'.$edge['column'])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($edge['parent'].' as p')->whereColumn('p.'.$edge['parent_column'], 'c.'.$edge['column']));
            if (Schema::hasColumn($edge['child'], 'tenant_id')) {
                $query->where('c.tenant_id', $tenant->getKey());
            }
            if (($n = (int) $query->count()) > 0) {
                $orphans[] = "{$edge['child']}.{$edge['column']} → {$edge['parent']}: {$n}";
            }
        }
        $this->updateStep($step, ['processed_count' => $checked, 'detail' => json_encode(['references_checked' => $checked, 'dangling' => $orphans])]);
        if ($orphans !== []) {
            throw new RuntimeException('Dangling references after deletion: '.implode('; ', $orphans));
        }
    }

    private function finish(TenantDeletion $deletion, Tenant $tenant, TenantOffboarding $case): void
    {
        $after = $this->planner->plan($tenant, $case, $deletion);
        $remaining = array_keys(array_filter($after['categories'], fn (array $c) => $c['owner'] === 'tenant' && $c['action'] !== 'none'
            && ! ($c['action'] === DataInventory::ANONYMISE && $this->onlyAnonymisedLeft($c))));
        $final = $remaining === [];
        $steps = $this->tenants->bypass(fn () => TenantDeletionStep::query()->where('tenant_deletion_id', $deletion->getKey())->orderBy('position')->get());
        $certificate = $this->certificate($deletion, $tenant, $case, $steps->all(), $after, $final);
        $signature = Signatures::sign('deletion-certificate', $certificate);

        DB::transaction(function () use ($deletion, $tenant, $case, $final, $certificate, $signature) {
            LegalHolds::lockFor($tenant->getKey()); // the lock order every exit transition uses (tenant, case, then the rest)
            $this->tenants->bypass(fn () => TenantDeletion::query()->whereKey($deletion->getKey())->update([
                'status' => DeletionStatus::Completed->value, 'in_flight_key' => null, 'completed_at' => now(), 'heartbeat_at' => now(),
                'certificate' => json_encode($certificate), 'certificate_sha256' => hash('sha256', Signatures::canonical($certificate)),
                'certificate_signature' => $signature, 'certified_at' => now(), 'updated_at' => now(),
            ]));
            if ($final) {
                Tenant::query()->whereKey($tenant->getKey())->where('status', TenantStatus::Closed->value)->update(['status' => TenantStatus::Deleted->value, 'updated_at' => now()]);
                $this->tenants->bypass(fn () => TenantOffboarding::query()->whereKey($case->getKey())->update(['status' => OffboardingStatus::Completed->value,
                    'open_key' => null, 'completed_at' => now(), 'updated_at' => now()]));
            }
        });
        $tenant->refresh();
        $deletion = $this->find($deletion->getKey());
        $meta = ['deletion' => $deletion->reference, 'certificate_sha256' => $deletion->certificate_sha256, 'final' => $final,
            'deleted' => $certificate['categories']['deleted'] === [] ? [] : array_column($certificate['categories']['deleted'], 'category')];
        $this->audit->record(AuditAction::DeletionCompleted, $tenant, $deletion, 'Deletion completed', null, $meta, tenantChain: ! $this->auditChainDeleted($deletion));
        $this->audit->record(AuditAction::DeletionCertified, $tenant, $deletion, 'Deletion certificate issued', null, $meta, tenantChain: ! $this->auditChainDeleted($deletion));
        if ($final) {
            $this->audit->record(AuditAction::OffboardingCompleted, $tenant, $case, 'Offboarding completed: no customer data remains', null, ['offboarding' => $case->reference], tenantChain: false);
        }
    }

    private function onlyAnonymisedLeft(array $category): bool
    {
        foreach ($category['tables'] as $table => $count) {
            if ($count > 0 && ! in_array($table, $category['anonymised_tables'], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<TenantDeletionStep>  $steps */
    private function certificate(TenantDeletion $deletion, Tenant $tenant, TenantOffboarding $case, array $steps, array $after, bool $final): array
    {
        $byCategory = [];
        $audit = null;
        $verification = null;
        $external = [];
        foreach ($steps as $step) {
            if ($step->kind === 'external') {
                $external[] = ['target' => $step->target, 'status' => $step->status, 'note' => $step->detail['note'] ?? null];

                continue;
            }
            if ($step->kind === 'verify') {
                $verification = $step->detail;

                continue;
            }
            if ($step->kind === 'audit') {
                $audit = ['final_event' => $step->detail['final_event'] ?? null, 'final_hash' => $step->detail['final_hash'] ?? null, 'events_deleted' => $step->processed_count];
            }
            $entry = &$byCategory[$step->category];
            $entry['category'] = $step->category;
            $entry['action'] = $deletion->plan['categories'][$step->category]['action'] ?? null;
            $entry['steps'][] = ['kind' => $step->kind, 'target' => $step->target, 'status' => $step->status, 'processed' => $step->processed_count];
            unset($entry);
        }
        $deleted = array_values(array_filter($byCategory, fn (array $c) => $c['action'] === DataInventory::DELETE));
        $anonymised = array_values(array_filter($byCategory, fn (array $c) => $c['action'] === DataInventory::ANONYMISE));
        // Retained: every category that still holds data after this run (kept by retention, a hold, a dependency, ownership).
        $retained = [];
        foreach ($after['categories'] as $key => $c) {
            if ($c['action'] === DataInventory::RETAIN) {
                $retained[] = ['category' => $key, 'reason' => $c['reason'], 'detail' => $c['detail'], 'until' => $c['retention']['until'] ?? null,
                    'legal_minimum' => $c['retention']['legal_minimum'] ?? null, 'holds' => $c['holds'], 'protected_by' => $c['protected_by']];
            }
        }
        $export = app(TenantContext::class)->bypass(fn () => TenantExport::query()->where('tenant_id', $tenant->getKey())->whereNotNull('delivered_at')->orderByDesc('id')->first());

        return [
            'certificate' => 'PeopleOS tenant deletion certificate',
            'version' => 1,
            'issued_at' => now()->toIso8601String(),
            'tenant' => ['id' => $tenant->getKey(), 'slug' => $tenant->slug, 'name' => $tenant->name, 'status_after' => $final ? TenantStatus::Deleted->value : TenantStatus::Closed->value],
            'offboarding' => ['reference' => $case->reference, 'closed_at' => $case->closed_at?->toIso8601String(), 'customer_reference' => $case->customer_reference],
            'deletion' => ['reference' => $deletion->reference, 'plan_sha256' => $deletion->plan_sha256, 'requested_by' => $this->operator($deletion->requested_by),
                'requested_at' => $deletion->requested_at?->toIso8601String(), 'approved_by' => $this->operator($deletion->approved_by), 'approved_at' => $deletion->approved_at?->toIso8601String(),
                'started_at' => $deletion->started_at?->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'attempts' => $deletion->attempts,
                'executed_by' => 'PeopleOS deletion worker (queued job ExecuteTenantDeletion)'],
            'categories' => ['deleted' => $deleted, 'anonymised' => $anonymised, 'retained' => $retained],
            'legal_holds' => array_map(fn ($h) => ['reference' => $h->reference, 'scope' => $h->scope, 'categories' => $h->categories], app(LegalHolds::class)->holding($tenant->getKey())),
            'export' => $case->export_waived_at !== null ? ['waived_at' => $case->export_waived_at->toIso8601String(), 'reason' => $case->export_waiver_reason]
                : ['reference' => $export?->reference, 'sha256' => $export?->sha256, 'manifest_sha256' => $export?->manifest_sha256, 'delivered_at' => $export?->delivered_at?->toIso8601String()],
            'audit_chain' => $audit ?? ['deleted' => false, 'note' => 'The tenant\'s audit chain is retained (see retained categories).'],
            'verification' => $verification,
            'external_dependencies' => $external,
            'external_dependencies_note' => 'Statuses when this certificate was issued. Later confirmations are recorded in the platform audit trail (EXTERNAL_DELETION_CONFIRMED); copies outside PeopleOS are not claimed deleted until confirmed.',
            'final' => $final,
            'status' => 'completed',
        ];
    }

    private function operator(?int $id): ?array
    {
        $user = $id === null ? null : User::query()->find($id);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    private function auditChainDeleted(TenantDeletion $deletion): bool
    {
        return $this->tenants->bypass(fn () => TenantDeletionStep::query()->where('tenant_deletion_id', $deletion->getKey())->where('kind', 'audit')
            ->where('status', TenantDeletionStep::DONE)->exists());
    }

    private function fail(TenantDeletion $deletion, Tenant $tenant, Throwable $e): void
    {
        $message = mb_substr(($e instanceof LegalHoldEncountered ? '' : class_basename($e).': ').$e->getMessage(), 0, 1000);
        $this->tenants->bypass(fn () => TenantDeletionStep::query()->where('tenant_deletion_id', $deletion->getKey())->where('status', TenantDeletionStep::PENDING)
            ->whereNotNull('started_at')->update(['status' => TenantDeletionStep::FAILED, 'updated_at' => now()]));
        $this->tenants->bypass(fn () => TenantDeletion::query()->whereKey($deletion->getKey())->where('status', DeletionStatus::InProgress->value)
            ->update(['status' => DeletionStatus::Failed->value, 'failed_at' => now(), 'failure' => $message, 'updated_at' => now()]));
        $this->audit->record(AuditAction::DeletionFailed, $tenant, $deletion, 'Deletion stopped', null, ['deletion' => $deletion->reference, 'failure' => mb_substr($message, 0, 300)],
            tenantChain: ! $this->auditChainDeleted($deletion));
    }

    private function updateStep(TenantDeletionStep $step, array $values): void
    {
        $this->tenants->bypass(fn () => TenantDeletionStep::query()->whereKey($step->getKey())->update($values + ['updated_at' => now()]));
        foreach ($values as $key => $value) {
            $step->setAttribute($key, $key === 'detail' && is_string($value) ? json_decode($value, true) : $value);
        }
    }

    private function find(int $id): TenantDeletion
    {
        return $this->tenants->bypass(fn () => TenantDeletion::query()->findOrFail($id));
    }
}
