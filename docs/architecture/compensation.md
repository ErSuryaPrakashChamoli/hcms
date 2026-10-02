# Compensation & Rewards (Phase 11)

For: engineers extending PeopleOS compensation, and anyone changing how Payroll, Letters or Exit read pay.

Code lives in `app/Domain/Compensation`. This file starts with the ownership decision and the caller
map that preceded the refactor, then describes the design.

## 1. Architecture decision: Compensation owns employee compensation (ADR, Phase 11.1)

**Decision (owner's choice, Option B):**

- `employee_salary_assignments` remains the canonical physical assignment history. There is no second
  salary-assignment table.
- Compensation is the domain owner and sole write authority of that table.
- Payroll and every other module consume compensation through controlled read contracts.

**Why:** discovery found that Payroll owned salary assignment in a way the Phase 11 design could not
sit on top of:

- `Salaries::assign` wrote the table directly, with no approval boundary;
- it deleted future-dated rows whenever an earlier change was made;
- the payroll calculator, Letters, Exit and two AI services read the table or the Payroll service
  directly.

The phase stopped at its §49 condition; the owner chose Option B over extending the Payroll-owned path
(A) or keeping Payroll's direct path (C).

**Boundary:**

| Compensation owns | Payroll owns |
|---|---|
| compensation structures, grades / ranges, compensation components (a structure's composition and compensation attributes), employee compensation assignments, effective dating, proposals, approvals, changes, history, cycles, planning | payroll-period calculation, attendance / leave inputs, statutory calculations, deductions, employer costs, payslips, runs, approval / finalisation, statutory returns, and the **payroll component catalogue** (calculation method, taxability, PF / ESI applicability, statutory flags), which Payroll and Statutory need to decide treatment |

**What changed in the move:**

- **Models moved, tables unchanged:** `EmployeeSalaryAssignment`, `SalaryStructure` and
  `SalaryStructureComponent` moved from `App\Domain\Payroll\Models` to
  `App\Domain\Compensation\Models` with the same class and table names.
- **History stays visible:**
  - Audit rows written before the move keep their stored class name (the hash chain forbids
    rewriting them). The models list it in `auditEntityAliases()`, so "What changed?" still shows them.
  - Polymorphic references (timeline sources, configuration changes) resolve through morph aliases
    for both the old class names and the new neutral names (`compensation.salary_assignment`,
    `compensation.salary_structure`, `compensation.salary_structure_component`).
- **`Payroll\Services\Salaries` is gone.** Its writer became `Compensation\Services\AssignmentWriter`,
  an internal executor that only takes an approved `CompensationChange`. Its reader became the
  `CompensationOutput` contract.

## 2. Caller map (before the refactor) and treatment

Baseline `49eff96`. Every read and write of employee salary, classified as the owner asked.

| # | Caller | Read / write | Classification | Phase 11 treatment |
|---|---|---|---|---|
| 1 | `Payroll\Services\Salaries::assign` | **Write**: insert, close the previous row, **delete later rows** | Compensation owner/write (misplaced in Payroll) | Removed. `AssignmentWriter` (internal) writes only from an approved change; it refuses instead of deleting |
| 2 | `Payroll\Services\Salaries::current` | Read | Compensation read | `CompensationOutput::on()` |
| 3 | `Payroll\Models\EmployeeSalaryAssignment` | Model | Compensation owner | Moved to `Compensation\Models`; model guard refuses any create / update / delete outside the writer |
| 4 | `Payroll\Models\SalaryStructure`, `SalaryStructureComponent` | Model | Compensation owner | Moved to `Compensation\Models` (same tables) |
| 5 | `PayrollCalculator::segments()` and the structure's items | Read (direct table) | Payroll consumer | `CompensationOutput::forPayroll()` |
| 6 | `PayrollComputation::$assignment` | Read | Payroll consumer | `CompensationSegment` value object (`$compensation`) |
| 7 | `PayrollRuns::calculate`: entry FK `employee_salary_assignment_id` | Read | Payroll consumer | The id comes from the contract segment; the column is unchanged |
| 8 | `PayrollRuns::finalize` | None | Payroll consumer | New: refuses entries whose compensation fingerprint changed since calculation |
| 9 | `PayrollEntry::assignment()` relation | Read (unused) | Payroll consumer | Removed (no callers); the FK column stays |
| 10 | `PayrollControlRoom`: "without salary" counts | Read (direct) | Payroll consumer | `CompensationOutput::coveredEmployeeIds()` |
| 11 | `PayrollDefaults::seed`: STANDARD structure | Write (configuration) | Compensation owner | Moved to `CompensationDefaults`; Payroll seeds components only |
| 12 | `PayrollConfigPolicy` for structures and assignments | Authorisation | — | `CompensationConfigPolicy` (`compensation.configure`), `CompensationPolicy` |
| 13 | `SalaryStructureResource` (Payroll navigation) | Write (configuration) | Compensation owner | Compensation navigation; `compensation.configure` |
| 14 | `Letters::context` via `Salaries::current` | Read | Letters consumer | `CompensationOutput::on()` |
| 15 | `FinalSettlements` via `Salaries::current` and the calculator | Read | Exit/F&F consumer | `CompensationOutput::on()`; the calculator reads through the contract |
| 16 | `Ai\PayrollAnomalyDetector` (revisions in a run's period) | Read (direct) | Payroll (AI) consumer | `CompensationOutput::history()` |
| 17 | `Ai\AttritionRisk` (date of the last revision) | Read (direct) | AI consumer | `CompensationOutput::history()` |
| 18 | `Analytics\EmployeesDataset` (`ctc_annual` column) | Read (relation) | Reporting consumer | `CompensationOutput::annualCtcOn()`; the field now needs `compensation.view` |
| 19 | `Employee::salaryAssignments()` | Relation | Employee 360 presentation | Kept (read-only presentation); `compensationChanges()` added |
| 20 | `SalaryRelationManager` (Employee 360) | Read + **direct write** ("Assign / revise salary") | Employee 360 presentation/action | `CompensationRelationManager`: read + "Propose compensation change"; new `CompensationChangesRelationManager` drives the approval chain |
| 21 | `config/peopleos.php` classification (financial) and risk tier | Classification | — | Moved class; `CompensationChange` added (financial; internal notes highly sensitive) |
| 22 | `DatabaseSeeder::seedPayroll` | Write via `Salaries::assign` | Test/support | Full flow with four seeded users |
| 23 | Tests: the `salariedEmployee()` helper and 8 direct `Salaries::assign` calls in 7 files | Write | Test/support | `compensate()` helper (tests/Pest.php) runs propose → review → approve → execute |
| 24 | Architecture tests naming `EmployeeSalaryAssignment` (Talent, Workforce, Learning, Performance) | Negative scans | Test | Unchanged (they match the class name) |
| 25 | `EmploymentEvent employee.salary_changed` | Event | Webhook / notification consumers | Still emitted by the executor; no amounts |
| 26 | API | None | — | New read-only `/api/v1/compensation` (Phase 11.4) |
| 27 | Stored data on dev: 10 / 1 / 4 audit rows and 5 timeline rows naming the old classes | Data | — | Aliases above; nothing rewritten |

## 3. Write path

```
Proposal ─► Review ─► Approval ─► Schedule (executor) ─► Effective-date processor
  draft      submitted   approved     canonical row written,     status effective
             under_review             effective-dated (future     (event, notifications)
                                      or today)
```

**Four different people:**
- proposer (`compensation.propose`), reviewer (`compensation.review`), approver
  (`compensation.approve`) and executor (`compensation.execute`);
- enforced in `CompensationChanges` and by a MySQL CHECK constraint on `compensation_changes`;
- nobody acts on their own compensation.

**When the canonical row is written:** at **scheduling**, not on the effective date. The row's
`effective_from` decides when it is in force. This is a deliberate reading of the owner's flow
(Proposal → … → Schedule → Effective-date execution → canonical assignment):

- `getCompensation(employee, futureDate)` (§11) must return approved future compensation.
- Payroll for a period must see an approved mid-period change even if the effective-date processor
  has not run yet. Otherwise a run finalized before the processor ran would pay the old salary, and
  the processor would then be refused by the closed period.

The effective-date processor (`peopleos:compensation:effect`, daily) marks scheduled changes
**Effective** when their date comes. A scheduled change can still be cancelled until then: its row is
kept with status `cancelled` and the timeline closes around it.

**`AssignmentWriter` rules** (inclusive dates; active rows of an employee never overlap):

| Situation | Result |
|---|---|
| Date inside a finalized or paid payroll period | Refused (pay the difference as an arrear), as before |
| The compensation in force on the date differs from what the change was approved against | Refused (return for a new decision) |
| An active row already starts on the date | Refused, unless the change is a `correction`: then that row is **superseded** (kept) |
| A previous row | Closed the day before |
| A later row (already scheduled) | Kept. The new row ends the day before it (**never deleted**) |
| Database backstop | Unique `(employee_id, effective_from, active_key)`, where `active_key` is 1 only while active; MySQL CHECKs on amounts, dates and status |

**Locks, in order:**
1. the change row;
2. the employee row;
3. the payroll runs that hold the employee for periods ending on or after the date
   (`PayrollClosureReader`; finalisation locks the same run row);
4. the employee's active rows (locking reads).

## 4. Read contract

`App\Domain\Compensation\Contracts\CompensationOutput` (version `compensation-output-1`), implemented
by `CompensationLedger`. It reads active rows only, so draft, submitted, rejected and cancelled
compensation can never reach a consumer.

| Method | Consumer |
|---|---|
| `forPayroll(employee, from, to)` | Payroll calculator. Segments with their structure's components, plus a fingerprint |
| `fingerprints(ids, from, to)` | Payroll finalisation (integrity; one query) |
| `on(employee, date)` | Letters, Exit / F&F, getCompensation(employee, date) |
| `history(employee)` | AI payroll auditor, attrition signal |
| `coveredEmployeeIds(date)` | Payroll control room |
| `annualCtcOn(ids, date)` | Analytics dataset |

Payroll engine `payroll-2.3` = 2.2 plus reading compensation through the contract. Amounts, proration
and statutory treatment are unchanged. Each entry records the contract version and a fingerprint, and
`finalize` refuses an entry whose compensation changed since calculation (recalculate first).

## 5. Statutory

Unchanged:
- 24 rule versions, 0 verified, 5 open notices;
- the payroll component catalogue (statutory flags) and the statutory engine are not touched.

**Statutory production readiness: NOT DECLARED.**
