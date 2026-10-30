<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use RuntimeException;

/**
 * Perquisites and income paid outside this payroll (HROne parity PY-07, PY-17, PY-18, PY-25). TaxComputer adds the
 * year's active entries to the salary it projects, counts the tax already deducted on them, and counts the PF and PT
 * deducted outside the run like payroll's own. Entries are cancelled with a reason, never edited or deleted.
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

    /** @return array{perquisites: float, outside_taxable: float, outside_tds: float, outside_pf: float, outside_pt: float} */
    public function totals(int $employeeId, string $financialYear): array
    {
        $entries = TaxIncomeEntry::query()->active()->where('employee_id', $employeeId)->where('financial_year', $financialYear)->get();
        $outside = $entries->where('kind', 'outside_payroll');

        return [
            'perquisites' => round($entries->where('kind', 'perquisite')->sum(fn ($e) => (float) $e->taxable_amount), 2),
            'outside_taxable' => round($outside->sum(fn ($e) => (float) $e->taxable_amount), 2),
            'outside_tds' => round($outside->sum(fn ($e) => (float) $e->tds_deducted), 2),
            'outside_pf' => round($outside->sum(fn ($e) => (float) $e->pf_employee), 2),
            'outside_pt' => round($outside->sum(fn ($e) => (float) $e->professional_tax), 2),
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
