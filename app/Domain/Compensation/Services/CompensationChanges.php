<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Events\CompensationEvent;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Workforce\Models\Position;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 11 — the compensation change engine: the only way employee compensation changes.
 *
 *   draft ──submit──► submitted ──review──► under_review ──approve──► approved ──schedule──► scheduled ──(effective date)──► effective
 *     ▲                   │                     │                        │                   │
 *     └──── return ───────┴─────────────────────┘          reject / cancel                 cancel (until it takes effect)
 *
 * Four different people: the proposer, the reviewer, the approver and the executor (who schedules the
 * approved change into the canonical timeline). No one acts on their own compensation. Every step locks
 * the change row, re-checks its status on the locked row and bumps lock_version; the executor's step
 * writes the canonical row through AssignmentWriter, which also locks the employee and the payroll
 * runs concerned. Nothing here is automatic: performance or planning data can prefill a proposal, never
 * approve or execute it.
 */
final class CompensationChanges
{
    /** Per-change events are held back while a bulk cycle step runs (the cycle emits one event). */
    private static bool $quiet = false;

    public function __construct(private readonly AssignmentWriter $writer, private readonly AuditRecorder $audit, private readonly AccessScopes $scopes) {}

    /**
     * Run bulk steps without one notification per line; audit is unaffected.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function quietly(callable $callback): mixed
    {
        $previous = self::$quiet;
        self::$quiet = true;
        try {
            return $callback();
        } finally {
            self::$quiet = $previous;
        }
    }

    /** @param  array<string, mixed>  $data */
    public function propose(Employee $employee, array $data, User $actor, string $source = 'manual'): CompensationChange
    {
        $this->authorise($actor, 'compensation.propose');
        $employee = $this->reachable($actor, (int) $employee->getKey());
        $this->assertNotOwn($actor, $employee);
        if (! array_key_exists($source, config('peopleos.compensation.sources', []))) {
            throw new CompensationRuleViolation("Unknown compensation change source [{$source}].");
        }

        return CompensationChange::query()->create([
            ...$this->validated($employee, $data),
            'employee_id' => $employee->id,
            'source' => $source,
            'status' => 'draft',
            'internal_notes' => filled($data['internal_notes'] ?? null) ? (string) $data['internal_notes'] : null,
            'proposed_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(CompensationChange $change, array $data, User $actor): CompensationChange
    {
        $this->authorise($actor, 'compensation.propose');
        if ((int) $change->proposed_by !== (int) $actor->id) {
            throw new CompensationRuleViolation('Only the proposer edits a draft compensation change.');
        }

        return $this->transition($change, ['draft'], $actor, function (CompensationChange $change) use ($data) {
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($change->employee_id);
            $change->update([
                ...$this->validated($employee, $data + $change->only(CompensationChange::CONTENT)),
                'internal_notes' => array_key_exists('internal_notes', $data) ? ($data['internal_notes'] ?: null) : $change->internal_notes,
            ]);
        });
    }

    public function submit(CompensationChange $change, User $actor): CompensationChange
    {
        $this->authorise($actor, 'compensation.propose');
        if ((int) $change->proposed_by !== (int) $actor->id) {
            throw new CompensationRuleViolation('Only the proposer submits a compensation change.');
        }

        return $this->transition($change, ['draft'], $actor, function (CompensationChange $change, Employee $employee) use ($actor) {
            // Re-validate against today's facts (structure still active, employee still eligible …).
            $this->validated($employee, $change->only(CompensationChange::CONTENT));
            $previous = $this->inForce($employee, $change->effective_from);
            $change->update([
                'status' => 'submitted', 'submitted_at' => now(),
                'previous_assignment_id' => $previous?->id, 'previous_ctc_annual' => $previous?->ctc_annual, 'previous_currency' => $previous?->currency,
                'previous_salary_structure_id' => $previous?->salary_structure_id, 'previous_component_values' => $previous?->component_values,
            ]);
            $this->audit->record(AuditAction::Submitted, 'compensation', $change, [['field' => 'status', 'before' => 'draft', 'after' => 'submitted']], null, actor: $actor, effectiveDate: $change->effective_from, metadata: ['reference' => $change->reference, 'change_type' => $change->change_type]);
            $this->event('compensation.change.submitted', $change, $employee, $this->holders('compensation.review', $employee, [$change->proposed_by]));
        });
    }

    public function review(CompensationChange $change, User $actor, ?string $note = null): CompensationChange
    {
        $this->authorise($actor, 'compensation.review');
        $this->assertNotActor($change, $actor, ['proposer'], 'review');

        return $this->transition($change, ['submitted'], $actor, function (CompensationChange $change, Employee $employee) use ($actor, $note) {
            $change->update(['status' => 'under_review', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note ?: null]);
            $this->audit->record(AuditAction::Reviewed, 'compensation', $change, [['field' => 'status', 'before' => 'submitted', 'after' => 'under_review']], null, actor: $actor, effectiveDate: $change->effective_from, metadata: ['reference' => $change->reference]);
            $this->event('compensation.change.reviewed', $change, $employee, $this->holders('compensation.approve', $employee, [$change->proposed_by, $actor->id]));
        });
    }

    public function approve(CompensationChange $change, User $actor, ?string $note = null): CompensationChange
    {
        $this->authorise($actor, 'compensation.approve');
        $this->assertNotActor($change, $actor, ['proposer', 'reviewer'], 'approve');

        return $this->transition($change, ['under_review'], $actor, function (CompensationChange $change, Employee $employee) use ($actor, $note) {
            if ($change->reviewed_by === null) {
                throw new CompensationRuleViolation('A compensation change is approved only after a review.');
            }
            $this->assertNotActor($change, $actor, ['proposer', 'reviewer'], 'approve');
            $this->assertPreviousUnchanged($change, $employee);
            if ($change->compensation_budget_id) {
                app(CompensationBudgets::class)->charge($change);
            }
            $change->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note ?: null]);
            $this->audit->record(AuditAction::Approved, 'compensation', $change, [['field' => 'status', 'before' => 'under_review', 'after' => 'approved']], null, actor: $actor, effectiveDate: $change->effective_from, metadata: ['reference' => $change->reference]);
            $this->event('compensation.change.approved', $change, $employee, [(int) $change->proposed_by, ...$this->holders('compensation.execute', $employee, [$change->proposed_by, $change->reviewed_by, $actor->id])]);
        });
    }

    public function reject(CompensationChange $change, User $actor, string $note): CompensationChange
    {
        $this->authoriseAny($actor, ['compensation.review', 'compensation.approve']);
        $this->assertNotActor($change, $actor, ['proposer'], 'reject');
        $this->requireText($note, 'Rejecting a compensation change needs a reason.');

        return $this->transition($change, CompensationChange::PENDING, $actor, function (CompensationChange $change, Employee $employee) use ($actor, $note) {
            $before = $change->status;
            $change->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'decision_note' => $note]);
            $this->audit->record(AuditAction::Rejected, 'compensation', $change, [['field' => 'status', 'before' => $before, 'after' => 'rejected']], null, actor: $actor, effectiveDate: $change->effective_from, metadata: ['reference' => $change->reference]);
            $this->event('compensation.change.rejected', $change, $employee, [(int) $change->proposed_by]);
        });
    }

    /** Back to the proposer as a draft (the review starts again after resubmission). */
    public function returnToDraft(CompensationChange $change, User $actor, string $note): CompensationChange
    {
        $this->authoriseAny($actor, ['compensation.review', 'compensation.approve']);
        $this->assertNotActor($change, $actor, ['proposer'], 'return');
        $this->requireText($note, 'Returning a compensation change needs a note for the proposer.');

        return $this->transition($change, CompensationChange::PENDING, $actor, function (CompensationChange $change, Employee $employee) use ($actor, $note) {
            $before = $change->status;
            $change->update(['status' => 'draft', 'submitted_at' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'decision_note' => $note]);
            $this->audit->record(AuditAction::StatusChange, 'compensation', $change, [['field' => 'status', 'before' => $before, 'after' => 'draft']], $note, actor: $actor, metadata: ['reference' => $change->reference, 'event' => 'returned']);
            $this->event('compensation.change.returned', $change, $employee, [(int) $change->proposed_by]);
        });
    }

    /**
     * Withdraw a change. Before approval the proposer (or an approver) may cancel; an approved or
     * scheduled change is cancelled only by an approver who did not propose it, and only until it takes
     * effect — its canonical row is kept as cancelled history and the timeline closes around it.
     */
    public function cancel(CompensationChange $change, User $actor, string $reason): CompensationChange
    {
        $this->requireText($reason, 'Cancelling a compensation change needs a reason.');
        $isProposer = (int) $change->proposed_by === (int) $actor->id;
        if (! ($isProposer && $actor->hasPermission('compensation.propose')) && ! $actor->hasPermission('compensation.approve')) {
            throw new CompensationRuleViolation('This needs compensation.approve (or being the proposer of an undecided change).');
        }

        return $this->transition($change, ['draft', 'submitted', 'under_review', 'approved', 'scheduled'], $actor, function (CompensationChange $change, Employee $employee) use ($actor, $reason, $isProposer) {
            $before = $change->status;
            if (in_array($before, ['approved', 'scheduled'], true) && ($isProposer || ! $actor->hasPermission('compensation.approve'))) {
                throw new CompensationRuleViolation('An approved compensation change is cancelled only by an approver who did not propose it.');
            }
            if ($before === 'scheduled') {
                $row = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->findOrFail($change->employee_salary_assignment_id);
                $this->writer->cancel($row, $change, $actor);
            }
            if (in_array($before, ['approved', 'scheduled'], true) && $change->compensation_budget_id) {
                app(CompensationBudgets::class)->release($change);
            }
            $change->update(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)]);
            $this->audit->record(AuditAction::Cancelled, 'compensation', $change, [['field' => 'status', 'before' => $before, 'after' => 'cancelled']], $reason, actor: $actor, effectiveDate: $change->effective_from, metadata: ['reference' => $change->reference]);
            $this->event('compensation.change.cancelled', $change, $employee, array_values(array_unique(array_filter([(int) $change->proposed_by, (int) $change->approved_by]))));
        });
    }

    /**
     * The executor's step: write the approved change into the canonical timeline. A change whose date
     * has come is effective at once; a later one is scheduled until the effective-date processor runs.
     */
    public function schedule(CompensationChange $change, User $actor): CompensationChange
    {
        $this->authorise($actor, 'compensation.execute');
        $this->assertNotActor($change, $actor, ['proposer', 'reviewer', 'approver'], 'execute');

        return $this->transition($change, ['approved'], $actor, function (CompensationChange $change, Employee $employee) use ($actor) {
            $this->assertNotActor($change, $actor, ['proposer', 'reviewer', 'approver'], 'execute');
            // The employee may have left (or the date may now be past) since the decision.
            $this->assertEligible($employee, $change->effective_from->copy(), $change->change_type);
            $this->assertCorrectionTargetsEffective($change);
            $row = $this->writer->write($change, $actor);
            $dueNow = $change->effective_from->lte(now()->startOfDay());
            $change->update([
                'status' => $dueNow ? 'effective' : 'scheduled', 'scheduled_by' => $actor->id, 'scheduled_at' => now(),
                'employee_salary_assignment_id' => $row->id,
                ...($dueNow ? ['effective_at' => now(), 'effected_by' => $actor->id] : []),
            ]);
            $this->audit->record(AuditAction::Scheduled, 'compensation', $change, [['field' => 'status', 'before' => 'approved', 'after' => 'scheduled']], null, actor: $actor, effectiveDate: $change->effective_from, metadata: ['reference' => $change->reference, 'assignment_id' => $row->id]);
            $recipients = array_values(array_unique([(int) $change->proposed_by, (int) $change->approved_by]));
            $this->event($change->change_type === 'correction' ? 'compensation.change.corrected' : 'compensation.change.scheduled', $change, $employee, $recipients);
            if ($dueNow) {
                $this->audit->record(AuditAction::Effected, 'compensation', $change, [['field' => 'status', 'before' => 'scheduled', 'after' => 'effective']], null, actor: $actor, effectiveDate: $change->effective_from, metadata: ['reference' => $change->reference]);
                $this->event('compensation.change.effective', $change, $employee, $recipients);
            }
        });
    }

    /**
     * The effective-date processor: every scheduled change whose date has come becomes effective. One
     * transaction per change (row lock + status re-check), so a run that overlaps another, or a
     * cancellation, changes each one exactly once.
     *
     * @return int changes made effective
     */
    public function effectDue(CarbonInterface|string|null $on = null, ?User $actor = null): int
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        $count = 0;
        CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('status', 'scheduled')
            ->where('effective_from', '<=', $day.' 23:59:59')->orderBy('effective_from')->orderBy('id')->pluck('id')
            ->each(function (int $id) use ($day, $actor, &$count) {
                try {
                    $count += $this->effect(CompensationChange::query()->withoutGlobalScope(AccessScope::class)->findOrFail($id), $day, $actor) ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }

    /** Make one scheduled change effective; false when another process already did (or it was cancelled). */
    public function effect(CompensationChange $change, CarbonInterface|string|null $on = null, ?User $actor = null): bool
    {
        if ($actor !== null) {
            $this->authorise($actor, 'compensation.execute');
            $this->assertNotActor($change, $actor, ['proposer', 'reviewer', 'approver'], 'execute');
        }
        $day = Carbon::parse($on ?? now())->startOfDay();

        return DB::transaction(function () use ($change, $day, $actor) {
            $current = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->whereKey($change->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'scheduled' || $current->effective_from->gt($day)) {
                return false;
            }
            $change->setRawAttributes($current->getAttributes(), true);
            $change->update(['status' => 'effective', 'effective_at' => now(), 'effected_by' => $actor?->id, 'lock_version' => $current->lock_version + 1]);
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($change->employee_id);
            $this->audit->record(AuditAction::Effected, 'compensation', $change, [['field' => 'status', 'before' => 'scheduled', 'after' => 'effective']], null, actor: $actor, effectiveDate: $change->effective_from, metadata: ['reference' => $change->reference, 'processor' => $actor ? 'user' : 'system']);
            $this->event('compensation.change.effective', $change, $employee, array_values(array_unique([(int) $change->proposed_by, (int) $change->approved_by])));

            return true;
        });
    }

    /**
     * Lock the change row, re-check status (and organisation scope) on the locked row, run the step and
     * bump lock_version — all in one transaction.
     *
     * @param  list<string>  $from
     * @param  callable(CompensationChange, Employee): void  $step
     */
    private function transition(CompensationChange $change, array $from, User $actor, callable $step): CompensationChange
    {
        return DB::transaction(function () use ($change, $from, $actor, $step) {
            $current = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->whereKey($change->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, $from, true)) {
                throw new CompensationRuleViolation('This compensation change is '.str_replace('_', ' ', $current->status).'; that step is not available.');
            }
            if ($change->lock_version !== null && (int) $change->lock_version !== (int) $current->lock_version) {
                throw new CompensationRuleViolation('This compensation change was changed by someone else; reload it.');
            }
            $employee = $this->reachable($actor, (int) $current->employee_id);
            $this->assertNotOwn($actor, $employee);
            $change->setRawAttributes($current->getAttributes(), true);
            $step($change, $employee);
            $change->update(['lock_version' => (int) $current->lock_version + 1]);

            return $change;
        });
    }

    /** @return array<string, mixed> normalised proposal content */
    private function validated(Employee $employee, array $data): array
    {
        $type = (string) ($data['change_type'] ?? '');
        if (! array_key_exists($type, config('peopleos.compensation.change_types', []))) {
            throw new CompensationRuleViolation("Unknown compensation change type [{$type}].");
        }
        try {
            $from = Carbon::parse((string) ($data['effective_from'] ?? ''))->startOfDay();
        } catch (Throwable) {
            throw new CompensationRuleViolation('A valid effective date is required.');
        }
        if (blank($data['effective_from'] ?? null)) {
            throw new CompensationRuleViolation('A valid effective date is required.');
        }
        $this->assertEligible($employee, $from, $type);

        $structure = SalaryStructure::query()->find($data['salary_structure_id'] ?? null);
        if ($structure === null) {
            throw new CompensationRuleViolation('The compensation structure does not exist.');
        }
        if ($structure->status->value !== 'active') {
            throw new CompensationRuleViolation('The salary structure is not active.');
        }
        // The structure's approved version in force on the effective date decides the components, and it
        // must apply to the employee's company and grade on that date.
        $version = $structure->versionOn($from);
        if ($version === null) {
            throw new CompensationRuleViolation("Structure {$structure->code} has no approved version in force on {$from->toDateString()}.");
        }
        $held = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->effectiveOn($from)->orderByDesc('effective_from')->first(['company_id', 'grade_id']);
        if ($held && ! $version->appliesTo($held->company_id ? (int) $held->company_id : null, $held->grade_id ? (int) $held->grade_id : null)) {
            throw new CompensationRuleViolation("Structure {$structure->code} v{$version->version} does not apply to this employee's company or grade.");
        }

        $ctc = $data['ctc_annual'] ?? null;
        if (! is_numeric($ctc) || (float) $ctc <= 0 || (float) $ctc >= 1e12) {
            throw new CompensationRuleViolation('Annual CTC must be positive.');
        }
        $variable = $data['variable_target_annual'] ?? null;
        if ($variable !== null && $variable !== '' && (! is_numeric($variable) || (float) $variable < 0 || (float) $variable >= 1e12)) {
            throw new CompensationRuleViolation('A variable target cannot be negative.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? config('peopleos.settings.tenant.base_currency', 'INR'))));
        if (! in_array($currency, config('peopleos.compensation.currencies', []), true)) {
            throw new CompensationRuleViolation("[{$currency}] is not a supported ISO 4217 currency.");
        }
        $frequency = (string) ($data['pay_frequency'] ?? 'monthly');
        if (! array_key_exists($frequency, config('peopleos.compensation.pay_frequencies', []))) {
            throw new CompensationRuleViolation("Pay frequency [{$frequency}] is not supported by Payroll.");
        }

        $codes = $version->components()->with('component:id,code')->get()->map(fn ($i) => $i->component?->code)->filter()->map(fn ($c) => strtoupper($c))->all();
        $values = [];
        foreach ((array) ($data['component_values'] ?? []) as $code => $amount) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') {
                continue;
            }
            if (! in_array($code, $codes, true)) {
                throw new CompensationRuleViolation("Component [{$code}] is not part of structure {$structure->code} v{$version->version}.");
            }
            if (! is_numeric($amount) || (float) $amount < 0) {
                throw new CompensationRuleViolation("The amount for component [{$code}] must be zero or more.");
            }
            $values[$code] = round((float) $amount, 2);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        $this->requireText($reason, 'A compensation change needs a reason.');

        foreach (['from_grade_id' => Grade::class, 'to_grade_id' => Grade::class, 'from_position_id' => Position::class, 'to_position_id' => Position::class, 'compensation_budget_id' => CompensationBudget::class] as $column => $model) {
            if (filled($data[$column] ?? null) && ! $model::query()->withoutGlobalScope(AccessScope::class)->whereKey($data[$column])->exists()) {
                throw new CompensationRuleViolation("The {$column} reference does not exist.");
            }
        }

        return [
            'change_type' => $type, 'effective_from' => $from->toDateString(), 'salary_structure_id' => $structure->id,
            'ctc_annual' => round((float) $ctc, 2), 'currency' => $currency, 'pay_frequency' => $frequency,
            'component_values' => $values, 'variable_target_annual' => filled($variable) ? round((float) $variable, 2) : null,
            'reason' => mb_substr($reason, 0, 2000),
            'from_grade_id' => $data['from_grade_id'] ?? null, 'to_grade_id' => $data['to_grade_id'] ?? null,
            'from_position_id' => $data['from_position_id'] ?? null, 'to_position_id' => $data['to_position_id'] ?? null,
            'compensation_budget_id' => filled($data['compensation_budget_id'] ?? null) ? (int) $data['compensation_budget_id'] : null,
        ];
    }

    /** Phase 11 §41: which lifecycle states may receive current or future compensation (config). */
    private function assertEligible(Employee $employee, Carbon $from, string $type): void
    {
        $state = $employee->lifecycle_state instanceof LifecycleState ? $employee->lifecycle_state->value : (string) $employee->lifecycle_state;
        $future = $from->gt(now()->startOfDay());
        $allowed = config('peopleos.compensation.lifecycle.'.($future ? 'future' : 'current'), []);
        $exitedCorrection = $type === 'correction' && in_array($state, config('peopleos.compensation.lifecycle.correction_after_exit', []), true)
            && $employee->exit_date !== null && $from->lte($employee->exit_date);
        if (! in_array($state, $allowed, true) && ! $exitedCorrection) {
            throw new CompensationRuleViolation('An employee who is '.str_replace('_', ' ', $state).' cannot receive '.($future ? 'future' : 'current').' compensation.');
        }
        if ($employee->exit_date !== null && $from->gt($employee->exit_date) && in_array($state, ['notice_period', 'exited', 'alumni'], true)) {
            throw new CompensationRuleViolation('Compensation cannot start after the employee\'s exit date ('.$employee->exit_date->toDateString().').');
        }
    }

    /** A same-date correction replaces compensation that took effect; a scheduled one is cancelled instead. */
    private function assertCorrectionTargetsEffective(CompensationChange $change): void
    {
        if ($change->change_type !== 'correction') {
            return;
        }
        $target = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()
            ->where('employee_id', $change->employee_id)->where('effective_from', '>=', $change->effective_from->toDateString())
            ->where('effective_from', '<=', $change->effective_from->toDateString().' 23:59:59')->first();
        if ($target?->compensation_change_id && CompensationChange::query()->withoutGlobalScope(AccessScope::class)->whereKey($target->compensation_change_id)->value('status') === 'scheduled') {
            throw new CompensationRuleViolation('The compensation starting on '.$change->effective_from->toDateString().' has not taken effect yet; cancel that change and propose a new one instead of correcting it.');
        }
    }

    private function assertPreviousUnchanged(CompensationChange $change, Employee $employee): void
    {
        $now = $this->inForce($employee, $change->effective_from)?->id;
        if ($now !== ($change->previous_assignment_id ? (int) $change->previous_assignment_id : null)) {
            throw new CompensationRuleViolation('The compensation in force on '.$change->effective_from->toDateString().' has changed since this change was submitted; return it to the proposer.');
        }
    }

    private function inForce(Employee $employee, CarbonInterface $on): ?EmployeeSalaryAssignment
    {
        return EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()
            ->where('employee_id', $employee->id)->effectiveOn($on)->orderByDesc('effective_from')->first();
    }

    private function reachable(User $actor, int $employeeId): Employee
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($employeeId);
        if ($employee === null || ! $this->scopes->allows($actor, $employee)) {
            throw new CompensationRuleViolation('That employee is outside your organisation scope.');
        }

        return $employee;
    }

    private function assertNotOwn(User $actor, Employee $employee): void
    {
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->id) {
            throw new CompensationRuleViolation('No one proposes, reviews, approves or executes their own compensation.');
        }
    }

    /** @param  list<string>  $duties  proposer / reviewer / approver / executor */
    private function assertNotActor(CompensationChange $change, User $actor, array $duties, string $step): void
    {
        foreach ($duties as $duty) {
            if ((int) ($change->actors()[$duty] ?? 0) === (int) $actor->id) {
                throw new CompensationRuleViolation("The {$duty} of a compensation change cannot {$step} it.");
            }
        }
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

    private function requireText(?string $text, string $message): void
    {
        if (trim((string) $text) === '') {
            throw new CompensationRuleViolation($message);
        }
    }

    /**
     * Active users holding a permission who may reach the employee, minus the given users.
     *
     * @param  list<int|null>  $except
     * @return list<int>
     */
    private function holders(string $permission, Employee $employee, array $except = []): array
    {
        $except = array_map('intval', array_filter($except));

        return User::query()->forCurrentTenant()->get()
            ->filter(fn (User $u) => $u->isActive() && ! in_array((int) $u->id, $except, true) && (int) $u->id !== (int) $employee->user_id
                && $u->hasPermission($permission) && $this->scopes->allows($u, $employee))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @param  list<int>  $recipients */
    private function event(string $name, CompensationChange $change, Employee $employee, array $recipients): void
    {
        if (self::$quiet) {
            return;
        }
        CompensationEvent::dispatch($name, $employee, $change, [
            'reference' => $change->reference, 'change_type' => $change->change_type, 'status' => $change->status,
            'effective_date' => $change->effective_from->toDateString(), 'employee_code' => $employee->employee_code,
        ], array_values(array_unique(array_filter($recipients))));
    }
}
