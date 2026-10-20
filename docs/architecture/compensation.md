# Compensation & Rewards (Phase 11)

For: engineers extending PeopleOS compensation, and anyone changing how Payroll, Letters or Exit read pay.

Code lives in `app/Domain/Compensation`. This file starts with the ownership decision and the caller
map that preceded the refactor, then describes the design. Phase report: `docs/PeopleOS-Phase-11-Report.md`.

## 1. Architecture decision: Compensation owns employee compensation (ADR, Phase 11.1)

> employee_salary_assignments remains the single canonical physical assignment history, but
> Compensation is its domain owner and sole write authority. Payroll and other modules consume
> compensation through controlled read contracts.

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

## 3. Concepts

| Concept | Record | Meaning |
|---|---|---|
| Definition | `salary_structures` + `salary_structure_versions` + `salary_structure_components` | Which payroll components pay is built from, in which order, with which overrides, from when |
| Component | Payroll's `salary_components` (catalogue) + the version line's `pay_nature` / `frequency` | What a component is. Statutory treatment stays in Payroll's catalogue; Compensation adds fixed / variable and frequency |
| Range | `compensation_ranges` | What a grade (optionally narrowed) permits: minimum, midpoint, maximum |
| Assignment | `employee_salary_assignments` | What one employee actually receives, effective-dated (the canonical history) |
| Change | `compensation_changes` | A proposed or decided change to one employee's compensation |
| Payroll consumption | `CompensationOutput` | What Payroll may consume for a period: approved, effective compensation only |

Position ≠ compensation: positions and employees carry no pay columns (architecture test), and two
people in one position can be paid differently. A position's grade, role and organisation only select
the applicable range.

## 4. Structures and components (§6, §7)

```
draft ─submit─► pending_approval ─approve (≠ preparer)─► scheduled ─(its date)─► active ─► superseded
  ▲                    │                                                      archive: draft, pending, superseded
  └──── return ────────┘
```

- **Versions:** a structure is an identity (code, name, status). Its composition is a series of
  effective-dated versions with currency, pay frequency and applicability (one company or every
  company; a list of grades or every grade).
- **No parallel table:** the existing `salary_structure_components` rows are the version lines
  (`salary_structure_version_id`). The Phase 11 migration gave every existing structure version 1,
  in force since 2000-01-01, holding its rows, so payroll calculates exactly as before.
- **Immutable once approved:** model guards refuse content changes after a draft is submitted, and any
  change to the lines of a non-draft version. A checksum is stored at approval.
- **Corrections:** a new version, copied from the latest approved one, from a later date. Approval:
  - closes the previous version the day before;
  - is refused inside a finalized payroll period (`PayrollClosureReader::latestClosedPeriodEnd`);
  - is refused on or before an approved version's start.
- **Activation:** the daily processor turns a scheduled version active on its date and supersedes the
  one before it.

## 5. Ranges, range position and compa-ratio (§8, §25)

- **Applicability:** a range belongs to a grade, optionally narrowed to a structure, company, job
  family or designation. `applicability_key` identifies "the same definition".
- **Approval:** drafted, submitted and approved by a second person. Approved definitions with the same
  key never overlap: a later version closes the earlier one; an earlier or equal start is refused.
- **Validation:**
  - minimum ≤ midpoint ≤ maximum, and no negative amount (also MySQL CHECKs);
  - ISO 4217 currency (`peopleos.compensation.currencies`);
  - no reversed dates.
- **Range model:** `peopleos.compensation.range_model`. `min_mid_max` requires a midpoint; `min_max`
  makes it optional.
- **Lookup:** `CompensationRanges::rangeFor` returns the most specific approved range in force
  (designation > job family > company > structure > grade only).

`CompensationRanges::position()` is descriptive arithmetic only. A monthly range is ×12, and
currencies are never converted.

| Measure | Definition | Undefined when |
|---|---|---|
| Range position | (CTC − minimum) / (maximum − minimum) | minimum = maximum |
| Compa-ratio | CTC / midpoint | no midpoint (or midpoint 0) |
| Band | below (< minimum), within, above (> maximum) | currency differs, no range, invalid range |

## 6. Employee compensation and effective dating (§10–§12, §41, §42)

**Dates:** `effective_from` and `effective_to` are inclusive. A revision from D closes the previous row
on D − 1, so `getCompensation(employee, D − 1)` and `getCompensation(employee, D)` return different
rows and no date returns two.

**Rows:**
- **Active** rows form the timeline (unique `(employee_id, effective_from, active_key)`).
- **Superseded** rows were replaced by a same-date correction.
- **Cancelled** rows were scheduled and then cancelled.
- Neither kind is deleted. A cancelled correction restores the row it superseded.

**What a row may change:** amounts, structure, currency and start date never change once written. Only
the end date (the timeline closing or reopening around another row) and the status move
(`EmployeeSalaryAssignment::MUTABLE`).

**Lifecycle** (`peopleos.compensation.lifecycle`):

| May receive | States |
|---|---|
| Compensation effective today or earlier | preboarding, onboarding, joined, probation, confirmed, active, on leave, suspended, notice period |
| Future compensation | the same, plus pre-employee |
| Cycle lines | probation, confirmed, active, on leave |
| A correction on or before the exit date | exited |

- Nothing may start after an exit date.
- Exited and alumni employees receive nothing new.
- A rehire is the same employee record: the new row closes the old open row, and all history stays.

## 7. Write path and separation of duties (§13, §14)

```
Proposal ─► Review ─► Approval ─► Schedule (executor) ─► Effective-date processor
  draft      submitted   approved     canonical row written,     status effective
             under_review             effective-dated (future     (event, notifications)
                                      or today)
reject / return (reviewer or approver) · cancel (proposer before a decision; an approver who did not propose it afterwards)
```

**Four different people:**
- proposer (`compensation.propose`), reviewer (`compensation.review`), approver
  (`compensation.approve`), executor (`compensation.execute`);
- enforced in `CompensationChanges` and by a MySQL CHECK on `compensation_changes`;
- nobody acts on their own compensation, whatever their permissions.

**When the canonical row is written:** at **scheduling**, not on the effective date. The row's
`effective_from` decides when it is in force. This is a deliberate reading of the owner's flow
(Proposal → … → Schedule → Effective-date execution → canonical assignment):

- `getCompensation(employee, futureDate)` (§11) must return approved future compensation.
- Payroll for a period must see an approved mid-period change even if the effective-date processor
  has not run yet. Otherwise a run finalized before the processor ran would pay the old salary, and the
  processor would then be refused by the closed period.

**Effective-date processor:** `peopleos:compensation:effect`, daily at 00:20, `EffectDueCompensation`
job. It marks scheduled changes **Effective** on their date. Until then a scheduled change can be
cancelled: its row is kept as `cancelled` and the timeline closes around it.

**Every step:**
- locks the change row and re-checks status and organisation scope on the locked row;
- bumps `lock_version`;
- audits (SUBMITTED, REVIEWED, APPROVED, REJECTED, CANCELLED, SCHEDULED, EFFECTED, CORRECTED,
  SALARY_CHANGED);
- emits a `CompensationEvent`.

**Stale approvals:** approval and execution refuse a change whose "compensation in force on the date"
moved since submission (return it for a new decision).

**`AssignmentWriter` rules** (`@internal`; the only writer of the table; a model guard refuses
anything else):

| Situation | Result |
|---|---|
| Date inside a finalized or paid payroll period | Refused (pay the difference as an arrear), as before |
| An active row already starts on the date | Refused, unless the change is a `correction`, which supersedes it. A correction is refused for a row whose change has not taken effect yet (cancel that change instead) |
| A previous row | Closed the day before |
| A later row (already scheduled) | **Kept.** The new row ends the day before it (`Salaries::assign` used to delete it) |
| Database backstop | Unique active start date; MySQL CHECKs on amounts, dates, status / active_key |

**Lock order** (the same order as payroll finalisation, so the two wait for each other instead of
deadlocking):
1. change row;
2. payroll runs of periods ending on or after the date (`PayrollClosureReader::closedOnOrAfter`);
3. employee row;
4. the employee's active rows (locking reads);
5. budget row (approval only).

## 8. Bulk cycles (§15)

`compensation_cycles` (annual increment, promotion, market adjustment). Its lines are ordinary
`compensation_changes` (`source = cycle`, unique per cycle and employee), so every employee-level step
is the same controlled, audited path.

- **Populate** (preparer):
  - snapshots the eligible employees: company / unit / location, cycle lifecycle states, the
    preparer's organisation scope, never the preparer's own record;
  - snapshots their compensation in force on the cycle date;
  - proposes `previous × (1 + % / 100)`. The % is the cycle default or, when a Phase 7 performance
    cycle is named, the % for the employee's **finalized** rating label (`PerformanceOutcomesReader`).
  - stores a snapshot checksum. Re-populating replaces draft lines deterministically.
- **Submit → review → approve → execute:** four different people (service + MySQL CHECK).
- **Execution:**
  - runs every line in one transaction under one audit operation id (BULK_OPERATION); any failing
    line rolls back all of them, and the cycle stays approved;
  - is idempotent: an executed cycle returns its operation id and changes nothing.
- **Notifications:** per-line notifications are suppressed during bulk steps (`quietly`); the cycle
  emits one event. Audit is per line.

A rating only prefills a proposal; people review, approve and execute every line. No "update
salaries" mass action exists.

## 9. Budgets (§16)

`compensation_budgets`: an amount of **annualised CTC increase** (new annual CTC − annual CTC before
the change) for a company (optional unit / location) and period, in one currency. Gross pay, employer
cost and payroll cost are never mixed in.

| Measure | Changes counted |
|---|---|
| Budget | The approved amount |
| Planned | draft, submitted, under review |
| Approved | approved, not executed |
| Committed | scheduled + effective |
| Actual | committed and in force on the as-of date |
| Variance | budget − (approved + committed) |

**Approval charging:**
- Approval charges `charged_amount` on the **locked budget row** and refuses an increase larger than
  what is left. Cancelling an approved change releases it.
- A locking read over other change rows was tried first: it deadlocked two approvers, each holding its
  own change row (MySQL race 3), so the running total replaced it.
- Other currencies, other periods and unapproved budgets are never charged.
- An optional link to a Phase 10 workforce budget is a reference only.

## 10. Payroll contract (§18, §19)

`App\Domain\Compensation\Contracts\CompensationOutput` (version `compensation-output-1`), implemented
by `CompensationLedger`. It reads active rows and approved structure versions only, so draft,
submitted, rejected, cancelled and approved-but-unexecuted compensation never reaches a consumer.

| Method | Consumer |
|---|---|
| `forPayroll(employee, from, to)` | Payroll calculator: one segment per (compensation row × structure version) in the window, with that version's components; a part with no approved version yields a segment with no components (Payroll raises `no_structure`) |
| `fingerprints(ids, from, to)` | Payroll finalisation (one query; ranges clipped to the window) |
| `on(employee, date)` | Letters, Exit / F&F, Employee 360, API (getCompensation) |
| `history(employee)` | AI payroll auditor, attrition signal, My compensation, API |
| `coveredEmployeeIds(date)` | Payroll control room readiness |
| `annualCtcOn(ids, date)` | Analytics dataset |

**Payroll engine `payroll-2.3`** = 2.2 plus reading compensation through the contract:
- amounts, proration and statutory treatment are unchanged;
- each entry records the contract version and a fingerprint of the rows and versions it used;
- `finalize` (after locking the run) refuses an entry whose fingerprint changed: recalculate first.

Payroll owns `PayrollClosureReader` (closed periods, with the run lock) for Compensation; nothing else
crosses the boundary.

## 11. Workforce, performance and career integration (§9, §17, §26, §27)

- **`CompensationPlanning::priceVersion`:** prices a workforce plan version at the applicable ranges
  (minimum / midpoint / maximum annual CTC × signed headcount). Read-only; lines without a grade or
  range are reported as unpriced.
- **`proposeFromPosition`:** the explicit action from planning to people. It creates a **draft** change
  from the position's range, with from / to position and grade references. It moves nobody.
- **Never written:** positions, plans, budgets, performance, career, talent and succession records
  (architecture tests).

## 12. Access and field security (§20–§23)

`CompensationAccess::level()` follows the chain tenant → permission → organisation scope →
relationship → field → record:

| Level | Who | Sees |
|---|---|---|
| full | `compensation.view`, in organisation scope | Everything: current, history, scheduled, superseded / cancelled rows, components, range position, changes and their reasons |
| team | `compensation.team` and a configured manager relationship (`PerformanceRelationships`: line, functional, dotted, secondary, hrbp; never mentor, buddy or project) | Approved compensation of reports. No proposals, reasons or notes |
| self | `compensation.self` and the tenant setting `compensation.self_service` (default on) | Own approved compensation (My compensation page). Never proposals, reasons, internal notes or ranges |

- **Changes:** visible to full readers and to the people with a duty on that change, never to the
  employee concerned.
- **Payroll permissions:** grant no salary access; payroll admins get `compensation.view` and
  `compensation.execute` through the role template.
- **Reports:** the analytics dataset's CTC field needs `compensation.view`.
- **Classification:** assignments, changes, ranges and budgets are financial (amounts and reasons
  masked in audit); `compensation_changes.internal_notes` is highly sensitive (encrypted); cycles are
  confidential.
- **Audited reads:** opening the Compensation tab or My compensation, and API reads.

**Role templates:**

| Role | Compensation permissions |
|---|---|
| tenant-hr-admin | `compensation.*` (duties still split per change) |
| hr-manager | view, propose, review |
| payroll-admin | view, execute |
| employee | self |
| executive | analytics |
| auditor | view |
| manager | none: tenants grant `compensation.team` explicitly |

## 13. API (§31)

`/api/v1/compensation`, read-only:

| Scope | Endpoints |
|---|---|
| `compensation.read` | `structures` (approved versions and components), `grades`, `ranges` (`?on=`), `cycles` |
| `compensation.read` + `compensation.sensitive` | `employees/{code}` (`?on=`) and `employees/{code}/history`. Every read is audited (VIEW, source api) |

- Codes, not ids; another tenant's code returns 404.
- **No write endpoints:** they could not yet meet the same four-person approval chain as the UI.

## 14. Audit, events, notifications, jobs (§28–§32)

- **Audit:** the existing `AuditRecorder` hash chain:
  - new actions REVIEWED, SCHEDULED, EFFECTED and CORRECTED, plus the existing SUBMITTED, APPROVED,
    REJECTED, CANCELLED, SALARY_CHANGED, BULK_OPERATION, EXPORT, VIEW, CREATE / UPDATE;
  - sensitive values masked.
- **Events:** `CompensationEvent` carries `compensation.change.*`, `compensation.structure.approved`,
  `compensation.range.approved`, `compensation.cycle.*` and reminders. Context holds a reference,
  type, status, dates and employee code: never amounts, reasons or notes.
- **Notifications:** in-app, to the next people in the chain only, never to the employee.
- **Webhooks:** the allow-list covers change approved / scheduled / effective / cancelled, structure
  approved and cycle executed. `employee.salary_changed` (EmploymentEvent) is still emitted by the
  executor for existing integrations.
- **Jobs and scheduler:**
  - `EffectDueCompensation` and `SendCompensationReminders` are TenantAwareJob + BindTenantContext and
    unique per tenant;
  - `peopleos:compensation:effect` runs at 00:20 and `peopleos:compensation:send-reminders` at 08:15,
    both `withoutOverlapping()->onOneServer()`;
  - reminders are throttled through `compensation_reminder_logs`.

## 15. Analytics and privacy (§24, §43)

`CompensationAnalytics::summary(viewer, filters, asOf)` reports, within organisation scope and in a
constant number of queries:
- totals per currency;
- breakdowns by grade, department, location and employment type;
- range penetration (below / within / above, average compa-ratio);
- change counts.

**Privacy:** the small-group rule (`analytics_min_group`, 5, the same threshold as workforce, talent
and learning) applies to every group, to every count, and to the filtered population. A filter leaving
fewer than five people returns nothing but that fact.

**No judgements:** no recommendation, pay-fairness verdict, performance inference or attrition
prediction.

**Export:** CSV export of the compensation in force needs `compensation.export` and
`compensation.view`, and is audited (EXPORT).

## 16. Concurrency (real MySQL, `tests/MySql/CompensationConcurrencyTest.php`)

| # | Race | Protection | Without it |
|---|---|---|---|
| 1 | Two approvers, one change | Change row lock + status re-check | Both approved |
| 2 | Two executions, overlapping dates, one employee | Employee row lock + locking reads of active rows | Overlapping open rows |
| 3 | Two approvals, last of a budget | Budget row lock + running total | Both charged, budget exceeded |
| 4 | Two executors, one cycle | Cycle row lock + status (idempotent) | Second run fails mid-way on line locks |
| 5 | Cancellation vs effective-date processor | Change row lock in both | Both acted (two decisions audited) |
| 6 | Execution vs payroll finalisation | Payroll run lock (same lock order) + fingerprint check at finalisation | Deadlock (audit chain vs payslip FK) every time |

## 17. Known limitations

- **Pay frequency:** Payroll calculates monthly; other pay frequencies are refused until Payroll
  supports them.
- **Structure versions:** they apply to everyone on the structure from their date, and cannot start
  inside any finalized period of the tenant (tenant-wide, conservative).
- **Differencing:** amounts are suppressed below five people, but two filters that differ by fewer than
  five people can still be compared (a differencing attack). Mitigate by granting
  `compensation.analytics` narrowly.
- **Optional workflow:** the generic WorkflowEngine is not used for compensation approvals; the
  four-person chain is enforced in the domain service (as in Phases 5–10).
- **API:** no write API.
- **Range lookup:** for Employee 360 range position it uses the employee's position today.

## 18. Statutory

Unchanged:
- 24 rule versions, 0 verified, 5 open notices;
- the payroll component catalogue (statutory flags) and the statutory engine are not touched.

**Statutory production readiness: NOT DECLARED.**
