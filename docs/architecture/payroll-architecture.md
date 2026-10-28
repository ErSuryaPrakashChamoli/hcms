# Payroll — architecture

Phase 4 (27 September 2026). Describes what is implemented; *deferred* items are not built. Statutory rates remain **illustrative** (see §8).

## 1. Pipeline (engine `payroll-2.4`: 2.1 = Phase 5 establishment-resolved statutory context; 2.2 = salary TDS by payment date; 2.3 = compensation through `CompensationOutput`; 2.4 = formula flexibility, §2.1)

```
PayrollPeriod (company, month; open → closed)
  └─ PayrollRun  draft → calculated → validated → approved → finalized → paid   (approved → draft allowed, audited; finalized and paid runs are never reopened, 2026-10-28)
       └─ PayrollCalculator::calculate(employee, period)
            1 eligibility window (joining / exit)
            2 salary segments — every EmployeeSalaryAssignment effective in the window (mid-month revisions split)
            3 loss of pay — AttendanceOutput (absent day 1; half day 0.5; half-day leave with nothing in the other half 0.5) + LeaveOutput::unpaidDays (approved unpaid leave) + approved manual LOP; at most 1 a day
            4 divisor — setting payroll.proration_basis: calendar_days | fixed_days (payroll.proration_fixed_days) | working_days (scheduled days from AttendanceOutput)
            5 earnings per segment (FormulaEngine, sandboxed arithmetic) — prorated components per segment; fixed and overtime-driven components once, on the current salary
            6 approved adjustments (earning, deduction, reimbursement, arrear, recovery, correction, lop)
            7 statutory lines — StatutoryEngine from versioned ComplianceRules (EPF, ESI, PT, LWF, TDS; generic SS/TAX for other jurisdictions)
            8 net, negative-net policy, bank check → exceptions
       └─ PayrollEntry + PayrollEntryLine (basis per line) → Payslip snapshot on finalization
```

The engine never reads punches, never recalculates attendance and never touches leave balances (architecture test). Attendance and leave are consumed only as `AttendanceOutput` / `LeaveOutput`.

### 1.1 Pay cycle (2026-10-28)

A payroll month's dates come from the tenant's **pay cycle**: setting `payroll.cycle_start_day`, 1 (calendar month, the default) to 28. At 25, November runs from 25 October to 24 November. A month is named after the month it ends in. Every company of the tenant shares the cycle, so the monthly attendance upload stays one sheet a month. Admins change it under **Payroll → Pay cycle** (`PayCyclePage`, `payroll.manage`). The page shows what the next four months will cover for each company before saving, and the reason is audited with the setting.

- `PayCycle` (`Payroll\Services`) holds the rules. `PayrollPeriod::for()` and `::containing(company, date)` delegate to it. Other modules read the cycle through `Payroll\Contracts\PayCycleReader`.
- **Fixed dates.** A month's dates are fixed once a run is opened for it or it closes. Before that, they follow the cycle as it is now: `for()` re-dates the month, and `PayCycle::change()` re-dates every open month with no run.
- **Changeover.** A month starts the day after a fixed month before it and ends the day before a fixed month after it. So a change never pays a day twice or leaves one out. After a calendar October, a 25th cycle gives November 1–24 Nov. Going back to calendar months after a 25 Nov – 24 Dec December gives January 25 Dec – 31 Jan.
- **Selecting months by name.** Code that selects months by name uses `year`/`month` or the `monthsBetween($from, $to)` scope, never `start_date`. A month counts when its 1st is in the range, the same as before for calendar months. That covers statutory returns (`StatutoryPayrollSource`, `EpfReturns`), workforce cost, compliance-report payroll states, analytics period columns and the API `period` (`PayrollPeriod::key()`).
- **Wage month.** Statutory wage months (PF, ESI, PT, LWF months) are the month's name. TDS stays on the payment date, which defaults to the period end.
- **Calendar-month registers.** Compliance registers built from attendance (muster rolls, wage registers) still read calendar months (`ReportPeriod`). Whether they follow the cycle is an open decision.

## 2. Salary structure and components

`salary_components` (tenant-configurable, code-unique) with a controlled `type` (earning, deduction, employer_contribution, reimbursement — `SalaryComponent::TYPES`), classification, calculation method (fixed value from the assignment, formula, statutory), flags (taxable, PF/ESI wages, CTC, gross, recurring, proratable, arrear-eligible, statutory) and effective dates. `salary_structures` order components with optional formula overrides. Statutory components are platform-owned and locked.

### 2.1 Formulas (engine `payroll-2.4`)

- **Engine:** `FormulaEngine` (tokenizer + shunting-yard, no PHP `eval`): numbers, `"text"`, variables, `+ - * / %`, comparisons, `&& ||`, and the whitelisted `FormulaEngine::FUNCTIONS`: `min max clamp round round_to ceil_to floor_to floor ceil abs if in between lookup slab`. Arity, alternation of values and operators, and unknown functions are parse errors. `"basic * 40%"` gets a hint, because `%` is the remainder operator, not percent. Text compares ignoring case and is refused in arithmetic. A division by zero poisons only its own value, so an untaken `if` / `lookup` / `slab` branch does not fail the formula.
- **Which formula runs:** `SalaryComponent::formulaFor(override)`. The structure line's override applies whatever the calculation method; otherwise the component's formula when it is formula-calculated; otherwise the fixed amount on the employee's compensation. Payroll 2.3 ignored an override on a fixed component.
- **Names:** `Payroll\Support\FormulaVariables` is the single catalogue, used by the calculator, the draft check, the preview and the in-app guide. It covers:
  - pay and month values (`ctc_annual`, `ctc_monthly`, `variable_target_annual`, `days_in_period`, `paid_days`, `lop_days`, `overtime_*`, `pf_employer`);
  - employee values read from the position on the last day paid (`grade`, `grade_rank`, `level`, `company`, `location`, `work_city`, `work_state`, `department`, `designation`, `employment_type`, `employee_category`, `years_of_service`), loaded once per entry and only when a formula uses one;
  - the structure version's **parameters** (`salary_structure_versions.parameters`, named numbers such as `basic_pct = 50`, versioned and approved with the version);
  - the lowercase codes of the components above the line.

  These names are reserved: a new component code or a parameter cannot take one, nor a statutory code.
- **Order:** lines run in sort order. A formula may use only the components above it.
- **Draft check:** `StructureFormulas::check` runs when a draft is created, saved or submitted (`CompensationStructures`; errors roll the save back). It reports parse errors, unknown names (with a "did you mean"), components used before they are calculated, components missing from the structure, statutory codes, self-reference and bad parameter names. It also warns when `pf_employer` is used above a PF-wage component.
- **Preview:** `StructureFormulas::preview` gives the full-month breakup for a sample CTC, fixed amounts and employee values, using the same rules as the calculator. The employer-PF estimate goes through `StatutoryEngine::employerPfOn`. The preview reconciles to monthly CTC and shows advisory guardrails from `peopleos.payroll.guardrails`. The only one so far is the Code on Wages 50% wage-share check, which is *advisory, not legal advice*; the statutory engine does **not** apply the wage add-back to PF / gratuity.
- **UI:** structure Versions: a **Formula guide** header action, a **Preview** row action, a parameters editor and a live preview in the draft form, and a placeholder showing each line's default formula. The components form links to the guide.
- **Known limit:** editing a catalogue component's formula changes it for every structure without an override, including approved versions, because catalogue formulas are not versioned. Structure-specific formulas belong in an override, which is versioned and approved.

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

Entries and lines of finalized/paid runs refuse update and delete; adjustments of a closed period are refused; attendance days are locked and leave on locked days cannot be approved or cancelled. Corrections: an **arrear** or **recovery** adjustment in an open period with `reference_period_id` pointing at the closed one. Since 2026-10-28 a finalized or paid run is never reopened: `PayrollRuns::reopen()` refuses it on the locked row and audits the attempt (`REJECTED`); `PayrollRun`, `PayrollPeriod` and `Payslip` refuse the same change through Eloquent. How a wrong finalized month is settled is decision C12-D1 (open; reopening is its option D). Automatic arrear computation from retro salary/attendance changes is *deferred*.

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
| LOP seems wrong | `inputs.lop_by_date`: absent days (1), half days (0.5, none when the day also has half-day leave), half-day leave with the other half absent (0.5), and approved unpaid leave; payroll-2.5 onward (2.4 and earlier paid half days in full) |
| Salary revision refused | The date is inside a paid period; use a later date and an arrear |
