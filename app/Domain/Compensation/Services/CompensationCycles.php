<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Events\CompensationEvent;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Contracts\PerformanceOutcomesReader;
use App\Domain\Workforce\Services\ChecksOrganisationScope;
use App\Domain\Workforce\Services\OrganisationDimensions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 bulk compensation cycles (§15): annual increment, promotion, market adjustment.
 *
 *   draft ──populate──► (lines) ──submit──► submitted ──review──► under_review ──approve──► approved ──execute──► executed
 *              ▲                                 │                    │
 *              └─────────── return ──────────────┴────────────────────┘          reject / cancel
 *
 * Each line is an ordinary CompensationChange (source "cycle"), so every employee-level step is the
 * same controlled, audited path as a single change. Populating snapshots the eligible employees and
 * their compensation in force on the cycle date and proposes each new CTC deterministically:
 * previous × (1 + percent / 100), the percent coming from the cycle's default or, when a performance
 * cycle is named, from the employee's finalized rating label (PerformanceOutcomesReader). A rating never
 * decides anything by itself: every line is reviewed, approved and executed by people.
 *
 * Preparer, reviewer, approver and executor are four different people. Execution runs under one audit
 * operation id in one transaction (all lines or none) and is idempotent: executing an executed cycle
 * returns the same operation id and changes nothing.
 */
final class CompensationCycles
{
    use ChecksOrganisationScope;

    public function __construct(
        private readonly CompensationChanges $changes,
        private readonly OrganisationDimensions $dimensions,
        private readonly AccessScopes $scopes,
        private readonly PerformanceOutcomesReader $performance,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): CompensationCycle
    {
        $this->authorise($actor, 'compensation.cycles');
        foreach (['code', 'name', 'cycle_type', 'company_id', 'effective_from'] as $field) {
            if (blank($data[$field] ?? null)) {
                throw new CompensationRuleViolation("A compensation cycle needs {$field}.");
            }
        }
        if (! array_key_exists($data['cycle_type'], config('peopleos.compensation.cycle_types', []))) {
            throw new CompensationRuleViolation("Unknown cycle type [{$data['cycle_type']}].");
        }
        $node = $this->dimensions->forNode(filled($data['organisation_node_id'] ?? null) ? (int) $data['organisation_node_id'] : null);
        if ($node['company_id'] && (int) $node['company_id'] !== (int) $data['company_id']) {
            throw new CompensationRuleViolation('A cycle covers one company and its organisation units.');
        }
        $this->assertDimensionsInScope($actor, ['company_id' => (int) $data['company_id'], 'location_id' => $data['location_id'] ?? null, ...array_intersect_key($node, array_flip(OrganisationDimensions::UNIT_COLUMNS))]);
        $percent = $data['default_increase_percent'] ?? 0;
        if (! is_numeric($percent) || (float) $percent < -100 || (float) $percent > 1000) {
            throw new CompensationRuleViolation('The default increase must be a percentage between -100 and 1000.');
        }
        $matrix = [];
        foreach ((array) ($data['rating_increase_percent'] ?? []) as $label => $value) {
            if (! is_numeric($value) || (float) $value < -100 || (float) $value > 1000) {
                throw new CompensationRuleViolation("The increase for rating [{$label}] must be a percentage between -100 and 1000.");
            }
            $matrix[(string) $label] = round((float) $value, 2);
        }

        return CompensationCycle::query()->create([
            'code' => strtoupper(trim((string) $data['code'])), 'name' => $data['name'], 'cycle_type' => $data['cycle_type'],
            'company_id' => (int) $data['company_id'], 'organisation_node_id' => $data['organisation_node_id'] ?? null, 'location_id' => $data['location_id'] ?? null,
            ...array_intersect_key($node, array_flip(OrganisationDimensions::UNIT_COLUMNS)),
            'effective_from' => Carbon::parse($data['effective_from'])->toDateString(), 'compensation_budget_id' => $data['compensation_budget_id'] ?? null,
            'performance_cycle_id' => $data['performance_cycle_id'] ?? null, 'default_increase_percent' => round((float) $percent, 2),
            'rating_increase_percent' => $matrix ?: null, 'prepared_by' => $actor->id,
        ]);
    }

    /**
     * Snapshot the eligible employees and propose one line each (replacing earlier draft lines).
     *
     * @return array{lines: int, skipped: int}
     */
    public function populate(CompensationCycle $cycle, User $actor): array
    {
        $this->authorise($actor, 'compensation.cycles');
        $this->authorise($actor, 'compensation.propose');
        if ((int) $cycle->prepared_by !== (int) $actor->id) {
            throw new CompensationRuleViolation('Only the preparer populates a compensation cycle.');
        }

        return DB::transaction(function () use ($cycle, $actor) {
            $current = $this->lock($cycle, ['draft']);
            $this->assertRecordInScope($actor, $current);
            $cycle->setRawAttributes($current->getAttributes(), true);
            CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)->get()->each(fn (CompensationChange $c) => $c->delete());

            $date = $cycle->effective_from->copy();
            $employees = $this->eligible($cycle, $actor);
            $rows = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->whereIn('employee_id', $employees->keys())->effectiveOn($date)->get()->keyBy('employee_id');
            $lines = 0;
            $skipped = 0;
            $snapshot = [];
            foreach ($employees as $employee) {
                $row = $rows->get($employee->id);
                if ($row === null || (int) $employee->user_id === (int) $actor->id) {
                    $skipped++;

                    continue;
                }
                [$percent, $label] = $this->percentFor($cycle, $employee);
                $proposed = round((float) $row->ctc_annual * (1 + $percent / 100), 2);
                if ($proposed <= 0) {
                    $skipped++;

                    continue;
                }
                $change = $this->changes->propose($employee, [
                    'change_type' => config("peopleos.compensation.cycle_types.{$cycle->cycle_type}.change_type", 'annual_increment'),
                    'effective_from' => $date->toDateString(), 'salary_structure_id' => $row->salary_structure_id, 'ctc_annual' => $proposed,
                    'currency' => $row->currency, 'pay_frequency' => $row->pay_frequency, 'component_values' => $row->component_values ?? [],
                    'variable_target_annual' => $row->variable_target_annual,
                    'reason' => "{$cycle->name}: ".($label !== null ? "rating {$label}, " : '').number_format($percent, 2).'%',
                ], $actor, 'cycle');
                $change->update(['compensation_cycle_id' => $cycle->id, 'compensation_budget_id' => $cycle->compensation_budget_id, 'performance_label' => $label, 'increase_percent' => $percent]);
                $snapshot[] = $employee->id.':'.$row->id.':'.$proposed;
                $lines++;
            }
            sort($snapshot);
            $cycle->update(['employee_count' => $lines, 'snapshot_checksum' => hash('sha256', implode('|', $snapshot)), 'populated_at' => now(), 'lock_version' => $cycle->lock_version + 1]);
            $this->audit->record(AuditAction::StatusChange, 'compensation', $cycle, [], null, actor: $actor, effectiveDate: $date, metadata: ['event' => 'populated', 'lines' => $lines, 'skipped' => $skipped]);

            return ['lines' => $lines, 'skipped' => $skipped];
        });
    }

    public function submit(CompensationCycle $cycle, User $actor): CompensationCycle
    {
        if ((int) $cycle->prepared_by !== (int) $actor->id) {
            throw new CompensationRuleViolation('Only the preparer submits a compensation cycle.');
        }

        return $this->step($cycle, ['draft'], 'submitted', $actor, AuditAction::Submitted, function (CompensationCycle $cycle) use ($actor) {
            $lines = $this->lines($cycle, ['draft']);
            if ($lines->isEmpty()) {
                throw new CompensationRuleViolation('Populate the cycle before submitting it.');
            }
            $this->changes->quietly(fn () => $lines->each(fn (CompensationChange $c) => $this->changes->submit($c, $actor)));
            $cycle->update(['submitted_at' => now()]);

            return $this->holders('compensation.review', [$cycle->prepared_by]);
        });
    }

    public function review(CompensationCycle $cycle, User $actor): CompensationCycle
    {
        $this->authorise($actor, 'compensation.review');
        $this->assertNotActor($cycle, $actor, ['prepared_by'], 'review');

        return $this->step($cycle, ['submitted'], 'under_review', $actor, AuditAction::Reviewed, function (CompensationCycle $cycle) use ($actor) {
            $this->changes->quietly(fn () => $this->lines($cycle, ['submitted'])->each(fn (CompensationChange $c) => $this->changes->review($c, $actor)));
            $cycle->update(['reviewed_by' => $actor->id, 'reviewed_at' => now()]);

            return $this->holders('compensation.approve', [$cycle->prepared_by, $actor->id]);
        });
    }

    public function approve(CompensationCycle $cycle, User $actor): CompensationCycle
    {
        $this->authorise($actor, 'compensation.approve');
        $this->assertNotActor($cycle, $actor, ['prepared_by', 'reviewed_by'], 'approve');

        return $this->step($cycle, ['under_review'], 'approved', $actor, AuditAction::Approved, function (CompensationCycle $cycle) use ($actor) {
            $this->assertNotActor($cycle, $actor, ['prepared_by', 'reviewed_by'], 'approve');
            $this->changes->quietly(fn () => $this->lines($cycle, ['under_review'])->each(fn (CompensationChange $c) => $this->changes->approve($c, $actor)));
            $cycle->update(['approved_by' => $actor->id, 'approved_at' => now()]);
            CompensationEvent::dispatch('compensation.cycle.approved', null, $cycle, ['cycle' => $cycle->code, 'lines' => $cycle->employee_count, 'effective_date' => $cycle->effective_from->toDateString()], array_filter([(int) $cycle->prepared_by]));

            return $this->holders('compensation.execute', [$cycle->prepared_by, $cycle->reviewed_by, $actor->id]);
        });
    }

    public function reject(CompensationCycle $cycle, User $actor, string $note): CompensationCycle
    {
        $this->authoriseAny($actor, ['compensation.review', 'compensation.approve']);
        $this->assertNotActor($cycle, $actor, ['prepared_by'], 'reject');
        if (trim($note) === '') {
            throw new CompensationRuleViolation('Rejecting a cycle needs a reason.');
        }

        return $this->step($cycle, ['submitted', 'under_review'], 'rejected', $actor, AuditAction::Rejected, function (CompensationCycle $cycle) use ($actor, $note) {
            $this->changes->quietly(fn () => $this->lines($cycle, CompensationChange::PENDING)->each(fn (CompensationChange $c) => $this->changes->reject($c, $actor, $note)));
            $cycle->update(['decision_note' => $note]);

            return [(int) $cycle->prepared_by];
        });
    }

    public function cancel(CompensationCycle $cycle, User $actor, string $reason): CompensationCycle
    {
        if (trim($reason) === '') {
            throw new CompensationRuleViolation('Cancelling a cycle needs a reason.');
        }
        $isPreparer = (int) $cycle->prepared_by === (int) $actor->id;
        if (! $isPreparer && ! $actor->hasPermission('compensation.approve')) {
            throw new CompensationRuleViolation('This needs compensation.approve (or being the preparer).');
        }

        return $this->step($cycle, ['draft', 'submitted', 'under_review', 'approved'], 'cancelled', $actor, AuditAction::Cancelled, function (CompensationCycle $cycle) use ($actor, $reason, $isPreparer) {
            if ($cycle->getRawOriginal('status') === 'approved' && $isPreparer) {
                throw new CompensationRuleViolation('An approved cycle is cancelled only by an approver who did not prepare it.');
            }
            $this->changes->quietly(fn () => $this->lines($cycle, ['draft', 'submitted', 'under_review', 'approved'])->each(fn (CompensationChange $c) => $this->changes->cancel($c, $actor, 'Cycle '.$cycle->code.' cancelled: '.$reason)));
            $cycle->update(['closed_by' => $actor->id, 'closed_at' => now(), 'decision_note' => $reason]);

            return [(int) $cycle->prepared_by];
        });
    }

    /**
     * Execute every approved line under one operation id, in one transaction: either every line enters
     * the compensation timeline or none does. Idempotent: an executed cycle returns its operation id.
     */
    public function execute(CompensationCycle $cycle, User $actor): string
    {
        $this->authorise($actor, 'compensation.execute');
        $this->assertNotActor($cycle, $actor, ['prepared_by', 'reviewed_by', 'approved_by'], 'execute');

        return DB::transaction(function () use ($cycle, $actor) {
            $current = CompensationCycle::query()->withoutGlobalScope(AccessScope::class)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'executed') {
                $cycle->setRawAttributes($current->getAttributes(), true);

                return (string) $current->operation_id;   // already done: nothing changes
            }
            if ($current->status !== 'approved') {
                throw new CompensationRuleViolation('This cycle is '.str_replace('_', ' ', $current->status).'; only an approved cycle is executed.');
            }
            $this->assertRecordInScope($actor, $current);
            $cycle->setRawAttributes($current->getAttributes(), true);
            $this->assertNotActor($cycle, $actor, ['prepared_by', 'reviewed_by', 'approved_by'], 'execute');
            $lines = $this->lines($cycle, ['approved']);

            $operationId = $this->audit->operation('compensation', "Execute compensation cycle {$cycle->code}", function () use ($lines, $actor) {
                $this->changes->quietly(fn () => $lines->each(fn (CompensationChange $c) => $this->changes->schedule($c, $actor)));

                return ['succeeded' => $lines->count(), 'ids' => $lines->pluck('id')->all()];
            }, entityType: CompensationChange::class);

            $cycle->update(['status' => 'executed', 'executed_by' => $actor->id, 'executed_at' => now(), 'operation_id' => $operationId, 'lock_version' => $current->lock_version + 1]);
            $this->audit->record(AuditAction::Scheduled, 'compensation', $cycle, [['field' => 'status', 'before' => 'approved', 'after' => 'executed']], null, actor: $actor, effectiveDate: $cycle->effective_from, metadata: ['operation_id' => $operationId, 'lines' => $lines->count()]);
            CompensationEvent::dispatch('compensation.cycle.executed', null, $cycle, ['cycle' => $cycle->code, 'lines' => $lines->count(), 'effective_date' => $cycle->effective_from->toDateString()], array_values(array_unique(array_filter([(int) $cycle->prepared_by, (int) $cycle->approved_by]))));

            return $operationId;
        });
    }

    /** @return Collection<int, Employee> keyed by id */
    private function eligible(CompensationCycle $cycle, User $actor)
    {
        $date = $cycle->effective_from->toDateString();
        $positions = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->select('employee_id')->effectiveOn($date)->where('company_id', $cycle->company_id)
            ->when($cycle->location_id, fn ($q, $id) => $q->where('location_id', $id));
        foreach (OrganisationDimensions::UNIT_COLUMNS as $column) {
            if (in_array($column, ['business_unit_id', 'division_id', 'department_id', 'team_id'], true)) {
                $positions->when($cycle->getAttribute($column), fn ($q, $id) => $q->where($column, $id));
            }
        }

        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $positions)
            ->whereIn('lifecycle_state', config('peopleos.compensation.lifecycle.cycles', []))
            ->when($this->scopes->isScoped($actor), fn ($q) => $q->whereIn('id', $this->scopes->employeeKeys($actor)))
            ->orderBy('id')->get()->keyBy('id');
    }

    /** @return array{0: float, 1: ?string} percent and the finalized rating label it came from */
    private function percentFor(CompensationCycle $cycle, Employee $employee): array
    {
        $matrix = (array) ($cycle->rating_increase_percent ?? []);
        if ($cycle->performance_cycle_id && $matrix !== []) {
            $outcome = $this->performance->finalOutcome((int) $employee->id, (int) $cycle->performance_cycle_id);
            $label = $outcome['final_label'] ?? null;
            if ($label !== null && array_key_exists($label, $matrix)) {
                return [(float) $matrix[$label], $label];
            }

            return [(float) $cycle->default_increase_percent, $label];
        }

        return [(float) $cycle->default_increase_percent, null];
    }

    /**
     * @param  list<string>  $from
     * @param  callable(CompensationCycle): list<int>  $work  returns the users to notify
     */
    private function step(CompensationCycle $cycle, array $from, string $to, User $actor, AuditAction $action, callable $work): CompensationCycle
    {
        return DB::transaction(function () use ($cycle, $from, $to, $actor, $action, $work) {
            $current = $this->lock($cycle, $from);
            $this->assertRecordInScope($actor, $current);
            $cycle->setRawAttributes($current->getAttributes(), true);
            $before = $current->status;
            $recipients = $work($cycle);
            $cycle->update(['status' => $to, 'lock_version' => (int) $current->lock_version + 1]);
            $this->audit->record($action, 'compensation', $cycle, [['field' => 'status', 'before' => $before, 'after' => $to]], null, actor: $actor, effectiveDate: $cycle->effective_from, metadata: ['cycle' => $cycle->code, 'lines' => $cycle->employee_count]);
            CompensationEvent::dispatch('compensation.cycle.'.$to, null, $cycle, ['cycle' => $cycle->code, 'lines' => $cycle->employee_count, 'status' => $to], array_values(array_unique(array_filter($recipients))));

            return $cycle;
        });
    }

    /** @param  list<string>  $statuses */
    private function lock(CompensationCycle $cycle, array $statuses): CompensationCycle
    {
        $current = CompensationCycle::query()->withoutGlobalScope(AccessScope::class)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
        if (! in_array($current->status, $statuses, true)) {
            throw new CompensationRuleViolation('This cycle is '.str_replace('_', ' ', $current->status).'; that step is not available.');
        }

        return $current;
    }

    /** @param  list<string>  $statuses */
    private function lines(CompensationCycle $cycle, array $statuses)
    {
        return CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)->whereIn('status', $statuses)->orderBy('id')->get();
    }

    /** @param  list<string>  $columns */
    private function assertNotActor(CompensationCycle $cycle, User $actor, array $columns, string $step): void
    {
        foreach ($columns as $column) {
            if ($cycle->getAttribute($column) !== null && (int) $cycle->getAttribute($column) === (int) $actor->id) {
                throw new CompensationRuleViolation('The '.str_replace(['prepared_by', 'reviewed_by', 'approved_by'], ['preparer', 'reviewer', 'approver'], $column)." of a compensation cycle cannot {$step} it.");
            }
        }
    }

    /** @return list<int> */
    private function holders(string $permission, array $except): array
    {
        $except = array_map('intval', array_filter($except));

        return User::query()->forCurrentTenant()->get()->filter(fn (User $u) => $u->isActive() && ! in_array((int) $u->id, $except, true) && $u->hasPermission($permission))->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new CompensationRuleViolation("This needs {$permission}.");
        }
    }

    /** @param  list<string>  $permissions */
    private function authoriseAny(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($actor->hasPermission($permission)) {
                return;
            }
        }
        throw new CompensationRuleViolation('This needs '.implode(' or ', $permissions).'.');
    }
}
