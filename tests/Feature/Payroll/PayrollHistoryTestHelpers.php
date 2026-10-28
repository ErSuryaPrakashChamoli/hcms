<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;

/**
 * Finalized payroll can no longer be reopened (decision C12-D1 open), so no production path changes a finalized month.
 * The EPF revision and TDS ledger logic must still handle a month whose payroll changed after its return or ledger was
 * built: a month reopened before that rule, or one settled later under C12-D1. This puts a run back to draft at the
 * data level, exactly as the removed reopen did (payslips withdrawn, attendance unlocked, period reopened), so those
 * tests can re-finalize it. Tests only; the model guards refuse the same change through Eloquent.
 */
function returnFinalizedRunToDraftForTest(PayrollRun $run): void
{
    $run->loadMissing('period');
    $entries = PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->where('payroll_run_id', $run->id);

    AttendanceRecord::query()->whereIn('employee_id', (clone $entries)->pluck('employee_id'))
        ->whereBetween('date', [$run->period->start_date->toDateString(), $run->period->end_date->toDateString()])
        ->update(['is_locked' => false, 'finalized_at' => null]);
    Payslip::query()->withoutGlobalScope(AccessScope::class)->whereIn('payroll_entry_id', (clone $entries)->pluck('id'))->delete();
    PayrollPeriod::query()->whereKey($run->payroll_period_id)->update(['status' => 'open']);
    PayrollRun::query()->whereKey($run->id)->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'finalized_by' => null, 'finalized_at' => null]);
}
