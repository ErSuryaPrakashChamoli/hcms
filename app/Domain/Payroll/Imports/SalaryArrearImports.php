<?php

namespace App\Domain\Payroll\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Data import DI-4 (Payroll → Salary arrear): arrears of an earlier month paid in an open payroll month, one employee,
 * pay month, arrear month and pay item per row, recorded as payroll adjustments of type arrear — the same record the
 * Adjustments screen creates, under its model guards: never in a closed payroll month, never where the employee's pay
 * for the month is finalised, and pending until someone other than the uploader approves it when the organisation
 * requires approval of adjustments. The arrear month is kept as the adjustment's reference period when PeopleOS has it.
 * A pay item's own tax, PF and ESI treatment applies (an ad hoc arrear is taxable unless the row says no, and is not PF
 * wages). Rows only add arrears; the same arrear already recorded is skipped.
 */
final class SalaryArrearImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'salary_arrears';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'pay_month' => ['Pay in month', true], 'arrear_month' => ['Arrear for month', true], 'component' => ['Pay item code', false],
            'amount' => ['Amount', true], 'name' => ['Description', false], 'taxable' => ['Taxable (yes/no)', false], 'note' => ['Note', false],
        ];
    }

    public static function intents(): bool
    {
        return false; // each row adds one arrear
    }

    public function label(): string
    {
        return 'Salary arrear upload';
    }

    protected function module(): string
    {
        return 'payroll';
    }

    protected function entityType(): string
    {
        return PayrollAdjustment::class;
    }

    protected function aliases(): array
    {
        return ['month' => 'pay_month', 'payroll_month' => 'pay_month', 'reference_month' => 'arrear_month', 'for_month' => 'arrear_month', 'component_code' => 'component',
            'pay_item' => 'component', 'arrear_amount' => 'amount', 'remarks' => 'note'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $pay = $this->month($r['pay_month'] ?? null);
        $arrear = $this->month($r['arrear_month'] ?? null);
        if ($pay === null || $arrear === null) {
            $errors[] = 'Pay in month and Arrear for month must look like 2026-10.';
        } elseif ($arrear >= $pay) {
            $errors[] = 'Arrear for month must be before the month it is paid in.';
        }
        $component = null;
        if (isset($r['component'])) {
            $component = SalaryComponent::query()->where('code', strtoupper(trim($r['component'])))->where('type', 'earning')->where('is_statutory', false)->where('status', 'active')->first();
            if ($component === null) {
                $errors[] = "Pay item '{$r['component']}' is not an active earning (statutory items are never arrears).";
            }
        }
        $amount = $this->money($r['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Amount must be a number above zero.';
        }
        if (isset($r['taxable']) && $this->yesNo($r['taxable']) === null) {
            $errors[] = 'Taxable must be yes or no.';
        } elseif (isset($r['taxable']) && $component !== null) {
            $errors[] = 'Taxable is set by the pay item; leave it blank when a pay item is given.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $employee = Employee::query()->findOrFail($employeeId);
        $uploader = $this->uploader($import);
        if ($uploader === null || ! app(AccessScopes::class)->allows($uploader, $employee)) {
            return ['action' => 'error', 'errors' => ['The employee is outside your organisation scope.'], 'employee_id' => $employeeId];
        }
        try {
            [$company, $period] = $this->periodOf($employee, $pay, false);
        } catch (RuntimeException $e) {
            return ['action' => 'error', 'errors' => [$e->getMessage()], 'employee_id' => $employeeId];
        }
        if ($period !== null && $this->finalised($employeeId, $period)) {
            return ['action' => 'error', 'errors' => ["The employee's pay for {$period->label()} is finalised; pay the arrear in a later month."], 'employee_id' => $employeeId];
        }
        if ($period !== null) {
            $name = $this->name($r, $component, $arrear);
            $onFile = PayrollAdjustment::query()->where('employee_id', $employeeId)->where('payroll_period_id', $period->id)->where('type', 'arrear')
                ->where('salary_component_id', $component?->id)->where('amount', $amount)->where('name', $name)->exists();
            if ($onFile) {
                return ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'This arrear is already recorded for '.$period->label().'.']];
            }
        }
        if (($duplicate = $this->seenBefore(implode('|', [$employeeId, $pay, $arrear, $component?->id, $amount]), $context, $row->row_number, 'arrear')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        [$company, $period] = $this->periodOf($employee, $this->month($r['pay_month']), true);
        if ($this->finalised($employee->id, $period)) {
            throw new RuntimeException("The employee's pay for {$period->label()} is finalised; pay the arrear in a later month.");
        }
        $arrear = $this->month($r['arrear_month']);
        [$year, $month] = array_map('intval', explode('-', $arrear));
        $reference = PayrollPeriod::query()->where('company_id', $company->id)->where('year', $year)->where('month', $month)->value('id');
        $component = isset($r['component']) ? SalaryComponent::query()->where('code', strtoupper(trim($r['component'])))->firstOrFail() : null;

        $adjustment = new PayrollAdjustment([
            'employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'salary_component_id' => $component?->id, 'type' => 'arrear',
            'name' => $this->name($r, $component, $arrear), 'amount' => (float) $this->money($r['amount']),
            'taxable' => $component === null ? ($this->yesNo($r['taxable'] ?? null) ?? true) : (bool) $component->taxable,
            'reference_period_id' => $reference, 'note' => mb_substr(trim(($r['note'] ?? '').' Arrear for '.Carbon::parse($arrear.'-01')->format('F Y').'.'), 0, 255),
            'source_type' => 'employee_import', 'source_id' => $import->getKey(), 'created_by' => $user->id,
        ]);
        $adjustment->save();

        return 'Arrear of '.number_format((float) $adjustment->amount, 2)." for {$period->label()} ({$adjustment->status})";
    }

    /**
     * The employee's company and payroll month (created when $create, as the Adjustments screen lists it); a closed month is refused.
     *
     * @return array{0: Company, 1: ?PayrollPeriod}
     */
    private function periodOf(Employee $employee, string $month, bool $create): array
    {
        [$year, $number] = array_map('intval', explode('-', $month));
        $end = Carbon::create($year, $number, 1)->endOfMonth();
        $companyId = $employee->positions()->effectiveOn($end)->orderByDesc('effective_from')->value('company_id')
            ?? $employee->positions()->effectiveOn(Carbon::create($year, $number, 1))->orderByDesc('effective_from')->value('company_id');
        $company = $companyId ? Company::query()->find($companyId) : null;
        if ($company === null) {
            throw new RuntimeException("The employee is not in any company in {$end->format('F Y')}.");
        }
        $period = $create ? PayrollPeriod::for($company, $year, $number) : PayrollPeriod::query()->where('company_id', $company->id)->where('year', $year)->where('month', $number)->first();
        if ($period?->status === 'closed') {
            throw new RuntimeException("Payroll for {$period->label()} is closed; pay the arrear in an open month.");
        }

        return [$company, $period];
    }

    private function finalised(int $employeeId, PayrollPeriod $period): bool
    {
        return PayrollEntry::query()->where('employee_id', $employeeId)->whereHas('run', fn ($q) => $q->where('payroll_period_id', $period->id)->whereIn('status', ['finalized', 'paid']))->exists();
    }

    private function name(array $r, ?SalaryComponent $component, string $arrear): string
    {
        return mb_substr(trim((string) ($r['name'] ?? (($component?->name ?? 'Salary').' arrear '.Carbon::parse($arrear.'-01')->format('M Y')))), 0, 255);
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Pay in month', 'Yes', 'The open payroll month that pays the arrear, YYYY-MM.', now()->format('Y-m')],
                ['Arrear for month', 'Yes', 'The earlier month the arrear is for, YYYY-MM.', now()->subMonths(2)->format('Y-m')],
                ['Pay item code', 'No', 'An earning pay item (Payroll → Pay components); its tax, PF and ESI treatment applies. Blank = an ad hoc arrear.', 'BASIC'],
                ['Amount', 'Yes', 'The arrear to pay.', '4500'],
                ['Description', 'No', 'Shown on the payslip; blank = "<pay item> arrear <month>".', ''],
                ['Taxable (yes/no)', 'No', 'Only for an ad hoc arrear: blank = yes.', ''],
                ['Note', 'No', 'Why it is paid; kept on the adjustment.', 'Revision from June'],
            ],
            'codes' => SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->where('status', 'active')->orderBy('sort_order')->pluck('name', 'code')->all(),
            'codes_title' => 'Pay items',
            'notes' => [
                'Each row adds one arrear adjustment to the employee\'s payroll month (the same arrear already recorded is skipped). Recalculate the run to pay it.',
                'A closed month, or a month the employee\'s pay is finalised for, is refused. With "adjustments need approval" on, arrears wait for someone other than you to approve them on Payroll → Adjustments.',
                'PF on the arrear follows the pay item: PF-wage pay items count in the month\'s PF wages under the EPF rule in force; ad hoc arrears are not PF wages.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);
        $basic = SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->where('status', 'active')->where('classification', 'basic')->value('code') ?? 'BASIC';

        return [
            [$first, now()->format('Y-m'), now()->subMonths(2)->format('Y-m'), $basic, '4500', '', '', 'Revision effective earlier'],
            [$second, now()->format('Y-m'), now()->subMonth()->format('Y-m'), '', '1200', 'Shift allowance arrear', 'yes', 'Missed last month'],
        ];
    }
}
