<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Services\PaymentModes;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity BP-13: how each finalized salary was paid — by bank transfer (and from which paying account), in
 * cash or by cheque — from the employee's payment mode in force at the end of the pay month. The cash and cheque
 * register is this dataset filtered to cash and cheque amounts.
 */
class SalaryPaymentsDataset extends Dataset
{
    /** @var array<int, list<EmployeePaymentMode>>|null employee id => modes, newest first, read once per run */
    private ?array $modes = null;

    /** @var array<int, list<array<string, mixed>>> entry id => parts */
    private array $parts = [];

    public function key(): string
    {
        return 'salary_payments';
    }

    public function label(): string
    {
        return 'Salary payments by mode (bank transfer, cash, cheque)';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.view'];
    }

    public function fields(): array
    {
        $sum = fn (PayrollEntry $e, string $mode) => round(collect($this->partsOf($e))->where('mode', $mode)->sum('amount'), 2);
        $label = fn (?string $mode) => $mode === null ? null : (EmployeePaymentMode::MODES[$mode] ?? $mode);

        return [
            'period' => ['label' => 'Pay month', 'type' => 'string', 'value' => fn ($e) => $e->run?->period?->key()],
            'period_start' => ['label' => 'Period start', 'type' => 'date', 'value' => fn ($e) => $e->run?->period?->start_date],
            'payment_date' => ['label' => 'Payment date', 'type' => 'date', 'value' => fn ($e) => $e->run?->period?->payment_date],
            ...$this->employeeFields(fn ($e) => $e->employee),
            'salary_mode' => ['label' => 'Salary paid by', 'type' => 'string', 'value' => fn ($e) => $label($this->modeOf($e)?->salary_mode ?? 'online_transfer')],
            'reimbursement_mode' => ['label' => 'Reimbursements paid by', 'type' => 'string', 'value' => fn ($e) => $label($this->modeOf($e)?->reimbursementMode() ?? 'online_transfer')],
            'bank_transfer' => ['label' => 'By bank transfer', 'type' => 'number', 'value' => fn ($e) => $sum($e, 'online_transfer')],
            'cash' => ['label' => 'In cash', 'type' => 'number', 'value' => fn ($e) => $sum($e, 'cash')],
            'cheque' => ['label' => 'By cheque', 'type' => 'number', 'value' => fn ($e) => $sum($e, 'cheque')],
            'cash_cheque_amount' => ['label' => 'In cash or by cheque', 'type' => 'number', 'value' => fn ($e) => round($sum($e, 'cash') + $sum($e, 'cheque'), 2)],
            'net_pay' => ['label' => 'Net pay', 'type' => 'number', 'value' => fn ($e) => (float) $e->net_pay],
            'paying_account' => ['label' => 'Paying account (set on the mode)', 'type' => 'string', 'value' => fn ($e) => $this->modeOf($e)?->salaryAccount?->label()],
            'run_status' => ['label' => 'Run status', 'type' => 'string', 'value' => fn ($e) => $e->run?->status],
            'count' => ['label' => 'Employees (1 per row)', 'type' => 'number', 'value' => fn ($e) => 1],
        ];
    }

    /** HROne parity CA-10: rows come from payroll runs (a view's payroll refresh day applies). */
    public function payrollRunPath(): ?string
    {
        return 'run';
    }

    public function query(): Builder
    {
        return PayrollEntry::query()->with(['run.period', ...self::employeeWith('employee'), 'lines'])
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))->orderByDesc('id');
    }

    /** The payment mode in force at the end of the entry's pay month (null = bank transfer). */
    private function modeOf(PayrollEntry $entry): ?EmployeePaymentMode
    {
        $this->modes ??= EmployeePaymentMode::query()->with('salaryAccount')->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('employee_id')->map(fn ($rows) => $rows->all())->all();
        $end = $entry->run?->period?->end_date?->toDateString();
        foreach ($this->modes[(int) $entry->employee_id] ?? [] as $mode) {
            if ($end !== null && $mode->effective_from->toDateString() <= $end) {
                return $mode;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function partsOf(PayrollEntry $entry): array
    {
        return $this->parts[$entry->id] ??= (float) $entry->net_pay > 0 ? app(PaymentModes::class)->split($entry, $this->modeOf($entry)) : [];
    }
}
