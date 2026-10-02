<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollClosureReader;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 11: answers "is this employee's payroll closed on or after a date?" for Compensation, and locks
 * the runs concerned so the answer cannot change before the caller commits. Finalisation locks the
 * same run row (PayrollRuns::finalize), so a compensation write and a finalisation of an overlapping
 * period are serialised. Integrity check: not limited by the caller's organisation scope.
 */
final class PayrollClosure implements PayrollClosureReader
{
    public function closedOnOrAfter(int $employeeId, CarbonInterface $from): ?CarbonInterface
    {
        // Lock only payroll_runs rows (the row finalisation locks first). Periods are read first without
        // a lock, and the employee's entries are read after the lock without one: a locking read through
        // payroll_entries would hold entry rows that finalisation writes after taking the run row, and
        // the two transactions would deadlock.
        $periods = PayrollPeriod::query()->where('end_date', '>=', $from->toDateString())->pluck('end_date', 'id');
        if ($periods->isEmpty()) {
            return null;
        }
        $closed = PayrollRun::query()->whereIn('payroll_period_id', $periods->keys())->orderBy('id')->lockForUpdate()
            ->get(['id', 'status', 'payroll_period_id'])->whereIn('status', ['finalized', 'paid']);
        if ($closed->isEmpty()) {
            return null;
        }
        $held = PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)
            ->whereIn('payroll_run_id', $closed->pluck('id'))->distinct()->pluck('payroll_run_id')->map(fn ($id) => (int) $id)->all();
        $ends = $closed->filter(fn (PayrollRun $run) => in_array((int) $run->id, $held, true))->map(fn (PayrollRun $run) => Carbon::parse($periods[$run->payroll_period_id]));

        return $ends->isEmpty() ? null : $ends->max();
    }
}
