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
| 5 | `PayrollCalculator::segments()` and the structure's items | Read (direct table) | Payroll consumer | `CompensationOutput::forPayroll()` (and `forPayrollMany()` for a batch, the same result per employee: payroll readiness, remediation I.8) |
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
  company; a list of grades or every grade; optionally lists of any other position dimension —
  designation, level, department, division, business unit, team, location, employment type,
  employee category, work mode, cost centre — kept in `applicability` as {position column: [ids]}).
- **Applicability match:** `SalaryStructureVersion::mismatch()` checks the employee's position on
  the proposal's effective date. Every narrowed dimension must match (values within one are
  alternatives); a position with no value in a narrowed dimension does not match. The refusal names
  the dimension. The admin picks dimensions in the draft form ("Fix this version to particular …").
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
- **Parameters and formula check (§30):** a version carries `parameters`, named numbers its formulas
  use (`basic_pct => 50`). They are part of its content: copied to a new version, frozen on submit,
  and included in the checksum when present. Creating, saving or submitting a draft runs Payroll's
  `StructureFormulas::check` and refuses the draft on any formula error. Payroll receives the
  parameters on `CompensationSegment::$parameters`. The contract version and fingerprint are
  unchanged, since a version's parameters never change once approved. See payroll-architecture §2.1.
- **Salary structure builder (2026-11-11):** drafts are now built and changed in the five-step builder
  (plain rules, or plain English, compiled to formula overrides in calculation order); it writes the
  numbers into the formulas and saves drafts without parameters. Approved versions that use parameters
  keep them and pay the same. See [salary-structure-builder.md](salary-structure-builder.md).

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

### Salary uploads

**Compensation › Salary uploads** (`SalaryImports`, import type `salaries` on the shared
`employee_imports` staging tables) loads amounts from a CSV file. The file never writes pay. Running it
prepares a draft cycle of type `salary_upload`, and that cycle goes through the same four-person chain.

- **Columns:** employee code, salary structure code and annual CTC (required). Optional: effective
  from, currency, variable target, change type, and one column per fixed-amount, non-statutory
  component (monthly). "Download template" writes every column header. Amounts may use thousands
  separators ("6,00,000").
- **Mapping:** the person mapping the columns chooses the company (one upload = one cycle = one
  company) and a default effective date for rows that have none.
- **New or existing:** at upload (changeable when mapping) the uploader chooses *Assign CTC to new
  employees* (no active salary assignment yet), *Revise CTC of existing employees* (has one) or *Both*.
  A row outside the choice is skipped with the reason (`ImportMode`, see `bulk-uploads.md`).
- **Validation:** runs as the uploader and touches no compensation.
  - Each row passes `CompensationChanges::check` (the same rules as `propose`).
  - Each row also passes the timeline rules `AssignmentWriter` would refuse at execution: employee not
    in the company on the date, another open change, compensation already starting on the date
    (identical → skipped; different → needs change type `correction`), and payroll finalized on or
    after the date.
  - This keeps one stale row from blocking the cycle's all-or-none execution.
  - With no change type given, a row is `hire` when the employee has no earlier compensation, and
    `revision` otherwise.
  - Validation is not wrapped in one transaction, so the payroll-closure locks are not held for the
    whole file.
- **Run:** `CompensationCycles::fromUpload` creates the cycle and one line per row (source `import`)
  under one audit operation. The uploader becomes the preparer. A line a rule refuses is reported on
  its row; a cycle that gets no line is cancelled. An upload cycle is never populated, and
  `CompensationCycles::create` refuses the upload type.
- **Access:** `compensation.cycles` + `compensation.propose`. Each person sees and acts on only the
  files they uploaded, because the rows hold pay (`EmployeeImportPolicy`, `SalaryImportResource`
  query).

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

## 19. Current salary on Employee 360, and revisions on CTC, gross or net pay (2026-11-27)

Owner request (2026-11-27): see the salary structure assigned to an employee with its full breakup and the statutory
details; see at a glance who has a salary and who is left; assign, revise or edit it from the employee; keep a complete,
audited history since joining (when, how often, by how much); and give a hike % (or a new amount) on CTC, gross pay or
net pay and let the structure work the salary out.

**Where things are**

| Place | What it holds |
|---|---|
| Employee 360 → Rewards → **Current salary** (`App\Livewire\Compensation\CurrentSalary`, first of `EmployeeResource::VIEW_LISTS['rewards']`) | Assigned / scheduled / not assigned said once; the salary in force today line by line (earnings, gross, deductions with the read-only statutory estimate, net pay, employer contributions, CTC, variable target); statutory details (applicability, PT state, regime; PAN/UAN/ESI only "on file / missing"); salary checks and pay mode; variable pay plans; the revision analysis. Actions: Assign salary, Revise salary, Edit salary in force, Print breakup. |
| Employee 360 → Rewards → **Salary history** (`CompensationRelationManager`, was "Compensation") | Every approved row with a **Change** column (% and amount against the row before) and a **Compare** slide-over: line by line against the previous salary on the same date, plus the audit trail of the change (proposed, submitted, reviewed, approved, executed, took effect — who and when, reference, reason). The old one-form "Propose compensation change" was removed (the wizard replaces it); "Propose from position" stays. |
| Pay → **Employee salary structures** (`SalaryStructureRegister`) | "Salary assigned" / "Not assigned yet" cards by the salary in force today (each filters the register), request chips (pending / approved / rejected), a **Salary** column; row actions **Assign salary** (never had one) or **Revise salary**, **View salary** (opens Current salary), More → Edit salary in force, Employee 360. The old "Status" chip column is hidden by default. |

**One wizard** (`App\Filament\Support\Compensation\SalaryChangeWizard`), used by both places: Employee statutory →
Salary details → Compare (Breakup for a first salary) → Review (outcome card). Purposes: `assign`, `revise`, `correct`.
"Edit salary in force" is a `correction` on the effective date of the salary in force; the server forces the type and
date whatever the form sends. Every purpose only **proposes** through `SalaryAssignments::propose` →
`CompensationChanges` (review, approval and execution by other people, as before); a change already open blocks another.

**The hike decided on the same page** (owner, 2026-11-28): under the hike % (or the new amount) on Salary details,
`SalaryChangeWizard::impact` → `filament.compensation.salary-change-impact` shows the annual CTC, gross pay a month and
net pay a month as **in force on the effective date → with this change**, the change and its %, with the row the change
is given on (CTC, gross or net) marked. It refreshes as the hike is typed (debounced; the date, basis, mode, amount and
currency fields are live). Before a hike is entered it shows the salary in force alone; a hike that would be refused says
why. The figures come from `previewData` (so they are exactly the Compare step's), with a stand-in reason while Reason —
typed below the hike — is still blank (Next still requires it). `SalaryRevisions::compare` headline carries
`ctc_before` / `gross_before` / `net_before`. A back-dated date adds the arrears line. Compare keeps the line-by-line
breakup.

**Revising on gross or net pay** (`App\Domain\Compensation\Services\SalaryRevisions::resolve`):
- *CTC* on a structure that balances to CTC: passed through to `SalaryAssignments` unchanged (percent or amount).
- *Gross pay* or *net pay* (a month), and *CTC* on a structure built from component amounts: solved. The target is the
  amount given, or the figure in force × (1 + %); the solver searches the CTC (or one scale for the component amounts —
  inputs are quantities and never scale) until the structure's own formulas give the target within 0.50 a month (1.00 a
  year for CTC): proportional and secant steps, then halving a bracket (a cliff such as the ESI ceiling has no exact
  answer; the closest side wins). Net pay is the take-home of the read-only statutory estimate (verified rules only,
  TDS excluded) — without verified rules it is gross less non-statutory deductions, and the screens say so.
- A hike on CTC starts from the CTC **recorded** on the salary in force (not what the same amounts would cost under
  today's statutory rules — the EPF ceiling change of September 2026 makes these differ for component-built structures).
- The CTC percentage of every revision made this way is recorded on the change (`increase_percent`, via
  `recorded_percent` in `SalaryAssignments::proposal`).

**Before → after** (`SalaryRevisions::compare`): both sides on the change's effective date (so only the revision
differs); pay rises are shown as good news, deduction changes neutral. **The salary story** (`SalaryRevisions::history`):
approved rows since joining with change, months since the previous one and the change's actors; analysis = revisions,
increases / reductions, total growth, growth a year (CAGR from the first salary to today or the exit date, after a year),
average and highest hike, last revised and months since, average months between revisions, service; a CTC sparkline.

**Access**: `CurrentSalary::canViewForRecord` = `CompensationAccess::mayViewCompensation`. Full readers see everything;
compensation.team managers see the salary, breakup and analysis (statutory facts only with employee.sensitive.view; no
open requests); the employee is pointed to My compensation (its own switches decide). Opening Current salary is an
audited sensitive read (`SensitiveAccessAuditor`, scope `compensation`, purpose `current_salary`); opening a wizard
records the statutory view as before; Print breakup is audited as an export.

Fixed along the way: the wizard's preview and review read the "Fixed amounts and inputs" field as raw key/value rows
while the form is open, which refused any proposal with a fixed amount ("Component [0] is not part of structure") in the
register's preview; `SalaryChangeWizard::keyValues` normalises it.

**Layout (redesigned the same day, owner: "it looks like a rough note")**: Rewards opens on Current salary — its overview
cards (which only pointed to Current salary and the payslips below) are not shown to whoever sees the panel. The panel is
a pay card (ink to iris, as the Home hero: "Salary assigned" / "Starts …" / "No salary assigned", the annual CTC in large
figures, the month, structure and since-when, the actions, and a bar of where the month's CTC goes: take-home, deductions,
reimbursements, employer contributions), then "Breakup a month" as three boxes (earnings with each line's share of gross,
deductions, employer contributions, each with its total), the month spelt out (gross − deductions = take-home; gross +
employer = CTC a month, with any unallocated remainder named), then Statutory (applicability chips, PT state, regime, IDs
as On file / Missing, the estimate in one sentence per state) beside Checks and payment, then Revisions since joining.
Amounts read in the currency's own grouping (`SalaryFormat`: ₹48,00,000, $4,800,000; paise only when there are any).
Styles: theme.css §X2 (`.pos-paycard`, `.pos-paysplit`, `.pos-paybox`, `.pos-payequation`, `.pos-statchip`, …).

Tests: `tests/Feature/Compensation/CurrentSalaryTest.php` (solver on CTC / gross / net / component amounts, compare,
history and analysis, the panel and its access, revise and edit from Employee 360, the register cards and actions);
updated `CompensationOwnershipTest` (4), `PayrollPagesRenderTest`, `IntelligenceTest` (360 card facts).

## 20. Salary uploads on the annual CTC, and arrears of back-dated changes (2026-11-27/28)

Owner: "what do we upload to give new joiners their first salary — just CTC or gross? Is there a bulk revision upload with an
effective date that pays past-month arrears, and does an individual revision do the same?" Then (2026-11-28): "if CTC
alone is needed and gross is not, just take CTC; add gross only if it is required." Gross is never required — the
structure's formulas work out gross, every component, PF, ESI, PT and take-home from the CTC — so the upload takes the
CTC only. (Structures built from component amounts instead of a CTC have their own upload, *Salary structure (employee
CTC manual)*. The Employee 360 wizard still lets HR give an individual salary on gross or net.)

**Salary upload** (Data import → Payroll → *CTC (employee CTC auto)*, `SalaryImports`, type `salaries`). One row per
employee; modes *Assign CTC to new employees*, *Revise CTC of existing employees* or *Both*. The downloadable starting
file has only Employee code, Salary structure, Annual CTC, Effective from and Reason; the format guide lists the rest.

| Column | Required | Notes |
|---|---|---|
| Employee code | yes | |
| Salary structure | first salary | by name (or code); blank on a revision keeps the structure in force |
| Annual CTC **or** Hike % | yes | a revision gives the new CTC, or a Hike % (−5 for a cut) |
| Hike on | no | CTC, gross or net, row by row; otherwise the upload's **"A Hike % is on"** choice made when mapping (CTC by default) |
| Effective from | yes (or a default date at mapping) | joining date for a new joiner |
| Reason, Currency, Variable target (annual), Change type | no | |
| one column per fixed / input component | no | monthly amount; blank = the structure's default |

Owner, 2026-11-28: "for revisions let the admin decide — a CTC amount, or a hike % on CTC, gross or net — so the impact
is easy to see". The admin picks what a Hike % is on when mapping the columns (a Hike on column differs row by row); the
structure's formulas are solved for the CTC that gives that rise (`SalaryRevisions::resolve`, as the Employee 360 wizard
does). Every revision row then shows how its CTC was reached ("Hike +10% on gross pay → annual CTC …") and its
**impact** — CTC, gross and take-home a month before → after, each with its % change (`SalaryRevisions::compare` on the
effective date; kept on the row as `match.impact`) — before anyone approves. A back-dated row's note also says which
months its arrears cover. Running the upload still prepares a draft compensation cycle (four people decide it).

**Arrears of back-dated changes** (`App\Domain\Payroll\Contracts\SalaryArrearsWriter`, `SalaryArrears`; setting
`payroll.arrears.back_dated`, default on, Setup → Payroll → Payroll run). Before, a change dated in a month whose
payroll was finalised was refused ("pay the difference as an arrear") by `AssignmentWriter` and the upload checks. Now:

1. `AssignmentWriter::write` still locks the payroll runs concerned, and with arrears on accepts the closed date; the
   timeline row keeps its true effective date. Closed entries, lines and payslips are never touched.
2. `CompensationChanges::schedule` (the executor's step, also used by cycle execution and so by uploads) then calls
   `SalaryArrearsWriter::record`: every finalised month of the employee from the date is calculated again by
   `PayrollCalculator` on the timeline as it now stands (the month's own attendance, loss of pay, proration and segments)
   and each **salary-structure** earning / reimbursement is compared with what was paid for it — the entry's own line plus
   arrears or recoveries of earlier back-dated changes for that month (so a later correction pays only the rest).
3. Each difference becomes a `PayrollAdjustment` in the employee's next open payroll month: type `arrear` (with the pay
   item, so its tax, PF and ESI treatment applies there — as the Salary arrear upload does) or `recovery` (a plain
   deduction with no pay item, so PF / ESI wages are never cut; the pay item's code ends the note), named
   "Basic arrear Sep 2026", with `reference_period_id` = the month it is for and source `compensation_change` / change id.
   They follow "payroll adjustments need approval". A month whose arrears cannot be worked out (formula error, no
   structure) refuses the execution with the reason.
4. `PayrollMonthReopen::blocker` refuses to reopen a month whose difference waits as arrears (the new salary would be
   paid twice) until those arrears are deleted on Payroll → Adjustments.

The wizard's Compare step shows the closed months, the paying month and a rough figure (change in gross × months from
the date); its Review says the same. Salary history → Compare lists the arrears a change recorded (month, pay item,
amount, paying month, status). With the setting off, everything behaves as before (refused).

Known limits: PF on arrears is counted in the paying month's PF wages under the rule in force (the separate EPFO arrear
return is not built — see the Data import note on *PF arrear*); an employee who exits before the paying month gets the
adjustment in that month, which the full and final settlement does not read yet. Tests:
`tests/Feature/Compensation/SalaryArrearsTest.php`, `SalaryUploadTest` (CTC-only file, hike % on CTC / gross / net, impact), `CompensationOwnershipTest` #14 and
`PayrollFoundationTest` (refusal with the setting off).
