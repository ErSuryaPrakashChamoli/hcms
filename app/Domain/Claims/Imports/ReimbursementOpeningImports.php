<?php

namespace App\Domain\Claims\Imports;

use App\Domain\Claims\Models\ReimbursementLedgerEntry;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Imports\PayrollImportCells;
use App\Domain\Payroll\Models\SalaryComponent;
use RuntimeException;

/**
 * Data import DI-4 (Payroll → Reimbursement opening balance, pay on claim): what employees had accrued and not claimed
 * of a pay-on-claim reimbursement when PeopleOS went live mid-year, one employee, reimbursement and financial year per
 * row, posted once to the entitlement ledger through ReimbursementClaims::opening(). An opening already posted is never
 * changed (the ledger is append-only), so such rows are skipped. Claims are then checked against the balance it gives.
 */
final class ReimbursementOpeningImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'reimbursement_opening';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'component' => ['Reimbursement code', true], 'financial_year' => ['Financial year', true],
            'amount' => ['Opening balance', true], 'note' => ['Note', false],
        ];
    }

    public static function intents(): bool
    {
        return false; // an opening is posted once and never changed
    }

    public function label(): string
    {
        return 'Reimbursement opening balance upload';
    }

    protected function module(): string
    {
        return 'payroll';
    }

    protected function entityType(): string
    {
        return ReimbursementLedgerEntry::class;
    }

    protected function aliases(): array
    {
        return ['component_code' => 'component', 'reimbursement' => 'component', 'fy' => 'financial_year', 'year' => 'financial_year', 'balance' => 'amount', 'opening' => 'amount', 'remarks' => 'note'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $component = $this->component($r['component'] ?? null);
        if ($component === null) {
            $errors[] = "Reimbursement '".($r['component'] ?? '')."' is not an active reimbursement paid on claim.";
        }
        $year = $this->financialYearLabel($r['financial_year'] ?? null);
        if ($year === null) {
            $errors[] = 'Financial year must look like 2026-27.';
        }
        $amount = $this->money($r['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Opening balance must be a number above zero.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        $uploader = $this->uploader($import);
        if ($uploader === null || ! app(AccessScopes::class)->allows($uploader, Employee::query()->findOrFail($employeeId))) {
            return ['action' => 'error', 'errors' => ['The employee is outside your organisation scope.'], 'employee_id' => $employeeId];
        }
        $start = (int) substr($year, 0, 4);
        if (ReimbursementLedgerEntry::query()->where('employee_id', $employeeId)->where('salary_component_id', $component->id)->where('entry_key', "opening-{$start}")->exists()) {
            return ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => "The {$year} opening balance of {$component->name} is already posted; it is never changed."]];
        }
        if (($duplicate = $this->seenBefore("{$employeeId}|{$component->id}|{$start}", $context, $row->row_number, 'employee, reimbursement and year')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $component = $this->component($r['component']);
        $year = $this->financialYearLabel($r['financial_year']);
        $entry = app(ReimbursementClaims::class)->opening(Employee::query()->findOrFail($row->employee_id), $component, (int) substr($year, 0, 4), (float) $this->money($r['amount']),
            trim(($r['note'] ?? '') !== '' ? $r['note'] : 'Opening balance ('.$this->label()." #{$import->getKey()})"));
        if ($entry === null) {
            throw new RuntimeException("The {$year} opening balance of {$component->name} is already posted.");
        }

        return "{$component->name} opening of ".number_format((float) $entry->amount, 2)." posted for {$year}";
    }

    private function component(?string $code): ?SalaryComponent
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : SalaryComponent::query()->where('code', $code)->where('type', 'reimbursement')->where('pay_on_claim', true)->where('status', 'active')->first();
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Reimbursement code', 'Yes', 'Code of a reimbursement paid on claim (Payroll → Pay components).', $this->sampleComponent()],
                ['Financial year', 'Yes', 'April to March, as 2026-27.', $this->year()],
                ['Opening balance', 'Yes', 'Accrued and not yet claimed when PeopleOS went live.', '7500'],
                ['Note', 'No', 'Kept on the ledger entry.', 'Balance from the old system'],
            ],
            'codes' => SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', true)->where('status', 'active')->orderBy('name')->pluck('name', 'code')->all(),
            'codes_title' => 'Reimbursements paid on claim',
            'notes' => [
                'One opening per employee, reimbursement and year; one already posted is skipped and never changed (the ledger is append-only).',
                'Employees then claim against the balance it gives; payroll adds each month\'s accrual after go-live.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);

        return [
            [$first, $this->sampleComponent(), $this->year(), '7500', 'Balance from the old system'],
            [$second, $this->sampleComponent(), $this->year(), '3200', 'Balance from the old system'],
        ];
    }

    private function sampleComponent(): string
    {
        return (string) (SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', true)->where('status', 'active')->orderBy('name')->value('code') ?? 'FUEL');
    }

    private function year(): string
    {
        return app(ReimbursementClaims::class)->financialYear(now()).'-'.substr((string) (app(ReimbursementClaims::class)->financialYear(now()) + 1), 2);
    }
}
