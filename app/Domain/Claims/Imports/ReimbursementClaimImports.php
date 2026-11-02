<?php

namespace App\Domain\Claims\Imports;

use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Imports\PayrollImportCells;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * Data import DI-4 (Payroll → Reimbursement claims, pay on claim): claims of pay-on-claim reimbursements raised for
 * employees, one claim (with its bill) per row, through ReimbursementClaims::claim() with every rule of the claim
 * screen: the balance (ledger less claims waiting, counting earlier rows of the file), one open claim per reimbursement,
 * bills adding up / not above the claim, the claim window and the per-claim limit, and bills from the new regime. Payroll
 * holds the bills of the claims it uploads (decision), so where bills must be attached the row lists its bill instead
 * (the claim is audited as "bills held by payroll"). Claims wait for approval by someone other than the uploader and are
 * paid by the next payroll. Rows only add claims; the same claim still open is skipped.
 */
final class ReimbursementClaimImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'reimbursement_claims';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'component' => ['Reimbursement code', true], 'claim_date' => ['Claim date', true], 'amount' => ['Claim amount', true],
            'description' => ['Description', true], 'bill_number' => ['Bill number', false], 'bill_date' => ['Bill date', false], 'vendor' => ['Vendor', false], 'bill_amount' => ['Bill amount', false],
        ];
    }

    public static function intents(): bool
    {
        return false; // each row raises one more claim
    }

    public function label(): string
    {
        return 'Reimbursement claim upload';
    }

    protected function module(): string
    {
        return 'payroll';
    }

    protected function entityType(): string
    {
        return ReimbursementClaim::class;
    }

    protected function aliases(): array
    {
        return ['component_code' => 'component', 'reimbursement' => 'component', 'date' => 'claim_date', 'claim' => 'amount', 'claimed' => 'amount', 'purpose' => 'description',
            'bill_no' => 'bill_number', 'invoice_number' => 'bill_number', 'invoice_date' => 'bill_date', 'merchant' => 'vendor', 'bill' => 'bill_amount'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $component = $this->component($r['component'] ?? null);
        if ($component === null) {
            $errors[] = "Reimbursement '".($r['component'] ?? '')."' is not an active reimbursement paid on claim.";
        }
        $date = $this->date($r['claim_date'] ?? null);
        if ($date === null || $date > now()->toDateString()) {
            $errors[] = 'Claim date must be a date (YYYY-MM-DD), today or earlier.';
        }
        $amount = $this->money($r['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Claim amount must be a number above zero.';
        }
        if (trim((string) ($r['description'] ?? '')) === '') {
            $errors[] = 'Describe what the claim is for.';
        }
        $bill = isset($r['bill_amount']) ? $this->money($r['bill_amount']) : null;
        if (isset($r['bill_amount']) && ($bill === null || $bill <= 0)) {
            $errors[] = 'Bill amount must be a number above zero.';
        }
        if (isset($r['bill_date']) && $this->date($r['bill_date']) === null) {
            $errors[] = 'Bill date must be a date (YYYY-MM-DD).';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $employee = Employee::query()->findOrFail($employeeId);
        $uploader = $this->uploader($import);
        // The claim service's on-behalf rule: the employee must be inside the uploader's payroll access scope.
        if ($uploader === null || ! app(AccessScopes::class)->allowsEmployeeId($uploader, (int) $employee->getKey(), 'payroll')) {
            return ['action' => 'error', 'errors' => ['The employee is outside your payroll access scope.'], 'employee_id' => $employeeId];
        }
        $problem = $this->ruleProblem($employee, $component, $date, $amount, $bill, $context);
        if ($problem !== null) {
            return ['action' => 'error', 'errors' => [$problem], 'employee_id' => $employeeId];
        }
        $same = ReimbursementClaim::query()->where('employee_id', $employeeId)->where('salary_component_id', $component->id)->whereDate('claim_date', $date)
            ->where('amount', $amount)->whereIn('status', ['pending', 'approved'])->exists();
        if ($same) {
            return ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'The same claim is already waiting or approved.']];
        }
        if (($duplicate = $this->seenBefore(implode('|', [$employeeId, $component->id, $date, $amount]), $context, $row->row_number, 'claim')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.$component->id.'|'.app(ReimbursementClaims::class)->financialYear($date);
        $context['claimed'][$key] = ($context['claimed'][$key] ?? 0) + $amount;

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $component = $this->component($r['component']);
        $bills = isset($r['bill_amount']) ? [array_filter(['number' => $r['bill_number'] ?? null, 'date' => $this->date($r['bill_date'] ?? null), 'vendor' => $r['vendor'] ?? null,
            'amount' => (float) $this->money($r['bill_amount'])], fn ($v) => $v !== null && $v !== '')] : [];
        $claim = app(ReimbursementClaims::class)->claim(Employee::query()->findOrFail($row->employee_id), $component, (float) $this->money($r['amount']), $this->date($r['claim_date']),
            trim($r['description']), $bills, [], $user, billsHeldByPayroll: true);

        return "{$component->name} claim of ".number_format((float) $claim->amount, 2).' sent for approval';
    }

    /** The claim screen's rules, before anything is written (the service checks them again when the row runs). */
    private function ruleProblem(Employee $employee, SalaryComponent $component, string $date, float $amount, ?float $bill, array $context): ?string
    {
        $settings = app(SettingsRepository::class);
        $claims = app(ReimbursementClaims::class);
        // Decision: payroll uploading claims holds the bills, so no attachment is asked for — but the bill must be listed.
        if ((bool) ($component->claim_attachment_required ?? $settings->get('payroll.claims.attachment_required', true)) && $bill === null) {
            return "Give the bill amount: {$component->name} claims need their bills, and payroll uploading the claim lists the bill it holds.";
        }
        if ((bool) $settings->get('payroll.claims.not_above_bills', false)) {
            if ($bill === null) {
                return 'Give the bill amount: claims cannot be above their bills.';
            }
            if ($amount > $bill + 0.001) {
                return 'The claim cannot be more than its bill.';
            }
        } elseif ($bill !== null && abs($bill - $amount) > 0.01) {
            return 'The bill must add up to the amount claimed.';
        }
        if ($settings->get('payroll.claims.new_regime_bills', 'allow') === 'block'
            && EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', app(FinancialYear::class)->label($date))->value('regime') === 'new') {
            return 'Bills are not taken under the new tax regime.';
        }
        if ($component->claim_limit_per_claim !== null && (float) $component->claim_limit_per_claim > 0 && $amount > (float) $component->claim_limit_per_claim) {
            return "{$component->name} can be claimed up to ".number_format((float) $component->claim_limit_per_claim, 2).' at a time.';
        }
        if ($component->claim_window_days && Carbon::parse($date)->lt(now()->startOfDay()->subDays($component->claim_window_days))) {
            return "Bills for {$component->name} must be from the last {$component->claim_window_days} day(s).";
        }
        $year = $claims->financialYear($date);
        $key = $employee->id.'|'.$component->id.'|'.$year;
        if ((bool) $settings->get('payroll.claims.one_open_per_component', false)
            && (isset($context['claimed'][$key]) || ReimbursementClaim::query()->where('employee_id', $employee->id)->where('salary_component_id', $component->id)->whereIn('status', ['pending', 'approved'])->exists())) {
            return "A claim for {$component->name} is still open; claim again once it is paid, rejected or cancelled.";
        }
        $available = $claims->balance($employee, $component, $year) - ($context['claimed'][$key] ?? 0);
        if (! (bool) $settings->get('payroll.claims.allow_beyond_balance', false) && $amount > $available + 0.001) {
            return 'Up to '.number_format(max(0, $available), 2)." of {$component->name} can be claimed now.";
        }

        return null;
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
                ['Claim date', 'Yes', 'YYYY-MM-DD, today or earlier, within the reimbursement\'s claim window.', now()->subDays(3)->toDateString()],
                ['Claim amount', 'Yes', 'The amount claimed; within the balance (accrued and opening, less claims waiting).', '2500'],
                ['Description', 'Yes', 'What the claim is for.', 'Fuel bills for October'],
                ['Bill number', 'No', 'The bill\'s number.', 'INV-1043'],
                ['Bill date', 'No', 'YYYY-MM-DD.', now()->subDays(5)->toDateString()],
                ['Vendor', 'No', 'Who issued the bill.', 'City Fuels'],
                ['Bill amount', 'No', 'Must add up to the claim (or be at least the claim when claims may not be above their bills).', '2500'],
            ],
            'codes' => SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', true)->where('status', 'active')->orderBy('name')->pluck('name', 'code')->all(),
            'codes_title' => 'Reimbursements paid on claim',
            'notes' => [
                'Each row raises one claim for the employee; the same claim still open is skipped. Someone other than you approves it; the next payroll pays it, non-taxable.',
                'The claim screen\'s rules apply, including the balance (earlier rows of the file count), one open claim per reimbursement and the claim window.',
                'An upload carries no bill images: payroll keeps the bills, so where bills must be attached (Setup → Payroll or the pay component) the row must give the bill amount, and the claim is recorded as "bills held by payroll".',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);

        return [
            [$first, $this->sampleComponent(), now()->subDays(3)->toDateString(), '2500', 'Fuel bills for October', 'INV-1043', now()->subDays(5)->toDateString(), 'City Fuels', '2500'],
            [$second, $this->sampleComponent(), now()->subDays(2)->toDateString(), '1800', 'Fuel bills for October', 'INV-2210', now()->subDays(4)->toDateString(), 'Highway Petro', '1800'],
        ];
    }

    private function sampleComponent(): string
    {
        return (string) (SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', true)->where('status', 'active')->orderBy('name')->value('code') ?? 'FUEL');
    }
}
