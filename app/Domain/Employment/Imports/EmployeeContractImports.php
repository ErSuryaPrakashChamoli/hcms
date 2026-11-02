<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeContract;
use App\Domain\Employment\Services\EmployeeContracts;
use App\Domain\Identity\Models\User;
use App\Domain\Partners\Models\Vendor;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Data import (Workforce → Contract): contracts of contract staff — the agency (vendor) that supplies them, the contract
 * number and its dates — written through EmployeeContracts (employee.update in the uploader's scope, validation, audit,
 * timeline). A row whose employee already has the contract number updates that contract with the cells given; any other
 * row adds a contract, which then needs its start date.
 */
final class EmployeeContractImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'employee_contracts';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'contract_number' => ['Contract number', true], 'vendor_code' => ['Agency (vendor) code', false],
            'start_date' => ['Starts on', false], 'end_date' => ['Ends on', false], 'notes' => ['Notes', false],
        ];
    }

    public function label(): string
    {
        return 'Contract upload';
    }

    protected function module(): string
    {
        return 'employment';
    }

    protected function entityType(): string
    {
        return EmployeeContract::class;
    }

    protected function aliases(): array
    {
        return ['contract_no' => 'contract_number', 'contract' => 'contract_number', 'agency' => 'vendor_code', 'agency_code' => 'vendor_code', 'vendor' => 'vendor_code',
            'vendor_code' => 'vendor_code', 'start_date' => 'start_date', 'from' => 'start_date', 'end_date' => 'end_date', 'to' => 'end_date', 'remarks' => 'notes'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['vendors' => Vendor::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $key = $employeeId.'|'.mb_strtolower($r['contract_number'] ?? '');
        if ($employeeId !== null && isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same employee and contract number are in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;
        $data = $this->data($r, $context, $errors);
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $employee = Employee::query()->find($employeeId);
        if ($employee === null) {
            return ['action' => 'error', 'errors' => ['That employee is outside your organisation scope.'], 'employee_id' => $employeeId];
        }
        if (($refusal = $this->refusal($import, $employee, EmployeeContracts::PERMISSION)) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $contract = $this->contract($employee, $r['contract_number']);
        if ($contract === null && ! ImportMode::of($import)->adds()) {
            return ['action' => 'create', 'employee_id' => $employeeId];
        }
        if ($contract !== null) {
            unset($data['contract_number']); // the key it was found by (any case); never rewritten
        }
        if ($contract === null && ! isset($data['start_date'])) {
            return ['action' => 'error', 'errors' => ['Starts on is required for a new contract.'], 'employee_id' => $employeeId];
        }
        $problems = app(EmployeeContracts::class)->problems($employee, $data, $contract);
        if ($problems !== []) {
            return ['action' => 'error', 'errors' => array_merge(...array_values($problems)), 'employee_id' => $employeeId];
        }
        if ($contract !== null) {
            $same = collect($data)->every(fn ($value, $field) => self::sameValue($contract->getAttribute($field), $value));

            return $same
                ? ['action' => 'skip', 'match' => ['reason' => 'Already on file with these values.'], 'employee_id' => $employeeId]
                : ['action' => 'update', 'match' => ['id' => $contract->getKey()], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        $errors = [];
        $data = $this->data($r, $this->prepare($import), $errors);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        $contract = $this->contract($employee, $r['contract_number']);
        if ($contract !== null) {
            unset($data['contract_number']);
        }
        try {
            $saved = app(EmployeeContracts::class)->save($employee, $data, $user, $contract, $this->why($import), $this->origin($import));
        } catch (ValidationException $e) {
            throw new RuntimeException(implode(' ', array_merge(...array_values($e->errors()))));
        }

        return ($contract === null ? 'Added contract ' : 'Updated contract ').$saved->contract_number;
    }

    /** The contract values a row gives (blank cells are left out, so an update keeps them). @param list<string> $errors */
    private function data(array $r, array $context, array &$errors): array
    {
        $data = ['contract_number' => trim((string) ($r['contract_number'] ?? ''))];
        if (isset($r['vendor_code'])) {
            $data['vendor_id'] = $context['vendors'][mb_strtolower($r['vendor_code'])] ?? null;
            if ($data['vendor_id'] === null) {
                $errors[] = "Agency (vendor) code '{$r['vendor_code']}' does not exist (Workforce → Vendors).";
            }
        }
        foreach (['start_date' => 'Starts on', 'end_date' => 'Ends on'] as $field => $label) {
            if (($date = $this->dateCell($r[$field] ?? null, $label, $errors)) !== null) {
                $data[$field] = $date;
            }
        }
        if (isset($r['notes'])) {
            $data['notes'] = $r['notes'];
        }

        return $data;
    }

    private function contract(Employee $employee, string $number): ?EmployeeContract
    {
        return EmployeeContract::query()->where('employee_id', $employee->getKey())->whereRaw('lower(contract_number) = ?', [mb_strtolower(trim($number))])->first();
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', 'The contract staff member\'s employee code.', 'EMP0101'],
                ['Contract number', 'Yes', 'The contract\'s number. An employee\'s contract number on file updates that contract; a new one adds a contract.', 'SKY/2026/014'],
                ['Agency (vendor) code', 'No', 'The code of the agency that supplies the person (Workforce → Vendors); blank for a direct contract.', 'AGY-001'],
                ['Starts on', 'To add', 'YYYY-MM-DD (or DD-MM-YYYY).', '2026-04-01'],
                ['Ends on', 'No', 'YYYY-MM-DD; blank while open-ended. Not before the start.', '2027-03-31'],
                ['Notes', 'No', 'Anything worth keeping with the contract.', 'Renewal of SKY/2025/009'],
            ],
            'notes' => [
                'Rows are matched by employee code and contract number; updating changes only the columns with a value.',
                'A new contract goes on the employee\'s timeline. Contracts are never deleted: give an ended one its end date.',
                'Only active vendors can be given to a new contract.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0101';
        $vendor = Vendor::query()->where('status', 'active')->orderBy('id')->value('code') ?? 'AGY-001';

        return [[$code, 'SKY/2026/014', $vendor, '2026-04-01', '2027-03-31', 'Renewal of SKY/2025/009'], [$code, 'SKY/2025/009', $vendor, '2025-04-01', '2026-03-31', '']];
    }
}
