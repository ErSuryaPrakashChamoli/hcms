<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Perquisites and income paid outside this payroll (HROne parity PY-07, PY-17, PY-18, PY-25). TaxComputer adds the
 * year's active entries to the salary it projects, counts the tax already deducted on them, and counts the PF and PT
 * deducted outside the run like payroll's own. Entries are cancelled with a reason, never edited or deleted.
 *
 * Data import DI-4: a month paid before PeopleOS went live (previous_salary: counted like income paid outside payroll,
 * with its basic + DA and HRA for the HRA exemption; a month PeopleOS itself paid is refused) and tax deducted or
 * collected elsewhere that the employee declares on Form 12BAA (tax_credit: TaxComputer counts it as tax already
 * deducted unless the TDS rule in force turns the credit off).
 */
final class TaxIncomeEntries
{
    public function __construct(private readonly FinancialYear $fy) {}

    public function recordPerquisite(Employee $employee, string $financialYear, string $type, string $description, float $value, float $recovered, User $by, ?string $reference = null): TaxIncomeEntry
    {
        $this->authorise($by);
        if (! array_key_exists($type, (array) config('peopleos.compliance.perquisite_types', []))) {
            throw new RuntimeException('Choose a perquisite type from the list.');
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $financialYear)) {
            throw new RuntimeException('Give the financial year as 2026-27.');
        }
        if ($value <= 0 || $recovered < 0 || $recovered > $value) {
            throw new RuntimeException('The value must be above zero and the amount recovered from the employee between zero and the value.');
        }

        return $this->create($employee, [
            'financial_year' => $financialYear, 'kind' => 'perquisite', 'perquisite_type' => $type, 'description' => $description,
            'amount' => round($value, 2), 'recovered' => round($recovered, 2), 'taxable_amount' => round($value - $recovered, 2), 'reference' => $reference,
        ], $by, 'Perquisite recorded for TDS');
    }

    public function recordOutside(Employee $employee, string $paidOn, string $description, float $amount, float $taxable, float $tds, float $pf, float $pt, User $by, ?string $reference = null): TaxIncomeEntry
    {
        $this->authorise($by);
        if ($amount <= 0 || $taxable < 0 || $taxable > $amount) {
            throw new RuntimeException('The amount must be above zero and its taxable part between zero and the amount.');
        }
        if ($tds < 0 || $pf < 0 || $pt < 0) {
            throw new RuntimeException('Tax, PF and PT deducted cannot be negative.');
        }

        return $this->create($employee, [
            'financial_year' => $this->fy->label($paidOn), 'kind' => 'outside_payroll', 'description' => $description, 'paid_on' => $paidOn,
            'amount' => round($amount, 2), 'taxable_amount' => round($taxable, 2), 'tds_deducted' => round($tds, 2), 'pf_employee' => round($pf, 2),
            'professional_tax' => round($pt, 2), 'reference' => $reference,
        ], $by, 'Income paid outside payroll recorded for TDS');
    }

    /**
     * Data import DI-4 (Previous month salary): one month paid before PeopleOS went live this financial year, with what
     * was deducted from it. A month already recorded is replaced (the earlier entry is cancelled); a month PeopleOS paid
     * itself (finalized or paid payroll) is refused, so nothing counts twice. The payment date decides the tax year.
     */
    public function recordPreviousSalary(Employee $employee, string $payMonth, ?string $paidOn, float $gross, float $taxable, float $basicDa, float $hra, float $tds, float $pf, float $pt, User $by, ?string $reference = null): TaxIncomeEntry
    {
        $this->authorise($by);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $payMonth)) {
            throw new RuntimeException('Give the month as 2026-06.');
        }
        $month = Carbon::parse($payMonth.'-01')->startOfMonth();
        if ($month->gt(now()->startOfMonth())) {
            throw new RuntimeException('A previous month salary is for a month that has started.');
        }
        $paidOn = $paidOn ?? $month->copy()->endOfMonth()->toDateString();
        if ($gross <= 0 || $taxable < 0 || $taxable > $gross) {
            throw new RuntimeException('The gross must be above zero and its taxable part between zero and the gross.');
        }
        if ($basicDa < 0 || $hra < 0 || $basicDa + $hra > $gross) {
            throw new RuntimeException('Basic + DA and HRA cannot be negative or add up to more than the gross.');
        }
        if ($tds < 0 || $pf < 0 || $pt < 0) {
            throw new RuntimeException('Tax, PF and PT deducted cannot be negative.');
        }
        if ($this->paidByPayroll($employee, $month)) {
            throw new RuntimeException('PeopleOS has already paid '.$month->format('F Y').' to this employee; only months before going live are recorded.');
        }

        return DB::transaction(function () use ($employee, $month, $paidOn, $gross, $taxable, $basicDa, $hra, $tds, $pf, $pt, $by, $reference) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->value('id');
            TaxIncomeEntry::query()->active()->where('employee_id', $employee->id)->where('kind', 'previous_salary')->whereDate('pay_month', $month->toDateString())->get()
                ->each(fn (TaxIncomeEntry $old) => $this->cancel($old, 'Replaced by a new previous month salary', $by));

            return $this->create($employee, [
                'financial_year' => $this->fy->label($paidOn), 'kind' => 'previous_salary', 'description' => 'Salary for '.$month->format('F Y').' paid before PeopleOS',
                'paid_on' => $paidOn, 'pay_month' => $month->toDateString(), 'amount' => round($gross, 2), 'taxable_amount' => round($taxable, 2),
                'basic_da' => round($basicDa, 2), 'hra' => round($hra, 2), 'tds_deducted' => round($tds, 2), 'pf_employee' => round($pf, 2),
                'professional_tax' => round($pt, 2), 'reference' => $reference,
            ], $by, 'Previous month salary recorded for TDS');
        });
    }

    /**
     * Data import DI-4 (12BAA declaration): tax deducted (TDS) or collected (TCS) at source elsewhere in the year that the
     * employee declares, so salary TDS can take it into account. The income itself is declared as Other income on the
     * investment declaration; this entry carries the tax only. The date it was deducted or collected decides the year.
     */
    public function recordTaxCredit(Employee $employee, string $creditType, string $on, string $section, float $amountPaid, float $tax, ?string $deductorName, ?string $deductorTan, User $by, ?string $reference = null): TaxIncomeEntry
    {
        $this->authorise($by);
        if (! array_key_exists($creditType, TaxIncomeEntry::CREDIT_TYPES)) {
            throw new RuntimeException('Say whether the tax was deducted (TDS) or collected (TCS).');
        }
        if (trim($section) === '' || mb_strlen(trim($section)) > 24) {
            throw new RuntimeException('Give the provision the tax was '.($creditType === 'tcs' ? 'collected' : 'deducted').' under (up to 24 characters).');
        }
        if ($tax <= 0 || $amountPaid < 0) {
            throw new RuntimeException('The tax must be above zero and the amount paid or received zero or more.');
        }
        $tan = $deductorTan !== null && trim($deductorTan) !== '' ? strtoupper(trim($deductorTan)) : null;
        if ($tan !== null && ! preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/', $tan)) {
            throw new RuntimeException('A TAN looks like ABCD12345E.');
        }
        $name = $deductorName !== null && trim($deductorName) !== '' ? mb_substr(trim($deductorName), 0, 150) : null;
        $label = $creditType === 'tcs' ? 'TCS' : 'TDS';

        return $this->create($employee, [
            'financial_year' => $this->fy->label($on), 'kind' => 'tax_credit', 'credit_type' => $creditType, 'section' => trim($section),
            'deductor_name' => $name, 'deductor_tan' => $tan, 'paid_on' => $on,
            'description' => mb_substr("{$label} under ".trim($section).($name !== null ? " by {$name}" : ''), 0, 255),
            'amount' => round($amountPaid, 2), 'taxable_amount' => 0, 'tds_deducted' => round($tax, 2), 'reference' => $reference,
        ], $by, 'Tax deducted or collected elsewhere declared (12BAA)');
    }

    /** Whether finalized or paid payroll in PeopleOS already covers this month for the employee. */
    public function paidByPayroll(Employee $employee, Carbon $month): bool
    {
        return PayrollEntry::query()->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))
            ->whereHas('run.period', fn ($q) => $q->where('year', $month->year)->where('month', $month->month))
            ->exists();
    }

    public function cancel(TaxIncomeEntry $entry, string $reason, User $by): TaxIncomeEntry
    {
        $this->authorise($by);
        if ($entry->status !== 'active') {
            throw new RuntimeException('Only an active entry can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for cancelling.');
        }
        $entry->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);

        return $entry;
    }

    /**
     * DI-4: a previous month salary counts like income paid outside payroll (taxable, TDS, PF, PT), and adds its basic + DA
     * and HRA; a 12BAA tax credit is kept apart (tax_credit) because a TDS rule version may turn the credit off.
     *
     * @return array{perquisites: float, outside_taxable: float, outside_tds: float, outside_pf: float, outside_pt: float, outside_basic: float, outside_hra: float, previous_months: int, tax_credit: float}
     */
    public function totals(int $employeeId, string $financialYear): array
    {
        $entries = TaxIncomeEntry::query()->active()->where('employee_id', $employeeId)->where('financial_year', $financialYear)->get();
        $outside = $entries->whereIn('kind', ['outside_payroll', 'previous_salary']);
        $previous = $entries->where('kind', 'previous_salary');

        return [
            'perquisites' => round($entries->where('kind', 'perquisite')->sum(fn ($e) => (float) $e->taxable_amount), 2),
            'outside_taxable' => round($outside->sum(fn ($e) => (float) $e->taxable_amount), 2),
            'outside_tds' => round($outside->sum(fn ($e) => (float) $e->tds_deducted), 2),
            'outside_pf' => round($outside->sum(fn ($e) => (float) $e->pf_employee), 2),
            'outside_pt' => round($outside->sum(fn ($e) => (float) $e->professional_tax), 2),
            'outside_basic' => round($previous->sum(fn ($e) => (float) $e->basic_da), 2),
            'outside_hra' => round($previous->sum(fn ($e) => (float) $e->hra), 2),
            'previous_months' => $previous->count(),
            'tax_credit' => round($entries->where('kind', 'tax_credit')->sum(fn ($e) => (float) $e->tds_deducted), 2),
        ];
    }

    /** @param  array<string, mixed>  $values */
    private function create(Employee $employee, array $values, User $by, string $reason): TaxIncomeEntry
    {
        if (trim((string) $values['description']) === '') {
            throw new RuntimeException('Describe the entry.');
        }
        $entry = new TaxIncomeEntry($values + ['employee_id' => $employee->id, 'created_by' => $by->id, 'status' => 'active']);
        $entry->withAuditReason($reason)->save();

        return $entry;
    }

    private function authorise(User $by): void
    {
        if (! $by->can('payroll.manage')) {
            throw new RuntimeException('Only payroll can record income for TDS.');
        }
    }
}
