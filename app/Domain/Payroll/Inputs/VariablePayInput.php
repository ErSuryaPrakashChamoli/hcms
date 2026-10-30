<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Payroll\Services\PayrollComputation;

/** HROne parity PY-05: approved variable pay due by the period's month is paid with its component's treatment. */
final class VariablePayInput implements PayrollInputSource
{
    public function key(): string
    {
        return 'variable_pay';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $month = sprintf('%04d-%02d', $c->period->year, $c->period->month);

        return VariablePayRequest::query()->with('component')->where('employee_id', $c->employee->id)->where('status', 'approved')->where('pay_month', '<=', $month)->orderBy('id')->get()
            ->filter(fn (VariablePayRequest $r) => $r->component !== null)
            ->map(fn (VariablePayRequest $r) => [
                'code' => $r->component->code, 'name' => $r->component->name.($r->pay_month !== $month ? " ({$r->pay_month})" : ''), 'type' => 'earning', 'amount' => (float) $r->amount,
                'ids' => [$r->id], 'taxable' => (bool) $r->component->taxable, 'classification' => $r->component->classification ?? 'variable',
                'pf_applicable' => (bool) $r->component->pf_applicable, 'esi_applicable' => (bool) $r->component->esi_applicable, 'include_in_gross' => (bool) $r->component->include_in_gross,
                'basis' => ['variable_pay_request' => $r->id, 'pay_month' => $r->pay_month, 'reason' => $r->reason],
            ])->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        foreach ($idsByEmployee as $ids) {
            VariablePayRequest::query()->whereIn('id', $ids)->where('status', 'approved')->get()
                ->each(fn (VariablePayRequest $r) => $r->withAuditReason('Paid in payroll '.$run->period?->label())->update(['status' => 'paid', 'payroll_run_id' => $run->id]));
        }
    }
}
