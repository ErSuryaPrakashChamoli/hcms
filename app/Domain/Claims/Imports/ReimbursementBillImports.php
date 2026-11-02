<?php

namespace App\Domain\Claims\Imports;

use App\Domain\Claims\Models\ReimbursementBill;
use App\Domain\Claims\Services\ReimbursementBills;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Imports\PayrollImportCells;
use App\Domain\Payroll\Models\SalaryComponent;

/**
 * Data import DI-4 (Payroll → Reimbursement bills, pay by default): bills employees hand in for reimbursements paid
 * with salary every month, one bill per row, recorded through ReimbursementBills::record (the Reimbursement bills
 * screen). From the organisation's bills date (Setup → Payroll), salary TDS treats what each such reimbursement pays in
 * the year beyond its bills as taxable salary. Rows only add bills; the same bill already on file is skipped.
 */
final class ReimbursementBillImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'reimbursement_bills';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'component' => ['Reimbursement code', true], 'bill_date' => ['Bill date', true], 'amount' => ['Bill amount', true],
            'bill_number' => ['Bill number', false], 'vendor' => ['Vendor', false], 'description' => ['Description', false],
        ];
    }

    public static function intents(): bool
    {
        return false; // each row adds one bill
    }

    public function label(): string
    {
        return 'Reimbursement bill upload';
    }

    protected function module(): string
    {
        return 'payroll';
    }

    protected function entityType(): string
    {
        return ReimbursementBill::class;
    }

    protected function aliases(): array
    {
        return ['component_code' => 'component', 'reimbursement' => 'component', 'date' => 'bill_date', 'invoice_date' => 'bill_date', 'amount' => 'amount',
            'bill_no' => 'bill_number', 'invoice_number' => 'bill_number', 'merchant' => 'vendor', 'remarks' => 'description'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $component = $this->component($r['component'] ?? null);
        if ($component === null) {
            $errors[] = "Reimbursement '".($r['component'] ?? '')."' is not an active reimbursement paid with salary every month.";
        }
        $date = $this->date($r['bill_date'] ?? null);
        if ($date === null || $date > now()->toDateString()) {
            $errors[] = 'Bill date must be a date (YYYY-MM-DD), today or earlier.';
        }
        $amount = $this->money($r['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Bill amount must be a number above zero.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        $uploader = $this->uploader($import);
        if ($uploader === null || ! app(AccessScopes::class)->allows($uploader, Employee::query()->findOrFail($employeeId))) {
            return ['action' => 'error', 'errors' => ['The employee is outside your organisation scope.'], 'employee_id' => $employeeId];
        }
        $number = isset($r['bill_number']) ? mb_substr(trim($r['bill_number']), 0, 60) : null;
        if (($duplicate = $this->seenBefore(implode('|', [$employeeId, $component->id, $date, $number, $amount]), $context, $row->row_number, 'bill')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }
        $onFile = ReimbursementBill::query()->active()->where('employee_id', $employeeId)->where('salary_component_id', $component->id)->whereDate('bill_date', $date)
            ->where('bill_number', $number)->where('amount', $amount)->exists();

        return $onFile
            ? ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'This bill is already on file.']]
            : ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $component = $this->component($r['component']);
        $bill = app(ReimbursementBills::class)->record(Employee::query()->findOrFail($row->employee_id), $component, $this->date($r['bill_date']), (float) $this->money($r['amount']),
            $r['bill_number'] ?? null, $r['vendor'] ?? null, $r['description'] ?? null, $user);

        return "{$component->name} bill of ".number_format((float) $bill->amount, 2).' recorded';
    }

    private function component(?string $code): ?SalaryComponent
    {
        $code = strtoupper(trim((string) $code));
        $component = $code === '' ? null : SalaryComponent::query()->where('code', $code)->first();

        return $component !== null && ReimbursementBills::takesBills($component) ? $component : null;
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Reimbursement code', 'Yes', 'Code of a reimbursement paid with salary every month (Payroll → Pay components, "Paid on claim" off).', $this->sampleComponent()],
                ['Bill date', 'Yes', 'YYYY-MM-DD, today or earlier; it decides the financial year.', now()->subDays(10)->toDateString()],
                ['Bill amount', 'Yes', 'The bill\'s amount.', '1499'],
                ['Bill number', 'No', 'The bill\'s number.', 'INV-7781'],
                ['Vendor', 'No', 'Who issued the bill.', 'Airtel'],
                ['Description', 'No', 'What the bill is for.', 'Mobile bill for October'],
            ],
            'codes' => SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', false)->where('status', 'active')->orderBy('name')->pluck('name', 'code')->all(),
            'codes_title' => 'Reimbursements paid with salary',
            'notes' => [
                'Each row adds one bill; the same bill already on file is skipped. Cancel a wrong one on Payroll → Reimbursement bills.',
                'From the date set in Setup → Payroll ("monthly reimbursements are taxable beyond their bills"), TDS treats what the reimbursement pays in the year beyond its bills as taxable salary. Blank date: bills are kept, and tax is as before.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);

        return [
            [$first, $this->sampleComponent(), now()->subDays(10)->toDateString(), '1499', 'INV-7781', 'Airtel', 'Mobile bill for October'],
            [$second, $this->sampleComponent(), now()->subDays(6)->toDateString(), '899', 'JIO-2210', 'Jio', 'Mobile bill for October'],
        ];
    }

    private function sampleComponent(): string
    {
        return (string) (SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', false)->where('status', 'active')->orderBy('name')->value('code') ?? 'PHONE');
    }
}
