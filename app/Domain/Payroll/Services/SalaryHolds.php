<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryHold;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Salary hold and release (HROne parity PY-06). A hold keeps the employee's pay for a month out of that month's bank
 * file; the calculation and payslip are unchanged. Releasing it puts the employee back in the file, or records the
 * reference of a payment made outside it.
 */
final class SalaryHolds
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function hold(Employee $employee, string $payMonth, string $reason, ?User $actor = null): SalaryHold
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $payMonth)) {
            throw new RuntimeException('Give the payroll month as YYYY-MM.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for holding the salary.');
        }
        [$year, $month] = array_map('intval', explode('-', $payMonth));
        $paid = PayrollRun::query()->where('status', 'paid')->whereHas('period', fn ($q) => $q->where('year', $year)->where('month', $month))
            ->whereHas('entries', fn ($q) => $q->where('employee_id', $employee->id))->exists();
        if ($paid) {
            throw new RuntimeException("Salary for {$payMonth} has already been paid.");
        }

        return DB::transaction(function () use ($employee, $payMonth, $reason, $actor) {
            if (SalaryHold::query()->where('employee_id', $employee->id)->where('pay_month', $payMonth)->where('status', 'held')->lockForUpdate()->exists()) {
                throw new RuntimeException("Salary for {$payMonth} is already on hold.");
            }
            $hold = new SalaryHold(['employee_id' => $employee->id, 'pay_month' => $payMonth, 'reason' => $reason, 'status' => 'held', 'held_by' => ($actor ?? auth()->user())?->id]);
            $hold->withAuditReason($reason)->save();

            return $hold;
        });
    }

    public function release(SalaryHold $hold, string $note, ?string $reference = null, ?User $actor = null): SalaryHold
    {
        if ($hold->status !== 'held') {
            throw new RuntimeException('This salary is not on hold.');
        }
        if (trim($note) === '') {
            throw new RuntimeException('Give a note for the release.');
        }
        $actor ??= auth()->user();
        $hold->withAuditReason($note)->update(['status' => 'released', 'released_at' => now(), 'released_by' => $actor?->id, 'release_note' => $note, 'release_reference' => $reference]);
        $this->audit->record(AuditAction::StatusChange, 'payroll', $hold, reason: $note, actor: $actor, metadata: ['released' => true, 'reference' => $reference]);

        return $hold;
    }

    /** @return list<int> employees whose pay for the run's month is held */
    public function heldEmployeeIds(PayrollRun $run): array
    {
        $run->loadMissing('period');
        $month = sprintf('%04d-%02d', $run->period->year, $run->period->month);

        return SalaryHold::query()->where('pay_month', $month)->where('status', 'held')->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
    }
}
