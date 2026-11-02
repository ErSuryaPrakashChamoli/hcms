<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Support\Collection;

/**
 * HROne setup parity (PS-3): read contract for what an employee was last paid, from finalized or paid payroll only.
 * Self-service pages outside Payroll (My compensation) read it here instead of payroll entries.
 *
 * HROne parity MS-10: and what they were paid in a financial year (My salary YTD), from the same finalized or paid
 * payroll only — never an open run, never a recomputation.
 */
interface PaidPayReader
{
    /**
     * Net, gross and wages of the employee's latest finalized or paid payroll entry; wages are the earnings classified
     * as wages for the wage-share guardrail, exclusions the other earnings. HROne parity MS-9 adds the pay month's end
     * date, the state the entry was calculated for (establishment state, else its PT state) and the full-month wages the
     * lines recorded (before proration), for the wage-code verdict.
     *
     * @return array{net: float, gross: float, wages: float, exclusions: float, wage_share: float, period: string, period_end: ?string, state: ?string, wages_full_month: float}|null
     */
    public function latestPaid(Employee $employee): ?array;

    /**
     * The employee's pay in a financial year ("2026-27"), month by month in the financial year's order (the payroll month a
     * run is named for), from finalized or paid payroll entries only. Lines hidden from the payslip are left out, as on the
     * payslip. Rows are grouped by line type and code; arrear lines count in their row's month and are also listed.
     * Statutory figures come from the lines (their recorded basis); ceilings only from a VERIFIED rule version (null
     * otherwise, never a fallback number).
     *
     * @return array{
     *     financial_year: string,
     *     months: array<string, string>,
     *     processed: list<string>,
     *     salary: array{earning: list<array<string, mixed>>, deduction: list<array<string, mixed>>, employer_contribution: list<array<string, mixed>>},
     *     reimbursement: list<array<string, mixed>>,
     *     totals: array<string, array{months: array<string, float>, total: float}>,
     *     arrears: list<array{month: string, code: string, name: string, type: string, amount: float, note: ?string}>,
     *     statutory: array<string, array<string, mixed>>,
     *     overtime: array{months: array<string, array{minutes: int, amount: float}>, minutes: int, amount: float},
     *     bonus: array{months: array<string, float>, total: float, rule: ?array{minimum_rate: float, maximum_rate: ?float, rule: string}},
     *     days: array<string, array{in_period: int, paid: float, lop: float}>
     * }
     */
    public function yearToDate(Employee $employee, string $financialYear): array;

    /**
     * HROne parity PO-22: a company's finalized or paid payroll entries of a payroll month ('Y-m'), with their lines and
     * employees, in the reader's access scope — what the bonus run measures a month's wages for bonus on. Never an open
     * run, never a recomputation.
     *
     * @return Collection<int, PayrollEntry>
     */
    public function finalEntries(int $companyId, string $month): Collection;
}
