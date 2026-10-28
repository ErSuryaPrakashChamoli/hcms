<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use Illuminate\Database\Eloquent\Builder;

/**
 * Statutory identifiers and the primary bank account per employee, for completeness checks. Identifiers
 * are always masked (last four characters); full values stay in the returns and the bank advice.
 */
class StatutoryDetailsDataset extends Dataset
{
    public function key(): string
    {
        return 'statutory_bank';
    }

    public function label(): string
    {
        return 'Statutory IDs and bank details (PAN, UAN, ESI, bank; masked)';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['employee.sensitive.view'];
    }

    public function fields(): array
    {
        $s = fn ($e) => $e->statutoryDetail;
        $bank = fn ($e) => $e->bankAccounts->sortByDesc('is_primary')->first();

        return [
            ...$this->employeeFields(fn ($e) => $e),
            'joining_date' => ['label' => 'Joining date', 'type' => 'date', 'value' => fn ($e) => $e->joining_date],
            'pan' => ['label' => 'PAN (masked)', 'type' => 'string', 'value' => fn ($e) => EmployeeStatutoryDetail::mask($s($e)?->pan)],
            'has_pan' => ['label' => 'PAN on file', 'type' => 'boolean', 'value' => fn ($e) => filled($s($e)?->pan)],
            'has_aadhaar' => ['label' => 'Aadhaar on file', 'type' => 'boolean', 'value' => fn ($e) => filled($s($e)?->aadhaar_reference)],
            'uan' => ['label' => 'UAN (masked)', 'type' => 'string', 'value' => fn ($e) => EmployeeStatutoryDetail::mask($s($e)?->uan)],
            'has_uan' => ['label' => 'UAN on file', 'type' => 'boolean', 'value' => fn ($e) => filled($s($e)?->uan)],
            'pf_number' => ['label' => 'PF number (masked)', 'type' => 'string', 'value' => fn ($e) => EmployeeStatutoryDetail::mask($s($e)?->pf_number)],
            'esic_number' => ['label' => 'ESI IP number (masked)', 'type' => 'string', 'value' => fn ($e) => EmployeeStatutoryDetail::mask($s($e)?->esic_number)],
            'has_esic' => ['label' => 'ESI IP number on file', 'type' => 'boolean', 'value' => fn ($e) => filled($s($e)?->esic_number)],
            'pf_applicable' => ['label' => 'PF applicable', 'type' => 'boolean', 'value' => fn ($e) => (bool) $s($e)?->pf_applicable],
            'esi_applicable' => ['label' => 'ESI applicable', 'type' => 'boolean', 'value' => fn ($e) => (bool) $s($e)?->esic_applicable],
            'pt_applicable' => ['label' => 'PT applicable', 'type' => 'boolean', 'value' => fn ($e) => (bool) $s($e)?->pt_applicable],
            'pt_state' => ['label' => 'PT state', 'type' => 'string', 'value' => fn ($e) => $s($e)?->pt_state_code],
            'tax_regime' => ['label' => 'Tax regime', 'type' => 'string', 'value' => fn ($e) => $s($e)?->tax_regime],
            'has_bank' => ['label' => 'Bank account on file', 'type' => 'boolean', 'value' => fn ($e) => $bank($e) !== null],
            'bank_name' => ['label' => 'Bank', 'type' => 'string', 'value' => fn ($e) => $bank($e)?->bank_name],
            'branch' => ['label' => 'Branch', 'type' => 'string', 'value' => fn ($e) => $bank($e)?->branch_name],
            'ifsc' => ['label' => 'IFSC', 'type' => 'string', 'value' => fn ($e) => $bank($e)?->ifsc],
            'account_number' => ['label' => 'Account (masked)', 'type' => 'string', 'value' => fn ($e) => $bank($e)?->maskedAccountNumber()],
            'account_type' => ['label' => 'Account type', 'type' => 'string', 'value' => fn ($e) => $bank($e)?->account_type],
            'bank_verified' => ['label' => 'Bank account verified', 'type' => 'boolean', 'value' => fn ($e) => $bank($e)?->verified_at !== null],
        ];
    }

    public function query(): Builder
    {
        return Employee::query()->with(['person', 'currentPosition.company', 'currentPosition.location', 'currentPosition.department', 'currentPosition.designation', 'statutoryDetail', 'bankAccounts'])->orderBy('employee_code');
    }
}
