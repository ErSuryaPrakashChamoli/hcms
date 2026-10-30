<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Claims\Models\ReimbursementLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * HROne parity AN-26 (HROne reports "Reimbursement audit", "Reimbursement monthly ledger", "Reimbursement summary"): the
 * entitlement ledger of pay-on-claim reimbursements — openings and monthly accruals credit, paid claims and lapses debit,
 * the balance is the sum. For payroll (payroll.manage, as the claims screen) in the reader's access scope; amounts are
 * sensitive fields of that right.
 */
class ReimbursementLedgerDataset extends Dataset
{
    public function key(): string
    {
        return 'reimbursement_ledger';
    }

    public function label(): string
    {
        return 'Reimbursement ledger (credits, claims paid, balance)';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.manage'];
    }

    public function sensitivePermission(): ?string
    {
        return 'payroll.manage';
    }

    public function fields(): array
    {
        $money = fn (string $label, \Closure $value) => ['label' => $label, 'type' => 'number', 'sensitive' => true, 'value' => $value];

        return [
            'financial_year' => ['label' => 'Financial year', 'type' => 'string', 'value' => fn (ReimbursementLedgerEntry $e) => self::yearLabel((int) $e->financial_year)],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn (ReimbursementLedgerEntry $e) => $e->month],
            // Openings carry no month: they date from the start of their financial year.
            'month_start' => ['label' => 'Month (date)', 'type' => 'date', 'value' => fn (ReimbursementLedgerEntry $e) => $e->month ? Carbon::parse($e->month.'-01') : self::yearStart((int) $e->financial_year)],
            ...$this->employeeFields(fn (ReimbursementLedgerEntry $e) => $e->employee),
            'component' => ['label' => 'Reimbursement', 'type' => 'string', 'value' => fn (ReimbursementLedgerEntry $e) => $e->component?->name],
            'component_code' => ['label' => 'Reimbursement code', 'type' => 'string', 'value' => fn (ReimbursementLedgerEntry $e) => $e->component?->code],
            // One key per employee and reimbursement, so a report can group the ledger per person and component.
            'employee_component' => ['label' => 'Employee and reimbursement', 'type' => 'string', 'value' => fn (ReimbursementLedgerEntry $e) => implode(' · ', array_filter([$e->employee?->employee_code, $e->employee?->person?->full_name])).' — '.($e->component?->name ?? '?')],
            'kind' => ['label' => 'Entry (code)', 'type' => 'string', 'value' => fn (ReimbursementLedgerEntry $e) => $e->kind],
            'kind_label' => ['label' => 'Entry', 'type' => 'string', 'value' => fn (ReimbursementLedgerEntry $e) => ReimbursementLedgerEntry::KINDS[$e->kind] ?? $e->kind],
            'credit' => $money('Credit', fn (ReimbursementLedgerEntry $e) => (float) $e->amount > 0 ? round((float) $e->amount, 2) : 0.0),
            'debit' => $money('Debit', fn (ReimbursementLedgerEntry $e) => (float) $e->amount < 0 ? round(-(float) $e->amount, 2) : 0.0),
            'amount' => $money('Amount (+ credit, − debit)', fn (ReimbursementLedgerEntry $e) => round((float) $e->amount, 2)),
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => fn (ReimbursementLedgerEntry $e) => $e->note],
            'count' => ['label' => 'Entries (1 per row)', 'type' => 'number', 'value' => fn (ReimbursementLedgerEntry $e) => 1],
        ];
    }

    public function query(): Builder
    {
        return ReimbursementLedgerEntry::query()->with([...self::employeeWith('employee'), 'component'])
            ->orderByDesc('financial_year')->orderBy('employee_id')->orderBy('id');
    }

    /** 2026 → "2026-27" (financial years are stored by the calendar year they start in). */
    public static function yearLabel(int $startYear): string
    {
        return $startYear.'-'.substr((string) ($startYear + 1), -2);
    }

    public static function yearStart(int $startYear): Carbon
    {
        return Carbon::create($startYear, (int) config('peopleos.compliance.financial_year_start_month', 4), 1)->startOfDay();
    }
}
