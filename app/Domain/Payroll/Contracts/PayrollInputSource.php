<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * A system of record that pays or recovers money through payroll (HROne parity: leave encashments, attendance
 * arrears, loans, claims, variable pay …). Sources are tagged `peopleos.payroll_inputs`. The calculator asks each
 * for the lines due to the employee in the period (after adjustments, before statutory, so taxable lines are taxed);
 * each line names the source records it pays in `ids`. When a run is finalized the source settles exactly the
 * records its lines carried. A source never changes the calculation of anything else.
 */
interface PayrollInputSource
{
    /** Short stable key stored on each line's basis (`source.key`). */
    public function key(): string;

    /**
     * Lines due for the computation's employee and period.
     *
     * @return list<array{code: string, name: string, type: string, amount: float, ids: list<int>, taxable?: bool, classification?: string, basis?: array<string, mixed>, pf_applicable?: bool, esi_applicable?: bool, include_in_gross?: bool}>
     */
    public function inputsFor(PayrollComputation $c): array;

    /**
     * The run was finalized: settle the records these lines paid.
     *
     * @param  array<int, list<int>>  $idsByEmployee  employee id => source record ids carried by the run's lines
     */
    public function settle(PayrollRun $run, array $idsByEmployee): void;
}
