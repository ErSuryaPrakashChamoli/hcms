<?php

namespace App\Domain\Claims\Services;

use App\Domain\Claims\Models\ReimbursementBill;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\TaxSettings;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollComputation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data import DI-4 (decision, as Indian payroll suites treat "pay by default" reimbursements): bills employees hand in
 * for reimbursements paid every month with salary (type reimbursement, not paid on claim). From the organisation's bills
 * date (Setup → Payroll, payroll.reimbursement.bills_from; blank = never, as before), salary TDS treats what each such
 * reimbursement pays in the financial year — paid so far plus this month for the months left — beyond its bills as
 * taxable salary. No amount or limit is typed: the reimbursement's own payments and the bills decide. Payroll records
 * and cancels bills (payroll.manage, in scope); they are never edited or deleted.
 */
final class ReimbursementBills
{
    public function __construct(private readonly FinancialYear $fy, private readonly AccessScopes $scopes, private readonly TaxSettings $settings) {}

    /** Whether a pay component takes bills here: a reimbursement paid every month (not on claim). */
    public static function takesBills(SalaryComponent $component): bool
    {
        $status = $component->status instanceof \BackedEnum ? $component->status->value : $component->status;

        return $component->type === 'reimbursement' && ! $component->pay_on_claim && $status === 'active';
    }

    public function record(Employee $employee, SalaryComponent $component, Carbon|string $billDate, float $amount, ?string $number, ?string $vendor, ?string $description, User $by): ReimbursementBill
    {
        $this->authorise($by, $employee);
        if (! self::takesBills($component)) {
            throw new RuntimeException("{$component->name} is not a reimbursement paid with salary every month; claims of reimbursements paid on claim carry their own bills.");
        }
        $date = Carbon::parse($billDate)->startOfDay();
        if ($date->gt(now()->endOfDay())) {
            throw new RuntimeException('A bill date cannot be in the future.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('The bill amount must be above zero.');
        }

        return DB::transaction(function () use ($employee, $component, $date, $amount, $number, $vendor, $description, $by) {
            $bill = new ReimbursementBill([
                'employee_id' => $employee->id, 'salary_component_id' => $component->id, 'financial_year' => $this->fy->startYear($date), 'bill_date' => $date->toDateString(),
                'bill_number' => filled($number) ? mb_substr(trim((string) $number), 0, 60) : null, 'vendor' => filled($vendor) ? mb_substr(trim((string) $vendor), 0, 120) : null,
                'amount' => round($amount, 2), 'description' => filled($description) ? mb_substr(trim((string) $description), 0, 255) : null, 'created_by' => $by->id,
            ]);
            $bill->withAuditReason('Reimbursement bill recorded')->save();

            return $bill;
        });
    }

    public function cancel(ReimbursementBill $bill, string $reason, User $by): ReimbursementBill
    {
        $this->authorise($by, Employee::query()->findOrFail($bill->employee_id));
        if ($bill->status !== 'active') {
            throw new RuntimeException('Only an active bill can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for cancelling.');
        }
        $bill->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'cancel_reason' => mb_substr(trim($reason), 0, 500)]);

        return $bill;
    }

    /** Active bills of an employee's reimbursement in a financial year (start year). */
    public function billed(int $employeeId, int $componentId, int $financialYear): float
    {
        return round((float) ReimbursementBill::query()->active()->where('employee_id', $employeeId)->where('salary_component_id', $componentId)->where('financial_year', $financialYear)->sum('amount'), 2);
    }

    /**
     * For salary TDS: what the employee's pay-by-default reimbursements pay in the financial year (finalized or paid
     * payroll before this payment, plus this month's amount for the months left) beyond their bills, from the bills date
     * on; null before it, or when no such reimbursement is paid.
     *
     * @return array{paid: float, billed: float, taxable: float}|null
     */
    public function unbilled(PayrollComputation $c, Carbon $paymentDate, int $monthsRemaining): ?array
    {
        $from = $this->settings->reimbursementBillsFrom();
        if ($from === null || $paymentDate->toDateString() < $from) {
            return null;
        }
        $ids = SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', false)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return null;
        }
        $fyStart = $this->fy->start($paymentDate)->toDateString();
        $paidBefore = PayrollEntryLine::query()->whereIn('salary_component_id', $ids)->where('type', 'reimbursement')
            ->whereHas('entry', fn ($q) => $q->where('employee_id', $c->employee->id)
                ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))
                ->whereHas('run.period', fn ($q) => $q->whereRaw('coalesce(payment_date, end_date) >= ?', [$fyStart])->whereRaw('coalesce(payment_date, end_date) < ?', [$paymentDate->toDateString()])
                    ->whereKeyNot($c->period->getKey())))
            ->get(['salary_component_id', 'amount'])->groupBy('salary_component_id')->map(fn ($lines) => (float) $lines->sum('amount'));
        $thisMonth = collect($c->lines)->where('type', 'reimbursement')->whereIn('salary_component_id', $ids)->groupBy('salary_component_id')->map(fn ($lines) => (float) $lines->sum('amount'));

        $paid = 0.0;
        $billed = 0.0;
        $taxable = 0.0;
        foreach ($paidBefore->keys()->merge($thisMonth->keys())->unique() as $componentId) {
            $year = round((float) ($paidBefore[$componentId] ?? 0) + (float) ($thisMonth[$componentId] ?? 0) * $monthsRemaining, 2);
            if ($year <= 0) {
                continue;
            }
            $bills = $this->billed((int) $c->employee->id, (int) $componentId, $this->fy->startYear($paymentDate));
            $paid += $year;
            $billed += min($bills, $year);
            $taxable += max(0, $year - $bills);
        }

        return $paid > 0 ? ['paid' => round($paid, 2), 'billed' => round($billed, 2), 'taxable' => round($taxable, 2)] : null;
    }

    private function authorise(User $by, Employee $employee): void
    {
        if (! $by->hasPermission('payroll.manage') || ! $this->scopes->allows($by, $employee)) {
            throw new RuntimeException('Only payroll records reimbursement bills, for employees in its scope.');
        }
    }
}
