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
 * finalized. Encashment at exit stays with full and final settlement.
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
        $due = LeaveEncashment::query()->with('leaveType')->where('employee_id', $c->employee->id)->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('reviewed_at')->orWhere('reviewed_at', '<=', $c->period->end_date->copy()->endOfDay()))->orderBy('id')->get();
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
            'code' => 'LEAVE_ENC', 'name' => ($e->leaveType?->name ?? 'Leave').' encashment ('.rtrim(rtrim(number_format((float) $e->days, 2), '0'), '.').' days)',
            'type' => 'earning', 'amount' => round((float) $e->days * $perDay, 2), 'ids' => [$e->id], 'taxable' => true, 'classification' => 'leave_encashment',
            'basis' => ['days' => (float) $e->days, 'per_day' => $perDay, 'pay_basis' => $basis, 'encashment_id' => $e->id],
        ])->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        foreach ($idsByEmployee as $employeeId => $ids) {
            LeaveEncashment::query()->where('employee_id', $employeeId)->whereIn('id', $ids)->where('status', 'approved')->get()
                ->each(fn (LeaveEncashment $e) => $e->withAuditReason('Paid in payroll '.$run->period?->label())->update(['status' => 'paid']));
        }
    }
}
