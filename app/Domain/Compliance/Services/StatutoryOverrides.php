<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\StatutoryOverride;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollComputation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Audited statutory overrides (HROne parity PY-14). The engine computes each line as usual; an active override for
 * the employee and period then sets the line's amount (zero removes it), keeps the computed amount and the reason on
 * the line's basis, and raises a non-blocking statutory_override exception so reviewers see it before approving.
 * Overrides are set only while the employee's pay for the period is not finalised, and never on one's own pay.
 */
final class StatutoryOverrides
{
    public function set(Employee $employee, PayrollPeriod $period, string $code, float $amount, string $reason, User $by): StatutoryOverride
    {
        if (! $by->can('payroll.override')) {
            throw new RuntimeException('You cannot override statutory amounts.');
        }
        if (! array_key_exists($code, StatutoryOverride::CODES)) {
            throw new RuntimeException('Choose a statutory line from the list.');
        }
        if ($amount < 0) {
            throw new RuntimeException('The amount cannot be negative.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the override.');
        }
        if ((int) $employee->user_id === (int) $by->id) {
            throw new RuntimeException('You cannot override your own statutory amounts.');
        }
        if (! $employee->positions()->where('company_id', $period->company_id)->effectiveOn($period->end_date)->exists()) {
            throw new RuntimeException('The employee is not in that company in '.$period->label().'.');
        }
        $this->assertOpen($employee, $period);

        return DB::transaction(function () use ($employee, $period, $code, $amount, $reason, $by) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            StatutoryOverride::query()->active()->where('employee_id', $employee->id)->where('payroll_period_id', $period->id)->where('code', $code)->get()
                ->each(fn (StatutoryOverride $old) => $old->withAuditReason('Replaced by a new override')->update(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'cancel_reason' => 'Replaced']));

            $override = new StatutoryOverride(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'code' => $code, 'amount' => round($amount, 2), 'reason' => trim($reason), 'created_by' => $by->id]);
            $override->withAuditReason($reason)->save();

            return $override;
        });
    }

    public function cancel(StatutoryOverride $override, string $reason, User $by): StatutoryOverride
    {
        if (! $by->can('payroll.override')) {
            throw new RuntimeException('You cannot override statutory amounts.');
        }
        if ($override->status !== 'active') {
            throw new RuntimeException('Only an active override can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for cancelling.');
        }
        $this->assertOpen($override->employee, $override->period);
        $override->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);

        return $override;
    }

    /**
     * Apply the period's active overrides to the computation.
     *
     * @param  list<string>|null  $only  codes to apply (null = all)
     * @param  list<string>  $except
     */
    public function apply(PayrollComputation $c, ?array $only = null, array $except = []): void
    {
        $overrides = StatutoryOverride::query()->active()->where('employee_id', $c->employee->id)->where('payroll_period_id', $c->period->getKey())->get()
            ->filter(fn (StatutoryOverride $o) => ($only === null || in_array($o->code, $only, true)) && ! in_array($o->code, $except, true));

        foreach ($overrides as $override) {
            $index = collect($c->lines)->search(fn (array $line) => $line['code'] === $override->code);
            $computed = $index === false ? 0.0 : (float) $c->lines[$index]['amount'];
            $amount = (float) $override->amount;
            $record = ['override_id' => $override->id, 'computed' => $computed, 'amount' => $amount, 'reason' => $override->reason];

            if ($amount <= 0) {
                if ($index !== false) {
                    unset($c->lines[$index]);
                    $c->lines = array_values($c->lines);
                }
            } elseif ($index !== false) {
                $c->lines[$index]['amount'] = round($amount, 2);
                $c->lines[$index]['basis']['override'] = $record;
            } else {
                [$name, $type, $classification, $sort] = StatutoryOverride::CODES[$override->code];
                $c->addLine($override->code, $name, $type, $amount, ['classification' => $classification, 'sort_order' => $sort, 'basis' => ['override' => $record]]);
            }

            $c->inputs['statutory_overrides'][$override->code] = $record;
            $c->exception('statutory_override', "{$override->codeLabel()} set by hand to ".number_format($amount, 2).' (calculated '.number_format($computed, 2)."): {$override->reason}");
        }
    }

    private function assertOpen(Employee $employee, PayrollPeriod $period): void
    {
        $closed = PayrollEntry::query()->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->where('payroll_period_id', $period->id)->whereIn('status', ['finalized', 'paid']))->exists();
        if ($closed) {
            throw new RuntimeException("The employee's pay for {$period->label()} is finalised; overrides can no longer change it.");
        }
    }
}
