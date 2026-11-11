<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Support\CompensationComponentLine;
use App\Domain\Compensation\Support\CompensationSegment;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HROne parity PO-2 (matrix 25 §25.2.1 #1–#5, §25.2.2 #18 / #30): the employee side of salary structures — which state
 * each employee's salary structure is in, and "Assign salary" for one or many employees.
 *
 * - State of an employee (HROne's chip): from the employee's latest compensation change that was not cancelled — open
 *   (draft, submitted, under review) = pending; approved or scheduled = approved (waiting to take effect); rejected =
 *   rejected; otherwise (no change, or the latest took effect) assigned when approved compensation is in force on the date
 *   (CompensationOutput), else not assigned.
 * - Assigning only proposes: every proposal goes through CompensationChanges::propose (and submit), so review, approval,
 *   execution by other people and the workflows apply exactly as for a proposal made on Employee 360. Nothing here
 *   approves, executes or writes the compensation history.
 * - "Revise by %" works the CTC out from the CTC in force on the effective date: round(CTC × (1 + % / 100), 2), the rule
 *   compensation cycles use.
 */
final class SalaryAssignments
{
    public const STATES = ['assigned' => 'Assigned', 'not_assigned' => 'Not assigned', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    /** Change statuses that make a request "pending" — and block a second proposal for the employee. */
    public const OPEN = ['draft', 'submitted', 'under_review'];

    /** Approved, not yet in force. */
    public const DECIDED = ['approved', 'scheduled'];

    /** How the new CTC is given: an amount, a percentage of the CTC in force, or the CTC in force unchanged. */
    public const CTC_MODES = ['amount' => 'Annual CTC', 'percent' => 'Revise by %', 'keep' => 'Keep the CTC in force'];

    public function __construct(
        private readonly CompensationOutput $output,
        private readonly CompensationChanges $changes,
        private readonly AccessScopes $scopes,
    ) {}

    // --- The register -------------------------------------------------------------------------------------------------

    /**
     * The latest change (not cancelled) of each employee.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, CompensationChange> employee id => change
     */
    public function latestRequests(array $employeeIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($ids === []) {
            return [];
        }

        return CompensationChange::query()->whereIn('employee_id', $ids)->where('status', '!=', 'cancelled')
            ->orderBy('id')->get(['id', 'reference', 'employee_id', 'change_type', 'source', 'status', 'effective_from', 'salary_structure_id', 'ctc_annual', 'currency', 'increase_percent', 'created_at', 'submitted_at'])
            ->keyBy(fn (CompensationChange $change) => (int) $change->employee_id)->all();
    }

    /** The register state for an employee's latest request and whether compensation is in force. */
    public static function stateOf(?CompensationChange $latest, bool $inForce): string
    {
        return match (true) {
            $latest !== null && in_array($latest->status, self::OPEN, true) => 'pending',
            $latest !== null && in_array($latest->status, self::DECIDED, true) => 'approved',
            $latest !== null && $latest->status === 'rejected' => 'rejected',
            $inForce => 'assigned',
            default => 'not_assigned',
        };
    }

    /**
     * Narrow an employees query to the given register states (the same rule as stateOf()).
     *
     * @param  Builder<Employee>  $employees
     * @param  list<string>  $states
     * @return Builder<Employee>
     */
    public function whereState(Builder $employees, array $states, CarbonInterface|string|null $on = null): Builder
    {
        $states = array_values(array_intersect($states, array_keys(self::STATES)));
        if ($states === []) {
            return $employees;
        }
        $id = $employees->getModel()->qualifyColumn('id');
        $latest = fn (array $statuses) => $this->latest()->whereIn('status', $statuses)->select('employee_id');
        $requested = [...self::OPEN, ...self::DECIDED, 'rejected'];
        $covered = fn () => $this->output->coveredEmployeeIds($on ?? today());

        return $employees->where(function (Builder $query) use ($states, $id, $latest, $requested, $covered) {
            foreach ($states as $state) {
                $query->orWhere(fn (Builder $q) => match ($state) {
                    'pending' => $q->whereIn($id, $latest(self::OPEN)),
                    'approved' => $q->whereIn($id, $latest(self::DECIDED)),
                    'rejected' => $q->whereIn($id, $latest(['rejected'])),
                    'assigned' => $q->whereNotIn($id, $latest($requested))->whereIn($id, $covered()),
                    'not_assigned' => $q->whereNotIn($id, $latest($requested))->whereNotIn($id, $covered()),
                });
            }
        });
    }

    /**
     * Employees with (or without) approved compensation in force on the date — HROne's "NO SALARY STRUCTURE".
     *
     * @param  Builder<Employee>  $employees
     * @return Builder<Employee>
     */
    public function whereInForce(Builder $employees, bool $inForce, CarbonInterface|string|null $on = null): Builder
    {
        $id = $employees->getModel()->qualifyColumn('id');

        return $inForce ? $employees->whereIn($id, $this->output->coveredEmployeeIds($on ?? today()))
            : $employees->whereNotIn($id, $this->output->coveredEmployeeIds($on ?? today()));
    }

    /**
     * Employees whose latest request came from one of the sources and / or was proposed in the date range.
     *
     * @param  Builder<Employee>  $employees
     * @param  list<string>  $sources
     * @return Builder<Employee>
     */
    public function whereRequest(Builder $employees, array $sources = [], ?string $from = null, ?string $until = null): Builder
    {
        $sources = array_values(array_intersect($sources, array_keys((array) config('peopleos.compensation.sources', []))));
        if ($sources === [] && blank($from) && blank($until)) {
            return $employees;
        }

        return $employees->whereIn($employees->getModel()->qualifyColumn('id'), $this->latest()
            ->when($sources !== [], fn (Builder $q) => $q->whereIn('source', $sources))
            ->when(filled($from), fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when(filled($until), fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse($until)->endOfDay()))
            ->select('employee_id'));
    }

    /**
     * How many employees of the (filtered) register are in each state.
     *
     * @param  Builder<Employee>  $employees
     * @return array<string, int>
     */
    public function counts(Builder $employees, CarbonInterface|string|null $on = null): array
    {
        $out = [];
        foreach (array_keys(self::STATES) as $state) {
            $out[$state] = $this->whereState($employees->clone()->reorder(), [$state], $on)->count();
        }

        return $out;
    }

    /** @return Builder<CompensationChange> the latest change (not cancelled) of each employee */
    private function latest(): Builder
    {
        return CompensationChange::query()->whereIn('id', CompensationChange::query()->where('status', '!=', 'cancelled')->selectRaw('max(id)')->groupBy('employee_id'));
    }

    // --- Assign salary --------------------------------------------------------------------------------------------------

    /** The new annual CTC for a revision by a percentage of the CTC in force (as compensation cycles work it out). */
    public static function revise(float $ctcAnnual, float $percent): float
    {
        return round($ctcAnnual * (1 + $percent / 100), 2);
    }

    /**
     * Why this person may not propose compensation for this employee here (null = may): outside their scope, their own
     * compensation, or a change already open for the employee. The service's own checks still run on proposing.
     */
    public function refusal(Employee $employee, User $actor): ?string
    {
        if (! $actor->hasPermission('compensation.propose')) {
            return 'This needs compensation.propose.';
        }
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->id) {
            return 'No one proposes their own compensation.';
        }
        if (! $this->scopes->allows($actor, $employee)) {
            return 'That employee is outside your access scope.';
        }
        $open = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->whereIn('status', self::OPEN)->orderByDesc('id')->first(['reference', 'status']);
        if ($open !== null) {
            return 'A compensation change is already pending for this employee ('.(CompensationChange::STATUSES[$open->status] ?? $open->status).', '.$open->reference.'); finish or cancel it first.';
        }

        return null;
    }

    /**
     * The proposal for one employee from the wizard's (or the bulk run's) answers, ready for CompensationChanges:
     * the structure ("keep" = the one in force), the CTC (an amount, a percentage of the CTC in force, or unchanged) and,
     * unless given, the fixed amounts, currency and variable target of the compensation in force (fixed amounts only for
     * components of the chosen structure). Throws a CompensationRuleViolation when it cannot be made.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function proposal(Employee $employee, array $input): array
    {
        try {
            $from = Carbon::parse((string) ($input['effective_from'] ?? ''))->startOfDay();
        } catch (Throwable) {
            throw new CompensationRuleViolation('A valid effective date is required.');
        }
        if (blank($input['effective_from'] ?? null)) {
            throw new CompensationRuleViolation('A valid effective date is required.');
        }
        $current = $this->output->on($employee, $from);
        $chosen = $input['salary_structure_id'] ?? 'keep';
        $structureId = $chosen === 'keep' || blank($chosen) ? $current?->structureId : (int) $chosen;
        if ($structureId === null) {
            throw new CompensationRuleViolation('No salary structure is in force on '.$from->format('j M Y').' to keep; choose a structure.');
        }
        $structure = SalaryStructure::query()->find($structureId);
        $version = $structure?->versionOn($from);
        $componentsMode = $version !== null && $version->formulaOptions()['ctc_mode'] === 'components';

        $mode = (string) ($input['ctc_mode'] ?? 'amount');
        $percent = null;
        if ($mode !== 'amount' && $current === null) {
            throw new CompensationRuleViolation('No compensation is in force on '.$from->format('j M Y').' to '.($mode === 'percent' ? 'revise by a percentage' : 'keep').'; enter the annual CTC.');
        }
        if ($mode === 'percent' && $componentsMode) {
            throw new CompensationRuleViolation("Structure {$structure->code} works out its CTC from the component amounts; revise the amounts instead of a percentage.");
        }
        $ctc = $input['ctc_annual'] ?? null;
        if ($mode === 'percent') {
            $value = $input['increase_percent'] ?? null;
            if (! is_numeric($value) || (float) $value <= -100 || (float) $value > 1000) {
                throw new CompensationRuleViolation('The increase must be a percentage above -100 and at most 1000.');
            }
            $percent = round((float) $value, 2);
            $ctc = self::revise($current->ctcAnnual, $percent);
        } elseif ($mode === 'keep') {
            $ctc = $current->ctcAnnual;
        }

        $values = array_key_exists('component_values', $input) && is_array($input['component_values'])
            ? $input['component_values']
            : $this->carriedValues($current, $structureId, $version);

        return [
            'change_type' => filled($input['change_type'] ?? null) ? (string) $input['change_type'] : ($current === null ? 'hire' : 'revision'),
            'effective_from' => $from->toDateString(),
            'salary_structure_id' => $structureId,
            'ctc_annual' => $ctc,
            // A percentage revises the CTC in its own currency.
            'currency' => $mode === 'amount' && filled($input['currency'] ?? null) ? (string) $input['currency'] : ($current?->currency ?? app(CurrencyRates::class)->baseCurrency()),
            'pay_frequency' => $current?->payFrequency ?? 'monthly',
            'component_values' => $values,
            'variable_target_annual' => array_key_exists('variable_target_annual', $input) ? $input['variable_target_annual'] : $current?->variableTargetAnnual,
            'reason' => (string) ($input['reason'] ?? ''),
            'internal_notes' => $input['internal_notes'] ?? null,
            'increase_percent' => $percent,
        ];
    }

    /**
     * Everything proposing would check, without writing: the proposal as CompensationChanges would save it (the CTC of a
     * components-mode structure worked out), or a CompensationRuleViolation.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function check(Employee $employee, array $input, User $actor): array
    {
        $data = $this->proposal($employee, $input);

        return $this->changes->check($employee, $data, $actor) + ['increase_percent' => $data['increase_percent']];
    }

    /**
     * Propose (and by default submit for review) one employee's salary through CompensationChanges — never approved or
     * executed here. Refused with the reason when refusal() says so.
     *
     * @param  array<string, mixed>  $input
     */
    public function propose(Employee $employee, array $input, User $actor, bool $submit = true): CompensationChange
    {
        if (($refusal = $this->refusal($employee, $actor)) !== null) {
            throw new CompensationRuleViolation($refusal);
        }
        $data = $this->proposal($employee, $input);

        return DB::transaction(function () use ($employee, $data, $actor, $submit) {
            $change = $this->changes->propose($employee, $data, $actor);
            if ($data['increase_percent'] !== null) {
                $change->update(['increase_percent' => $data['increase_percent']]);
            }

            return $submit ? $this->changes->submit($change, $actor) : $change;
        });
    }

    /**
     * The compensation a proposal would give, as a payroll segment of its effective date (the structure's approved version
     * on that date, its components and parameters) — for the breakup preview and the printed annexure. Null when the
     * structure has no approved version on the date.
     *
     * @param  array<string, mixed>  $proposal  as check() returns it
     */
    public function segment(Employee $employee, array $proposal): ?CompensationSegment
    {
        $from = Carbon::parse((string) $proposal['effective_from'])->startOfDay();
        $structure = SalaryStructure::query()->find($proposal['salary_structure_id'] ?? null);
        $version = $structure?->versionOn($from);
        if ($structure === null || $version === null) {
            return null;
        }
        $components = $version->components()->with('component')->get()
            ->filter(fn ($line) => $line->component !== null)
            ->map(fn ($line) => new CompensationComponentLine($line->component, $line->formula_override, (int) $line->sort_order))
            ->values();
        $variable = $proposal['variable_target_annual'] ?? null;
        $snapshot = new CompensationSnapshot(
            assignmentId: 0,
            employeeId: (int) $employee->id,
            changeId: isset($proposal['id']) ? (int) $proposal['id'] : null,
            changeType: (string) ($proposal['change_type'] ?? 'revision'),
            structureId: (int) $structure->id,
            structureVersionId: (int) $version->id,
            structureCode: $structure->code,
            currency: (string) ($proposal['currency'] ?? app(CurrencyRates::class)->baseCurrency()),
            payFrequency: (string) ($proposal['pay_frequency'] ?? 'monthly'),
            ctcAnnual: (float) ($proposal['ctc_annual'] ?? 0),
            variableTargetAnnual: $variable !== null && $variable !== '' ? (float) $variable : null,
            componentValues: array_map('floatval', array_change_key_case((array) ($proposal['component_values'] ?? []), CASE_UPPER)),
            effectiveFrom: $from,
            effectiveTo: null,
            approvedAt: null,
        );

        return new CompensationSegment($snapshot, $from->copy(), $from->copy(), $components, array_map('floatval', (array) ($version->parameters ?? [])), $version->formulaOptions()['ctc_mode']);
    }

    /** The segment of a compensation change's proposal (Print breakup on the salary structure requests). */
    public function segmentForChange(CompensationChange $change): ?CompensationSegment
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($change->employee_id);

        return $employee === null ? null : $this->segment($employee, ['id' => $change->id, ...$change->only(CompensationChange::CONTENT), 'pay_frequency' => $change->pay_frequency]);
    }

    /**
     * The fixed amounts and inputs of the compensation in force, kept for the components the chosen structure has.
     *
     * @return array<string, float>
     */
    public function carriedValues(?CompensationSnapshot $current, int $structureId, mixed $version = null): array
    {
        if ($current === null || $current->componentValues === []) {
            return [];
        }
        if ($version === null) {
            return $current->structureId === $structureId ? $current->componentValues : [];
        }
        $codes = $version->components()->with('component:id,code')->get()->map(fn ($line) => strtoupper((string) $line->component?->code))->filter()->all();

        return array_intersect_key($current->componentValues, array_flip($codes));
    }
}
