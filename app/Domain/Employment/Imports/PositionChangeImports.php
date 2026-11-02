<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Services\OrganisationCodes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Data import DI-2 (Workforce → Employee (advance)): dated job and organisation changes of employees on file — any of
 * company, location, business unit, division, department, team, designation, level, grade, employment type, employee
 * category, work mode and cost centre (by code), and the line manager — each row one change from its effective date.
 * The position is opened through AssignPositionAction (the Employee 360's "Transfer / promote": effective-dated, never
 * editing history, audited, on the timeline, employment events) and the manager through ChangeManagerAction::change.
 * Blank cells are "unchanged". Needs employee.position within organisation scope. Only existing employees: a row is
 * always a change, so the upload has no "new or existing" choice.
 */
final class PositionChangeImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'position_changes';

    public const PERMISSION = 'employee.position';

    /** row field (an OrganisationCodes code field) => its label */
    private const DIMENSIONS = [
        'company_code' => 'Company code', 'location_code' => 'Location code', 'business_unit_code' => 'Business unit code', 'division_code' => 'Division code',
        'department_code' => 'Department code', 'team_code' => 'Team code', 'designation_code' => 'Designation code', 'level_code' => 'Level code',
        'grade_code' => 'Grade code', 'employment_type_code' => 'Employment type code', 'employee_category_code' => 'Employee category code',
        'work_mode_code' => 'Work mode code', 'cost_centre_code' => 'Cost centre code',
    ];

    public static function fields(): array
    {
        $fields = ['employee_code' => ['Employee code', true], 'effective_from' => ['Effective date', true], 'change_type' => ['Change type', false]];
        foreach (self::DIMENSIONS as $field => $label) {
            $fields[$field] = [$label, false];
        }

        return $fields + ['manager_code' => ['Manager employee code', false], 'reason' => ['Reason', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Employee (advance) upload';
    }

    protected function module(): string
    {
        return 'employment';
    }

    protected function entityType(): string
    {
        return EmployeePosition::class;
    }

    protected function aliases(): array
    {
        return ['effective_date' => 'effective_from', 'effective_from' => 'effective_from', 'with_effect_from' => 'effective_from', 'date' => 'effective_from',
            'type' => 'change_type', 'branch_code' => 'location_code', 'branch' => 'location_code', 'company' => 'company_code', 'department' => 'department_code',
            'designation' => 'designation_code', 'grade' => 'grade_code', 'level' => 'level_code', 'manager' => 'manager_code', 'reporting_manager' => 'manager_code',
            'reporting_manager_code' => 'manager_code', 'manager_code' => 'manager_code', 'remarks' => 'reason'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        [$from, $type, $dimensions, $managerId, $problems] = $this->parse($r, $context);
        $errors = [...$errors, ...$problems];
        if ($errors !== [] || $employee === null || $from === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        // Rows of one employee apply in file order, so they must run forwards in time.
        if (isset($context['seen'][$employeeId]) && $context['seen'][$employeeId][1] >= $from) {
            [$earlier, $date] = $context['seen'][$employeeId];

            return ['action' => 'error', 'errors' => ["Row {$earlier} changes {$employee->employee_code} from {$date}: put the rows of one employee in date order, one row per date."], 'employee_id' => $employeeId];
        }
        $context['seen'][$employeeId] = [$row->row_number, $from];
        if (($refusal = $this->refusal($import, $employee, self::PERMISSION)) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        [$positionChanges, $managerChange, $problem] = $this->changes($employee, $from, $dimensions, $managerId);
        if ($problem !== null) {
            return ['action' => 'error', 'errors' => [$problem], 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'matched_on' => ['employee code']];
        if ($positionChanges === [] && ! $managerChange) {
            return ['action' => 'skip', 'match' => $match + ['reason' => 'Already in this position (and with this manager) on that date.'], 'employee_id' => $employeeId];
        }

        return ['action' => 'update', 'match' => $match + ['changes' => [...$positionChanges, ...($managerChange ? ['manager'] : [])]], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        $context = [];
        [$from, $type, $dimensions, $managerId, $problems] = $this->parse($r, $context);
        if ($problems !== [] || $from === null) {
            throw new RuntimeException(implode(' ', $problems) ?: 'The row could not be read.');
        }
        app(ProfileChangeGuard::class)->authorize($user, $employee, self::PERMISSION);
        [$positionChanges, $managerChange, $problem] = $this->changes($employee, $from, $dimensions, $managerId);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        if ($positionChanges === [] && ! $managerChange) {
            return "{$employee->employee_code} from {$from}: already in this position, nothing changed";
        }
        $reason = trim((string) ($r['reason'] ?? '')) ?: $this->why($import);
        $done = [];
        try {
            if ($positionChanges !== []) {
                app(AssignPositionAction::class)->handle($employee, $dimensions, $type, $from, $reason);
                $done[] = (string) config("peopleos.people.position_change_types.{$type}", ucfirst($type)).' ('.implode(', ', $positionChanges).')';
            }
            if ($managerChange) {
                app(ChangeManagerAction::class)->change($employee, Employee::query()->findOrFail($managerId), $user, 'line', $from, $reason);
                $done[] = 'manager changed';
            }
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return "{$employee->employee_code} from {$from}: ".implode('; ', $done);
    }

    public function format(): array
    {
        $types = array_diff_key((array) config('peopleos.people.position_change_types', []), ['hire' => true]);
        $columns = [
            ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
            ['Effective date', 'Yes', 'The day the change starts: YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY. After the start of the employee\'s current position; one employee\'s rows in date order.', now()->startOfMonth()->toDateString()],
            ['Change type', 'No', 'One of: '.implode(', ', array_keys($types)).' (or the label). Blank: transfer.', 'transfer'],
        ];
        foreach (self::DIMENSIONS as $label) {
            $columns[] = [$label, 'No', 'The code of the new '.mb_strtolower(str_replace(' code', '', $label)).' (Core masters). Blank: unchanged.', $label === 'Department code' ? 'FIN' : ''];
        }

        return [
            'columns' => [...$columns,
                ['Manager employee code', 'No', 'The new line manager\'s employee code. Blank: unchanged.', ''],
                ['Reason', 'No', 'Why the change is made (audited; shown on the timeline). Blank: the upload\'s name.', 'Reorganisation'],
            ],
            'codes' => $types,
            'codes_title' => 'Change types',
            'notes' => [
                'Each row opens a new position from its effective date with only the given values changed, as "Transfer / promote" on the Employee 360 does; the current position ends the day before. History is never edited.',
                'A row whose values (and manager) are already the employee\'s on that date is skipped.',
                'The effective date must be after the start of the employee\'s current position, and no later position may be on file.',
                'Needs employee.position within your organisation scope (the manager too). Each change is audited and shown on the employee\'s timeline.',
            ],
        ];
    }

    protected function examples(): array
    {
        $employees = Employee::query()->orderBy('id')->limit(3)->get(['id', 'employee_code']);
        $code = $employees->get(0)?->employee_code ?? 'EMP0001';
        $second = $employees->get(1)?->employee_code ?? 'EMP0002';
        $manager = $employees->get(2)?->employee_code ?? '';
        [, $departments] = OrganisationCodes::DIMENSIONS['department_code'];
        [, $designations] = OrganisationCodes::DIMENSIONS['designation_code'];
        $department = $departments::query()->orderBy('id')->value('code') ?? 'FIN';
        $designation = $designations::query()->orderBy('id')->value('code') ?? 'ACC';
        $from = now()->addMonth()->startOfMonth()->toDateString();
        $blank = array_fill(0, count(self::DIMENSIONS), '');
        $first = array_replace($blank, [4 => $department]);
        $promotion = array_replace($blank, [6 => $designation]);

        return [
            [$code, $from, 'transfer', ...$first, '', 'Reorganisation'],
            [$second, $from, 'promotion', ...$promotion, $manager, 'Annual promotion'],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The effective date, change type, the dimension ids given and the manager, with what is wrong.
     *
     * @return array{0: ?string, 1: string, 2: array<string, int>, 3: ?int, 4: list<string>}
     */
    private function parse(array $r, array &$context): array
    {
        $errors = [];
        $from = $this->dateCell($r['effective_from'] ?? null, 'Effective date', $errors);
        if (! isset($r['effective_from'])) {
            $errors[] = 'Effective date is required.';
        }
        $types = array_diff_key((array) config('peopleos.people.position_change_types', []), ['hire' => true]);
        $type = 'transfer';
        if (isset($r['change_type'])) {
            $type = self::codeIn($types, $r['change_type']) ?? '';
            if ($type === '') {
                $errors[] = 'Change type must be one of: '.implode(', ', array_keys($types)).'.';
            }
        }
        $dimensions = [];
        foreach (self::DIMENSIONS as $field => $label) {
            if (! isset($r[$field])) {
                continue;
            }
            [$column, $model] = OrganisationCodes::DIMENSIONS[$field];
            $context['codes'][$model] ??= $model::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower((string) $code) => (int) $id])->all();
            $id = $context['codes'][$model][mb_strtolower(trim($r[$field]))] ?? null;
            $id === null ? $errors[] = "{$label} '{$r[$field]}' does not exist." : $dimensions[$column] = $id;
        }
        $managerId = null;
        if (isset($r['manager_code'])) {
            // As the Employee 360 offers managers: employed people within the organisation scope.
            $managerId = Employee::query()->employed()->whereRaw('lower(employee_code) = ?', [mb_strtolower(trim($r['manager_code']))])->value('id');
            if ($managerId === null) {
                $errors[] = "Manager employee code '{$r['manager_code']}' is not an employed person within your organisation scope.";
            } elseif (mb_strtolower(trim($r['manager_code'])) === mb_strtolower(trim((string) ($r['employee_code'] ?? '')))) {
                $errors[] = 'An employee cannot report to themselves.';
            }
        }
        if ($dimensions === [] && ! isset($r['manager_code']) && $errors === []) {
            $errors[] = 'Give at least one new value (a code or the manager).';
        }

        return [$from, $type, $dimensions, $managerId === null ? null : (int) $managerId, array_values(array_unique($errors))];
    }

    /**
     * What the row changes for the employee on that date — the dimensions that differ and whether the line manager does —
     * or why the position cannot be opened (the rules of AssignPositionAction).
     *
     * @param  array<string, int>  $dimensions
     * @return array{0: list<string>, 1: bool, 2: ?string}
     */
    private function changes(Employee $employee, string $from, array $dimensions, ?int $managerId): array
    {
        $day = Carbon::parse($from);
        $current = $employee->positions()->effectiveOn($day)->first();
        $changed = [];
        foreach ($dimensions as $column => $id) {
            if ((int) $current?->getAttribute($column) !== $id) {
                $changed[] = EmployeePosition::DIMENSIONS[$column][1];
            }
        }
        if ($changed !== []) {
            if ($current !== null && $current->effective_from->gte($day)) {
                return [$changed, false, 'The change must start after the current position ('.$current->effective_from->toDateString().'); correct that position on the Employee 360 instead.'];
            }
            $future = $employee->positions()->where('effective_from', '>', $day->toDateString())->orderBy('effective_from')->first();
            if ($future !== null) {
                return [$changed, false, 'A position already starts on '.$future->effective_from->toDateString().'; positions cannot overlap. Use a date after it.'];
            }
            if ($current === null && ! isset($dimensions['company_id'])) {
                return [$changed, false, 'The employee has no position on that date: give the company code too.'];
            }
        }
        $managerChange = false;
        if ($managerId !== null) {
            $line = ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->where('type', 'line')->effectiveOn($day)->first();
            $managerChange = (int) $line?->manager_id !== $managerId;
        }

        return [$changed, $managerChange, null];
    }
}
