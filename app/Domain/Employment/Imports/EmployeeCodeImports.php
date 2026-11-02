<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Employment\Actions\ChangeEmployeeCodeAction;
use App\Domain\Employment\Exceptions\EmployeeCodeTakenException;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use RuntimeException;

/**
 * Data import DI-2 (Workforce → Employee code): change employees' codes, one row per employee (old code → new code),
 * through ChangeEmployeeCodeAction: the hire form's rule, free across the whole tenant (not case-sensitive), audited and
 * on the timeline. Every row is a change of an employee on file, so the upload has no "new or existing" choice.
 */
final class EmployeeCodeImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'employee_codes';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'new_code' => ['New employee code', true], 'reason' => ['Reason', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Employee code upload';
    }

    protected function module(): string
    {
        return 'employment';
    }

    protected function entityType(): string
    {
        return Employee::class;
    }

    protected function aliases(): array
    {
        return ['old_code' => 'employee_code', 'old_employee_code' => 'employee_code', 'current_code' => 'employee_code', 'existing_code' => 'employee_code',
            'new_employee_code' => 'new_code', 'new_code' => 'new_code', 'remarks' => 'reason'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        $new = trim((string) ($r['new_code'] ?? ''));
        if ($new === '') {
            $errors[] = 'New employee code is required.';
        }
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        foreach (['employees' => [$employeeId, 'This employee'], 'codes' => [mb_strtolower($new), 'The new code '.$new]] as $list => [$key, $what]) {
            if (isset($context['seen'][$list][$key])) {
                return ['action' => 'error', 'errors' => ["{$what} is also in row {$context['seen'][$list][$key]}."], 'employee_id' => $employeeId];
            }
            $context['seen'][$list][$key] = $row->row_number;
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'matched_on' => ['employee code']];
        if ($new === $employee->employee_code) {
            return ['action' => 'skip', 'match' => $match + ['reason' => 'The employee already has this code.'], 'employee_id' => $employeeId];
        }
        $problem = app(ChangeEmployeeCodeAction::class)->problem($employee, $new) ?? $this->refusal($import, $employee, ChangeEmployeeCodeAction::PERMISSION);

        return $problem !== null
            ? ['action' => 'error', 'errors' => [$problem], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => ['employee_code']], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        $old = $employee->employee_code;
        $reason = trim((string) ($r['reason'] ?? '')) ?: $this->why($import);
        try {
            $saved = app(ChangeEmployeeCodeAction::class)->handle($employee, (string) $r['new_code'], $user, $reason, $this->origin($import));
        } catch (EmployeeCodeTakenException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return "{$old} → {$saved->employee_code}";
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', 'The employee\'s code today.', 'EMP0001'],
                ['New employee code', 'Yes', 'Letters, digits, dashes and underscores, at most 32; not held by any other employee (not case-sensitive).', 'MKE-0001'],
                ['Reason', 'No', 'Why the code changes (audited; shown on the timeline). Blank: the upload\'s name.', 'New code series'],
            ],
            'notes' => [
                'Each row changes one employee\'s code. An employee and a new code may each appear once in a file.',
                'A code held by another employee is refused, also when that employee changes code in the same file: to swap codes, give one employee a temporary code first, in an earlier file.',
                'Payslips, letters and reports already issued keep the code printed on them. Systems that send the employee code (such as an attendance device without a device user id) must use the new code; set a device user id with the Employee settings upload to keep a device\'s id.',
                'Needs employee.update within your organisation scope. Each change is audited and shown on the employee\'s timeline.',
            ],
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(2)->pluck('employee_code')->all();

        return [
            [$codes[0] ?? 'EMP0001', 'MKE-'.mb_substr(preg_replace('/\D/', '', (string) ($codes[0] ?? '1')) ?: '1', -4).'A', 'New code series'],
            [$codes[1] ?? 'EMP0002', 'MKE-'.mb_substr(preg_replace('/\D/', '', (string) ($codes[1] ?? '2')) ?: '2', -4).'B', 'New code series'],
        ];
    }
}
