<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\PersonSkill;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Phase 14 Change Intelligence: a read-only lens on the existing audit trail (ADR-0008). It stores
 * nothing and never duplicates audit data.
 *
 * It answers what changed, who changed it, when, which employee / entity / module / organisation,
 * the previous and new values, why (reason), whether an approval or workflow was involved, and which
 * integration, request or bulk operation caused it.
 *
 * Security:
 * - `audit.view`, inside the bound tenant.
 * - An organisation-scoped viewer sees only changes to records of employees in their scope (employee,
 *   person, and every employee- or person-linked record); tenant-level configuration changes need an
 *   unscoped viewer.
 * - Values stay masked as written (sensitive attributes are masked at write time). Values on financial,
 *   statutory, highly sensitive or confidential records are also masked for viewers without
 *   employee.sensitive.view.
 */
final class ChangeIntelligence
{
    /** @var array{employee: array<class-string, string>, person: array<class-string, string>}|null */
    private static ?array $linked = null;

    public function __construct(private readonly AccessScopes $scopes) {}

    /**
     * @param  array{employee_id?: int|null, module?: string|null, actor_id?: int|null, action?: string|list<string>|null, from?: string|null, to?: string|null,
     *               department_id?: int|null, company_id?: int|null, entity_type?: string|null, entity_id?: string|int|null, source?: string|null,
     *               correlation_id?: string|null, operation_id?: string|null}  $filters
     */
    public function query(User $viewer, array $filters = []): Builder
    {
        if (! $viewer->hasPermission('audit.view')) {
            throw new RuntimeException('Change history needs audit.view.');
        }
        $query = AuditEvent::query()->with('fieldChanges');
        $this->scope($query, $viewer);

        if (filled($filters['employee_id'] ?? null)) {
            $this->linkedTo($query, Employee::query()->withoutGlobalScope(AccessScope::class)->select('id')->whereKey((int) $filters['employee_id'])->toBase());
        }
        $orgColumn = filled($filters['department_id'] ?? null) ? 'department_id' : (filled($filters['company_id'] ?? null) ? 'company_id' : null);
        if ($orgColumn !== null) {
            $this->linkedTo($query, EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->select('employee_id')->effectiveOn()->where($orgColumn, (int) $filters[$orgColumn])->toBase());
        }
        $query->when($filters['module'] ?? null, fn (Builder $q, $m) => $q->where('module', $m))
            ->when($filters['actor_id'] ?? null, fn (Builder $q, $a) => $q->where('actor_id', (int) $a))
            ->when($filters['action'] ?? null, fn (Builder $q, $a) => $q->whereIn('action', (array) $a))
            ->when($filters['from'] ?? null, fn (Builder $q, $d) => $q->where('occurred_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, $d) => $q->where('occurred_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($filters['entity_type'] ?? null, fn (Builder $q, $t) => $q->where('entity_type', $t))
            ->when(filled($filters['entity_id'] ?? null), fn (Builder $q) => $q->where('entity_id', (string) $filters['entity_id']))
            ->when($filters['source'] ?? null, fn (Builder $q, $s) => $q->where('source', 'like', $s.'%'))
            ->when($filters['correlation_id'] ?? null, fn (Builder $q, $c) => $q->where('request_id', $c))
            ->when($filters['operation_id'] ?? null, fn (Builder $q, $o) => $q->where('operation_id', $o));

        return $query->orderByDesc('id');
    }

    /** Organisation scope for audit reads (also used by the generic audit list). */
    public function scope(Builder $query, User $viewer): Builder
    {
        if ($this->scopes->isScoped($viewer)) {
            $this->linkedTo($query, $this->scopes->employeeKeys($viewer));
        }

        return $query;
    }

    /** The answer to "what changed, by whom, why, through what" for one audit event, masked for the viewer. */
    public function describe(AuditEvent $event, User $viewer): array
    {
        $maskValues = $this->classified((string) $event->entity_type) && ! $viewer->hasPermission('employee.sensitive.view');
        $metadata = $event->metadata ?? [];

        return [
            'what' => $event->action?->label().' — '.$event->entity_label,
            'who' => $event->actor_name ?? ($event->source === 'anonymous' ? 'Anonymous (by design)' : 'System'),
            'when' => $event->occurred_at?->toDateTimeString(),
            'module' => $event->module,
            'entity' => class_basename((string) $event->entity_type).($event->entity_id ? ' #'.$event->entity_id : ''),
            'changes' => $event->fieldChanges->map(fn ($c) => [
                'field' => $c->field,
                'before' => $maskValues && ! $c->is_sensitive ? config('peopleos.audit.mask', '••••') : $c->before,
                'after' => $maskValues && ! $c->is_sensitive ? config('peopleos.audit.mask', '••••') : $c->after,
                'masked' => $c->is_sensitive || $maskValues,
            ])->values()->all(),
            'why' => $event->reason,
            'approval' => $event->approval_reference ?? (isset($metadata['workflow_instance_id']) ? 'Workflow run #'.$metadata['workflow_instance_id'] : null),
            'source' => $event->source,
            'integration' => str_starts_with((string) $event->source, 'api:') ? substr((string) $event->source, 4) : ($metadata['system'] ?? null),
            'correlation_id' => $event->request_id,
            'operation_id' => $event->operation_id,
            'effective_date' => $event->effective_date?->toDateString(),
        ];
    }

    /** Is the entity type a classified record (financial / statutory / highly sensitive / confidential)? */
    public function classified(string $class): bool
    {
        $c = config('peopleos.data_classification');

        return array_key_exists($class, $c['highly_sensitive'] ?? []) || in_array($class, $c['financial'] ?? [], true)
            || in_array($class, $c['statutory'] ?? [], true) || in_array($class, $c['confidential'] ?? [], true);
    }

    /** Restrict to events on the given employees' records: employee, person, and employee- / person-linked rows. */
    private function linkedTo(Builder $query, QueryBuilder $employeeIds): void
    {
        $linked = self::linkedModels();
        $query->where(function (Builder $w) use ($employeeIds, $linked) {
            $w->where(fn (Builder $x) => $x->where('entity_type', Employee::class)->whereIn('entity_id', clone $employeeIds))
                ->orWhere(fn (Builder $x) => $x->where('entity_type', Person::class)->whereIn('entity_id', Employee::query()->withoutGlobalScope(AccessScope::class)->select('person_id')->whereIn('id', clone $employeeIds)));
            foreach ($linked['employee'] as $class => $table) {
                $w->orWhere(fn (Builder $x) => $x->where('entity_type', $class)->whereIn('entity_id', $class::query()->withoutGlobalScopes([AccessScope::class])->select($table.'.id')->whereIn($table.'.employee_id', clone $employeeIds)));
            }
            foreach ($linked['person'] as $class => $table) {
                $w->orWhere(fn (Builder $x) => $x->where('entity_type', $class)->whereIn('entity_id', $class::query()->withoutGlobalScopes([AccessScope::class])->select($table.'.id')
                    ->whereIn($table.'.person_id', Employee::query()->withoutGlobalScope(AccessScope::class)->select('person_id')->whereIn('id', clone $employeeIds))));
            }
        });
    }

    /**
     * HROne parity MS-2 ("My changes"): the audited changes to the signed-in person's own profile, with earlier and new
     * values, for their own record only and without audit.view. Only the person's employee record, person record and
     * person satellites (addresses, family, emergency contacts, education, experience, profile skills) count; classified
     * records (bank, statutory, pay) never do, sensitive fields are left out entirely, and technical fields (ids, keys,
     * metadata) are skipped.
     *
     * @return list<array{when: ?CarbonInterface, record: string, by: string, fields: list<array{field: string, before: ?string, after: ?string}>}>
     */
    public function ownProfileChanges(User $viewer, Employee $employee, int $limit = 30): array
    {
        if ($employee->getAttribute('user_id') === null || (int) $employee->getAttribute('user_id') !== (int) $viewer->getKey()
            || (int) $employee->getAttribute('tenant_id') !== (int) $viewer->getAttribute('tenant_id')) {
            return [];
        }
        $satellites = array_values(array_filter([PersonAddress::class, PersonFamilyMember::class, PersonEmergencyContact::class, PersonQualification::class, PersonExperience::class, PersonSkill::class],
            fn (string $class) => ! $this->classified($class)));
        $personId = (int) $employee->getAttribute('person_id');
        $events = AuditEvent::query()->with('fieldChanges')->whereIn('action', [AuditAction::Create->value, AuditAction::Update->value])
            ->where(function (Builder $w) use ($employee, $personId, $satellites) {
                $w->where(fn (Builder $x) => $x->where('entity_type', Employee::class)->where('entity_id', (string) $employee->getKey()))
                    ->orWhere(fn (Builder $x) => $x->where('entity_type', Person::class)->where('entity_id', (string) $personId));
                foreach ($satellites as $class) {
                    $table = (new $class)->getTable();
                    $w->orWhere(fn (Builder $x) => $x->where('entity_type', $class)->whereIn('entity_id', $class::query()->withoutGlobalScopes([AccessScope::class])->select($table.'.id')->where($table.'.person_id', $personId)));
                }
            })
            ->orderByDesc('occurred_at')->limit($limit * 2)->get();
        $technical = fn (string $field) => str_ends_with($field, '_id') || str_ends_with($field, '_key') || in_array($field, ['metadata', 'lock_version', 'directory_hidden'], true);

        return $events->map(fn (AuditEvent $event) => [
            'when' => $event->occurred_at,
            'record' => (string) ($event->entity_label ?: class_basename((string) $event->entity_type)),
            'by' => (string) ($event->actor_name ?? 'System'),
            // HROne parity MS-3: the request that caused it (e.g. a self-service profile change "PCR-000012").
            'reference' => $event->approval_reference,
            'fields' => $event->fieldChanges->reject(fn ($c) => $c->is_sensitive || $technical((string) $c->field))
                ->map(fn ($c) => ['field' => ucfirst(str_replace('_', ' ', (string) $c->field)), 'before' => $c->before, 'after' => $c->after])->values()->all(),
        ])->filter(fn (array $row) => $row['fields'] !== [])->take($limit)->values()->all();
    }

    /** @return array{employee: array<class-string, string>, person: array<class-string, string>} audited models carrying employee_id / person_id */
    public static function linkedModels(): array
    {
        if (self::$linked !== null) {
            return self::$linked;
        }
        $out = ['employee' => [], 'person' => []];
        foreach (glob(app_path('Domain/*/Models/*.php')) as $file) {
            $class = 'App\\Domain\\'.basename(dirname($file, 2)).'\\Models\\'.basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || in_array($class, [Employee::class, Person::class], true)
                || ! in_array(Auditable::class, class_uses_recursive($class), true)) {
                continue;
            }
            $model = new $class;
            $fillable = $model->getFillable();
            if (in_array('employee_id', $fillable, true)) {
                $out['employee'][$class] = $model->getTable();
            } elseif (in_array('person_id', $fillable, true)) {
                $out['person'][$class] = $model->getTable();
            }
        }
        ksort($out['employee']);
        ksort($out['person']);

        return self::$linked = $out;
    }
}
