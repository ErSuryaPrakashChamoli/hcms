<?php

namespace App\Domain\Payroll\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Payroll\Services\VariablePay;
use App\Domain\Payroll\Services\VariablePayPlans;

/**
 * Data import DI-4 (Payroll → Variable pay): variable pay for a payroll month, one employee, pay item and month per row,
 * raised through VariablePay::request like the screen's "For an employee" (or, when the row names one of the employee's
 * variable pay plans, as that plan month's payout through VariablePayPlans::enterPayout). Every request waits for a
 * decision by someone other than the uploader (Approval Center or the screen) and approved ones are paid by that
 * month's payroll (VariablePayInput) with the pay item's own tax and statutory treatment. Rows only add requests; the
 * same request still open is skipped.
 */
final class VariablePayImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'variable_pay';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'component' => ['Pay item code', true], 'amount' => ['Amount', true], 'pay_month' => ['Pay month', true],
            'reason' => ['Reason', true], 'plan' => ['Variable pay plan', false],
        ];
    }

    public static function intents(): bool
    {
        return false; // each row raises one more request
    }

    public function label(): string
    {
        return 'Variable pay upload';
    }

    protected function module(): string
    {
        return 'payroll';
    }

    protected function entityType(): string
    {
        return VariablePayRequest::class;
    }

    protected function aliases(): array
    {
        return ['component_code' => 'component', 'pay_item' => 'component', 'code' => 'component', 'month' => 'pay_month', 'payroll_month' => 'pay_month', 'note' => 'reason', 'plan_name' => 'plan'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $component = $this->component($r['component'] ?? null);
        if ($component === null) {
            $errors[] = "Pay item '".($r['component'] ?? '')."' is not an active earning that can be paid as variable pay.";
        } elseif ($component->pay_nature !== 'variable' && SalaryComponent::query()->where('pay_nature', 'variable')->where('status', 'active')->exists()) {
            $errors[] = "{$component->name} is a fixed component; choose a variable one.";
        }
        $amount = $this->money($r['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Amount must be a number above zero.';
        }
        $month = $this->month($r['pay_month'] ?? null);
        if ($month === null || $month < now()->subMonth()->format('Y-m')) {
            $errors[] = 'Pay month must be last month or later, as YYYY-MM.';
        }
        if (trim((string) ($r['reason'] ?? '')) === '') {
            $errors[] = 'Give a reason.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $employee = Employee::query()->findOrFail($employeeId);
        $uploader = $this->uploader($import);
        if ($uploader === null || ! app(AccessScopes::class)->allows($uploader, $employee)) {
            return ['action' => 'error', 'errors' => ['The employee is outside your organisation scope.'], 'employee_id' => $employeeId];
        }
        if (isset($r['plan'])) {
            $plan = $this->plan($employeeId, $r['plan']);
            $problem = match (true) {
                $plan === null => "The employee has no active variable pay plan named '{$r['plan']}'.",
                (int) $plan->salary_component_id !== (int) $component->id => "Plan {$plan->name} pays another pay item.",
                ! array_key_exists($month, $plan->targets()) => "{$month} is not a month of plan {$plan->name}.",
                $plan->requests()->where('plan_month', $month)->whereIn('status', ['pending', 'approved', 'paid'])->exists() => "The payout of plan {$plan->name} for {$month} has already been entered.",
                ! app(VariablePayPlans::class)->mayEnter($uploader, $employee) => 'Only the employee\'s manager or payroll enters a plan payout, never for themselves.',
                default => null,
            };
            if ($problem !== null) {
                return ['action' => 'error', 'errors' => [$problem], 'employee_id' => $employeeId];
            }
        }

        $open = VariablePayRequest::query()->where('employee_id', $employeeId)->where('salary_component_id', $component->id)->where('pay_month', $month)
            ->where('amount', $amount)->whereIn('status', ['pending', 'approved'])->exists();
        if ($open) {
            return ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'The same variable pay is already waiting or approved for this month.']];
        }
        if (($duplicate = $this->seenBefore("{$employeeId}|{$component->id}|{$month}", $context, $row->row_number, 'employee, pay item and month')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        $component = $this->component($r['component']);
        $month = $this->month($r['pay_month']);
        $amount = (float) $this->money($r['amount']);
        $request = isset($r['plan'])
            ? app(VariablePayPlans::class)->enterPayout($this->plan($employee->id, $r['plan']), $month, $amount, trim($r['reason']), $user)
            : app(VariablePay::class)->request($employee, $component, $amount, $month, trim($r['reason']), $user);

        return "{$component->name} of ".number_format((float) $request->amount, 2)." for {$month} sent for approval";
    }

    private function component(?string $code): ?SalaryComponent
    {
        $code = trim((string) $code);

        return $code === '' ? null : SalaryComponent::query()->where('code', strtoupper($code))->where('type', 'earning')->where('is_statutory', false)->where('status', 'active')->first();
    }

    private function plan(int $employeeId, string $name): ?VariablePayPlan
    {
        return VariablePayPlan::query()->where('employee_id', $employeeId)->where('status', 'active')->get()
            ->first(fn (VariablePayPlan $p) => mb_strtolower(trim((string) $p->name)) === mb_strtolower(trim($name)));
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);
        $component = $this->sampleComponent();

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Pay item code', 'Yes', 'Code of an earning pay item (a variable one once any is marked variable), Payroll → Pay components.', $component],
                ['Amount', 'Yes', 'The amount to pay.', '15000'],
                ['Pay month', 'Yes', 'Payroll month to pay it in, YYYY-MM: last month or later (a month already run is paid in the next run).', now()->format('Y-m')],
                ['Reason', 'Yes', 'What it is for; shown to the approver.', 'Q2 sales incentive'],
                ['Variable pay plan', 'No', 'The name of the employee\'s variable pay plan when this is that plan month\'s payout.', ''],
            ],
            'codes' => SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->where('status', 'active')->orderBy('sort_order')->pluck('name', 'code')->all(),
            'codes_title' => 'Pay items',
            'notes' => [
                'Each row raises one variable pay request; someone other than you approves it (Approval Center or Payroll → Variable pay). The same request still open is skipped.',
                'Approved variable pay is paid by the payroll of its month with the pay item\'s own tax, PF and ESI treatment.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);
        $component = $this->sampleComponent();

        return [
            [$first, $component, '15000', now()->format('Y-m'), 'Q2 sales incentive', ''],
            [$second, $component, '8000', now()->format('Y-m'), 'Spot award', ''],
        ];
    }

    private function sampleComponent(): string
    {
        $query = SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->where('status', 'active');

        return (string) ((clone $query)->where('pay_nature', 'variable')->orderBy('sort_order')->value('code') ?? $query->orderBy('sort_order')->value('code') ?? 'INCENTIVE');
    }
}
