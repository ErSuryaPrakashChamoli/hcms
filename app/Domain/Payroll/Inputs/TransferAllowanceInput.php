<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * HROne setup parity 08.6 (Workforce → Transfer allowances): the allowances granted on an approved transfer request are
 * paid once, with the payroll of the month the transfer takes effect (or the first run after it), one line per allowance
 * with its pay component's own tax, PF and ESI treatment. Each line carries the request id; finalizing the run records the
 * run on the request, so a later run never pays it again, and a request another run not yet finalized carries is left to
 * that run. Without such requests nothing is added.
 */
final class TransferAllowanceInput implements PayrollInputSource
{
    public function key(): string
    {
        return 'transfer_allowance';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $requests = TransferRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee->id)->where('status', 'approved')
            ->whereNotNull('allowances')->whereNull('allowances_payroll_run_id')->whereDate('effective_date', '<=', $c->period->end_date->toDateString())
            ->orderBy('id')->sharedLock()->get()
            ->filter(fn (TransferRequest $r) => (array) $r->allowances !== []);
        if ($requests->isEmpty()) {
            return [];
        }
        $elsewhere = app(PendingPayrollInputs::class)->pending($this->key(), (int) $c->employee->id);
        $components = SalaryComponent::query()->whereIn('id', $requests->flatMap(fn (TransferRequest $r) => array_column((array) $r->allowances, 'salary_component_id')))->get()->keyBy('id');
        $lines = [];
        foreach ($requests as $request) {
            if (isset($elsewhere[$request->id])) {
                continue;
            }
            foreach ((array) $request->allowances as $row) {
                $component = $components->get($row['salary_component_id']);
                if ($component === null) {
                    continue;
                }
                $earning = $component->type === 'earning';
                $lines[] = [
                    'code' => $component->code, 'name' => $component->name.' (transfer)', 'type' => $earning ? 'earning' : 'reimbursement', 'amount' => round((float) $row['amount'], 2),
                    'ids' => [$request->id], 'taxable' => (bool) $component->taxable, 'classification' => $component->classification ?? 'allowance',
                    'pf_applicable' => $earning && (bool) $component->pf_applicable, 'esi_applicable' => $earning && (bool) $component->esi_applicable, 'include_in_gross' => $earning && (bool) $component->include_in_gross,
                    'basis' => ['transfer_request' => $request->id, 'effective_date' => $request->effective_date->toDateString(), 'salary_component_id' => $component->id],
                ];
            }
        }

        return $lines;
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        $ids = array_values(array_unique(array_merge(...array_values($idsByEmployee))));
        TransferRequest::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $ids)->whereNull('allowances_payroll_run_id')->get()
            ->each(fn (TransferRequest $r) => $r->withAuditReason('Transfer allowances paid in payroll '.$run->period?->label())->update(['allowances_payroll_run_id' => $run->id, 'allowances_paid_at' => now()]));
    }
}
