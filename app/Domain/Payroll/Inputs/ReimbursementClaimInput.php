<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/** HROne parity PY-02: approved reimbursement claims are paid, non-taxable, by the next payroll. */
final class ReimbursementClaimInput implements PayrollInputSource
{
    public function key(): string
    {
        return 'reimbursement_claim';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        return ReimbursementClaim::query()->with('component')->where('employee_id', $c->employee->id)->where('status', 'approved')
            ->where('reviewed_at', '<=', $c->period->end_date->copy()->endOfDay())->orderBy('id')->get()
            ->groupBy('salary_component_id')
            ->map(fn ($claims) => [
                'code' => 'CLAIM_'.$claims->first()->component->code, 'name' => $claims->first()->component->name.' claim', 'type' => 'reimbursement',
                'amount' => round((float) $claims->sum(fn (ReimbursementClaim $cl) => $cl->payable()), 2), 'ids' => $claims->pluck('id')->all(),
                'taxable' => false, 'classification' => $claims->first()->component->classification ?? 'reimbursement', 'basis' => ['claims' => $claims->count()],
            ])->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        app(ReimbursementClaims::class)->paid(array_merge(...array_values($idsByEmployee)), $run->loadMissing('period'));
    }
}
