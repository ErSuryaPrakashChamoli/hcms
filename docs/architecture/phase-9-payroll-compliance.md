# Phase 9 — Payroll and Compliance

Blueprint §29–§33, §70, §100, §101, §116. Built 2026-09-26.

## What exists

**Domain: `App\Domain\Payroll`**

| Model | Purpose |
|---|---|
| `SalaryComponent` | A pay element: type (earning / deduction / employer_contribution / reimbursement), classification, calculation method (`fixed` from the assignment, `formula`, or `statutory`), and the §30 properties (taxable, PF/ESI wages, CTC/gross inclusion, recurring, proratable, arrear-eligible). `is_statutory` rows are locked (type, formula, code cannot change; not deletable). Codes `PF_EE, PF_ER, PF_ADMIN, ESI_EE, ESI_ER, PT, LWF_EE, LWF_ER, TDS` are reserved for compliance lines. |
| `SalaryStructure` + `SalaryStructureComponent` | Ordered components with optional per-structure formula override. |
| `EmployeeSalaryAssignment` | Effective-dated salary history: structure, annual CTC, monthly fixed `component_values`, change type, reason. CTC and values are audit-sensitive. |
| `PayrollPeriod` | One calendar month per company; `closed` once finalized. |
| `PayrollRun` | The pipeline instance: `draft → calculated → validated → approved → finalized → paid`; `totals` JSON, `exception_count`, who prepared/approved/finalized. |
| `PayrollEntry` + `PayrollEntryLine` | Per-employee result with days, totals, status (`ok` / `warning` / `exception`), `exceptions`, `inputs` (traceability) and one line per component with its `basis` (formula, proration, rule version, wages). |
| `PayrollAdjustment` | One-off period inputs: earning, deduction, reimbursement, or manual LOP days. |
| `Payslip` | Immutable snapshot generated at finalization (`PS-YYYYMM-<employee code>`), deleted only when a run is reopened. |

**Domain: `App\Domain\Compliance`**

| Model | Purpose |
|---|---|
| `ComplianceRule` | Platform-owned, **no tenant_id**, versioned and effective-dated statutory parameters (EPF, ESI, PT per state, LWF per state, TDS per FY, gratuity). Loaded from `database/data/compliance/<jurisdiction>.php` by `peopleos:compliance:sync` (scheduled weekly). Tenants can read, never write (`ComplianceRulePolicy`). |
| `CompanyStatutoryProfile` | Per legal entity: which statutes apply, PT/LWF state, PF ceiling restriction, registrations (PF code, ESI code, PT registration, TAN, PAN). Governed configuration (medium risk). |
| `EmployeeTaxDeclaration` | Regime (`new` default / `old`), Chapter VI-A declarations, HRA rent, previous-employer income/TDS, status draft → submitted → verified. |

**Services**

- `FormulaEngine` — a tokenizer + shunting-yard evaluator (no `eval`): numbers, variables, `+ - * / %`, comparisons, `&& ||`, parentheses, unary minus, `min max round floor ceil abs if`. Variables resolve through a closure so components can reference earlier components (`basic`, `hra`), `ctc_annual`, `ctc_monthly`, `paid_days`, `lop_days`, `days_in_period` and `pf_employer` (estimated employer PF on PF wages so far, for CTC-balancing formulas).
- `PayrollCalculator` — pure per-employee pipeline: attendance/LOP → components (full-month amounts, then proration of proratable earnings by `paid_days / days_in_period`) → adjustments → `StatutoryEngine` → exceptions. Paid days = calendar days less pre-joining / post-exit days, attendance LOP (`absent` = 1, `unpaid_leave` = 1 or 0.5) when `payroll.lop_from_attendance` is on, and manual LOP adjustments.
- `StatutoryEngine` (Compliance) — EPF (employee 12%, employer 12% split EPS/EPF, EDLI + admin, ceiling optional per profile), ESI (eligibility on full-month ESI wages ≤ ceiling, ceil rounding), PT (state slabs on gross, February/March specials, female exemption where the rule has it; state from employee statutory detail, else profile), LWF (fixed amounts in contribution months), TDS via `TaxComputer`.
- `TaxComputer` — s.192 projection: YTD taxable from finalized entries in the FY + this month × remaining months + previous employer; HRA exemption (old regime, needs `HRA_RENT`/`METRO` declarations), standard deduction, PT (old), Chapter VI-A with per-section caps (PF employee share counts under 80C), slab tax, 87A rebate with marginal relief (new), surcharge (capped 25% new), 4% cess; balance spread over remaining months. No PAN ⇒ higher of computed tax or 20% of taxable income and a `no_pan` exception.
- `Salaries` — `assign()` closes the previous open assignment the day before, rejects an assignment starting on/after an existing one, drops later-dated ones, audits `SALARY_CHANGED` (sensitive) and writes a compensation timeline entry.
- `PayrollRuns` — `open` (one open run per period), `calculate` (replaces entries, totals, `payroll.calculated`), `validate` (no `exception` entries), `approve` (**separation of duties**: approver ≠ preparer unless platform admin), `finalize` (locks attendance `is_locked`, closes the period, generates payslips, `payroll.finalized`), `markPaid`, `reopen` (finalized & unpaid only; reason mandatory; withdraws payslips, unlocks attendance, `PAYROLL_REOPENED`).
- `Payslips` — snapshot builder + Indian number-to-words; `BankFile` — CSV with decrypted account numbers, audited as `EXPORT`.
- `PayrollDefaults` — seeds BASIC (40% CTC), HRA (50% basic), CONV (fixed), SPECIAL (balances to CTC net of employer PF), BONUS, ADV and the `STANDARD` structure at tenant provisioning.

**Events / notifications** — `PayrollEvent` (`payroll.calculated|approved|finalized|paid|payslip_generated|exception`) is bridged into the notification engine; payslip generation always reaches the employee in-app even without a rule.

**Admin UI (nav group "Payroll")** — Control room page (readiness counters, per-company month status, run register), Payroll runs (list + view with pipeline actions and employee breakdown modal), Adjustments, Payslips (employees see only their own; viewing is audited), Tax declarations, Salary components / structures (GovernedEdit), Statutory profiles, Statutory rules (read-only with parameter viewer). Employee 360 gains a **Salary** tab (sensitive; assign/revise action).

**Permissions** — `payroll.view|manage|calculate|approve|finalize|payslip`, `compliance.view`. Payroll Admin template has `payroll.*`; Employee has `payroll.payslip`.

## Conventions

- Statutory logic and rates live outside tenant configuration (`ComplianceRule` + `StatutoryEngine`). Tenants only choose applicability and registrations on the statutory profile. Never let a tenant-editable formula compute a statutory line.
- Compliance pack values are illustrative for FY 2025-26 and **must be verified against current notifications** before production. Add a new version row (never edit an old one) when rates change; `effective_to` closes the old row.
- Every entry line carries its `basis`; every run transition is audited. Payslips are snapshots: never read live data for a payslip.
- Amounts in the assignment's `component_values` are monthly; `ctc_annual` is annual.
- Tests: `tests/Feature/Payroll/{FormulaEngineTest,StatutoryTest,PayrollRunTest}.php`, `tests/Feature/Admin/PayrollPagesRenderTest.php`; helpers in `tests/Feature/Payroll/PayrollTestHelpers.php` (`syncComplianceRules()`, `payrollCompany()`, `salariedEmployee()`).

## Known gaps / deferred

- Mid-month salary revisions apply to the whole month (the assignment effective at period end wins); arrears are not automated.
- ESI eligibility does not yet persist across the contribution period once an employee crosses the ceiling mid-period.
- TDS projects from actual finalized months only; a tenant starting mid-year without history under-projects until declarations carry previous income.
- Payroll simulation mode (§74), Form 16 / challan / ECR file generation, gratuity accrual, loan/advance schedules and multi-currency are not built.
- Payroll workflow approval (routing an approval through the workflow engine) is not wired; approval is the in-app action with separation of duties.
