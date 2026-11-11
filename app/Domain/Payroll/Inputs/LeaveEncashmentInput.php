<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;
use App\Domain\Platform\Services\SettingsRepository;

/**
 * HROne parity PY-11: approved leave encashments are paid in the next payroll as a taxable earning, at a day's pay
 * on the configured basis (payroll.encashment_basis, payroll.encashment_divisor), and become paid when the run is
 * finalized. Encashment at exit stays with full and final settlement. HROne parity PO-8: an encashment may name the pay
 * month that pays it (`pay_month`, set by an HR batch or at approval); without one it is paid exactly as before.
 */
final class LeaveEncashmentInput implements PayrollInputSource
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function key(): string
    {
        return 'leave_encashment';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $month = $c->period->key();
        // HROne parity PO-8: an encashment with a pay month is paid in that month's run (or the first run after it, when
        // that month was missed); without one, as before, by the first run whose period ends on or after its approval.
        $due = LeaveEncashment::query()->with('leaveType')->where('employee_id', $c->employee->id)->where('status', 'approved')
            ->where(fn ($q) => $q->where(fn ($next) => $next->whereNull('pay_month')->where(fn ($r) => $r->whereNull('reviewed_at')->orWhere('reviewed_at', '<=', $c->period->end_date->copy()->endOfDay())))
                ->orWhere(fn ($set) => $set->whereNotNull('pay_month')->where('pay_month', '<=', $month)))
            ->orderBy('id')->get();
        if ($due->isEmpty()) {
            return [];
        }
        $basis = (string) $this->settings->get('payroll.encashment_basis', 'basic');
        $perDay = PayrollInputs::dailyRate($c, $basis, (int) $this->settings->get('payroll.encashment_divisor', 30));
        if ($perDay <= 0) {
            $c->exception('encashment_no_rate', 'Approved leave encashment waits: there is no '.$basis.' pay to value a day.');

            return [];
        }

        return $due->map(fn (LeaveEncashment $e) => [
            'code' => 'LEAVE_ENC', 'name' => ($e->leaveType?->name ?? 'Leave').' encashment ('.rtrim(rtrim(number_format((float) $e->days, 2), '0'), '.').' days)'
                .($e->pay_month !== null && $e->pay_month < $month ? ' for '.$e->pay_month : ''),
            'type' => 'earning', 'amount' => round((float) $e->days * $perDay, 2), 'ids' => [$e->id], 'taxable' => true, 'classification' => 'leave_encashment',
            'basis' => ['days' => (float) $e->days, 'per_day' => $perDay, 'pay_basis' => $basis, 'encashment_id' => $e->id] + ($e->pay_month !== null ? ['pay_month' => $e->pay_month] : []),
        ])->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        foreach ($idsByEmployee as $employeeId => $ids) {
            LeaveEncashment::query()->where('employee_id', $employeeId)->whereIn('id', $ids)->where('status', 'approved')->get()
                ->each(fn (LeaveEncashment $e) => $e->withAuditReason('Paid in payroll '.$run->period?->label())->update(['status' => 'paid']));
        }
    }

    public function unsettle(PayrollRun $run, array $idsByEmployee): void
    {
        foreach ($idsByEmployee as $employeeId => $ids) {
            LeaveEncashment::query()->where('employee_id', $employeeId)->whereIn('id', $ids)->where('status', 'paid')->get()
                ->each(fn (LeaveEncashment $e) => $e->withAuditReason('Payroll '.$run->period?->label().' reopened')->update(['status' => 'approved']));
        }
    }
}
