# Payroll — architecture

Phase 4 (27 September 2026). Describes what is implemented; *deferred* items are not built. Statutory rates remain **illustrative** (see §8).

## 1. Pipeline (engine `payroll-2.1`; 2.1 = 2.0 + Phase 5 establishment-resolved statutory context, see compliance architecture)

```
PayrollPeriod (company, month; open → closed)
  └─ PayrollRun  draft → calculated → validated → approved → finalized → paid   (reopen: finalized → draft, audited)
       └─ PayrollCalculator::calculate(employee, period)
            1 eligibility window (joining / exit)
            2 salary segments — every EmployeeSalaryAssignment effective in the window (mid-month revisions split)
            3 loss of pay — AttendanceOutput (absent days) + LeaveOutput::unpaidDays (approved unpaid leave) + approved manual LOP
            4 divisor — setting payroll.proration_basis: calendar_days | fixed_days (payroll.proration_fixed_days) | working_days (scheduled days from AttendanceOutput)
            5 earnings per segment (FormulaEngine, sandboxed arithmetic) — prorated components per segment; fixed and overtime-driven components once, on the current salary
            6 approved adjustments (earning, deduction, reimbursement, arrear, recovery, correction, lop)
            7 statutory lines — StatutoryEngine from versioned ComplianceRules (EPF, ESI, PT, LWF, TDS; generic SS/TAX for other jurisdictions)
            8 net, negative-net policy, bank check → exceptions
       └─ PayrollEntry + PayrollEntryLine (basis per line) → Payslip snapshot on finalization
```

The engine never reads punches, never recalculates attendance and never touches leave balances (architecture test). Attendance and leave are consumed only as `AttendanceOutput` / `LeaveOutput`.

## 2. Salary structure and components

`salary_components` (tenant-configurable, code-unique) with a controlled `type` (earning, deduction, employer_contribution, reimbursement — `SalaryComponent::TYPES`), classification, calculation method (fixed value from the assignment, formula, statutory), flags (taxable, PF/ESI wages, CTC, gross, recurring, proratable, arrear-eligible, statutory) and effective dates. `salary_structures` order components with optional formula overrides. Statutory components are platform-owned and locked. Formulas run in `FormulaEngine` (shunting-yard, arithmetic and whitelisted functions only; no PHP). Variables: other components, `ctc_annual`, `ctc_monthly`, `paid_days`, `lop_days`, `days_in_period`, `overtime_minutes`, `overtime_hours`, `pf_employer`, fixed values from the assignment.

## 3. Effective-dated salary

`employee_salary_assignments` are effective-dated rows; `Salaries::assign()` closes the previous one and is audited as sensitive (`SALARY_CHANGED`) and emits `employee.salary_changed`. A revision whose effective date falls inside a period the employee was already paid for (finalized/paid run) is refused — pay the difference as an arrear.

## 4. Overtime

Only `approved_overtime_minutes` from `AttendanceOutput` are used, through a component whose formula references `overtime_minutes`/`overtime_hours` (the rate is tenant configuration). With approved overtime and no such component the entry gets an `overtime_unpaid` exception; no rate is invented.

## 5. Explainability

Each line keeps `basis` (segments with from/to/full-month/paid/proration, formula, divisor, proration basis; statutory rule id, code, version, jurisdiction, state, effective date, verification status, source; adjustment id and source). Each entry keeps `inputs` (engine version, eligibility window, divisor, LOP by date, manual LOP, attendance summary and calculation versions, the `LeaveOutput` quantities, segments). Each run keeps `calculation_version`, `rule_versions` (every statutory rule version used) and `reconciliation`.

## 6. Exceptions

Blocking: `no_salary`, `no_structure`, `formula_error`, `invalid_component`, `negative_net` (unless `payroll.negative_net_policy = allow`). Warnings: `attendance_missing`, `attendance_incomplete`, `overtime_unpaid`, `no_bank`, and the statutory warnings (`no_pan`, …). Validation refuses a run with blocking entries.

## 7. Approval and finalization

Separation of duties (preparer ≠ approver). Calculation, approval and finalization each lock the run row. Finalization re-checks on the locked row: status approved, calculation version = current engine, no blocking entries, reconciliation balanced, and — when `peopleos.compliance.enforce_verified_rules` is on (production default) — every active statutory rule for the jurisdiction verified. It then locks the period's attendance days, closes the period, generates payslips (unique per entry) and audits `PAYROLL_FINALIZED`.

## 8. Statutory verification status

All 22 synced rule versions (India EPF, ESI, PT by state, LWF, TDS; UAE generic) are `illustrative`. Production finalization is therefore blocked until each is verified against its official source and marked `verified` with `verified_at` (compliance phase). Nothing in the code claims statutory compliance.

## 9. Immutability and corrections

Entries and lines of finalized/paid runs refuse update and delete; adjustments of a closed period are refused; attendance days are locked and leave on locked days cannot be approved or cancelled. Corrections: an **arrear** or **recovery** adjustment in an open period with `reference_period_id` pointing at the closed one, or an audited **reopen** of a finalized, unpaid run. Automatic arrear computation from retro salary/attendance changes is *deferred*.

## 10. Adjustments

`payroll_adjustments` with status (approved | pending), approver, source reference and reference period. With `payroll.adjustments.require_approval` on, new adjustments are pending until someone other than the creator approves (`PAYROLL_ADJUSTMENT_APPROVED`); every creation is audited (`PAYROLL_ADJUSTED`).

## 11. Reconciliation

`PayrollReconciliation::reconcile()` compares stored totals (employees, gross, deductions, net, employer cost, LOP days) to the sum of entries and entry-level net to the sum of lines. Stored on calculation and finalization; an unbalanced run cannot be finalized.

## 12. Payslips and security

Payslips are JSON snapshots (employee, company, period, days, earnings, deductions, employer contributions, net, bank masked) rendered in the panel; views and API reads are audited (`PAYSLIP_ACCESSED`). `PayslipPolicy`: own payslip, or `payroll.view` **and** organisation scope — a manager sees nothing through the reporting line alone. PDF files are *deferred* (no public storage is used).

## 13. Queues, scale, concurrency

`CalculatePayrollRun` (TenantAwareJob, ShouldBeUnique per run) calculates large runs off-request; the population is streamed with `lazyById(200)`. Run rows are locked for calculate/approve/finalize; `(payroll_run_id, employee_id)` and payslip-per-entry are unique; periods are unique per company and month. No scheduler finalizes payroll.

## 14. API

`/api/v1/payroll/`: periods, runs, runs/{id} (totals, rule versions, reconciliation), payslips, payslips/{number} (audited). Scope `payroll.read`; bank numbers are never returned. Employee self-service stays in the panel (API keys are not user identities).

## 15. Legal Entity / Establishment

Runs, periods, bank files and statutory profiles are per company (ADR-0001: Company = legal entity = establishment). Payroll calculation is safe under this model; statutory **filings** (ECR, ESI returns, PT returns, Form 16/24Q) are per establishment/TAN and are not built — they wait for ADR-0001.

## 16. Troubleshooting

| Symptom | Check |
|---|---|
| Run cannot be validated | Entries with blocking exceptions in the run page |
| Finalization says "recalculate" | Run calculated on an older engine version |
| Finalization says "do not reconcile" | Totals were changed outside calculation; recalculate |
| Finalization says rules are illustrative | Compliance rules need verification (production enforcement) |
| LOP seems wrong | `inputs.lop_by_date`: absent attendance days and approved unpaid leave only |
| Salary revision refused | The date is inside a paid period; use a later date and an arrear |
