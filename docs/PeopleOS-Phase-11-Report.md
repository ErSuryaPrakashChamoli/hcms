# PHASE 11 — COMPENSATION & REWARDS FOUNDATION

## Status

```text
Phase 11: COMPLETE
Statutory production readiness: NOT DECLARED
PeopleOS production readiness: NOT DECLARED
```

Stopped for owner review. Phase 12 has not been started.

## 1–5. Baseline and Git

| | |
|---|---|
| 1. Starting HEAD | `49eff96` on `feature/oct_1_phase_1` (Phase 10 end; baseline verified: clean tree, no discrepancy) |
| 2. Ending HEAD | the phase-11.6 documentation commit (the commit that adds this report) |
| 3. Branch | `feature/oct_1_phase_1` |
| Working tree | clean at the start; clean after the final commit |
| 4. Commits | 6 on top of `49eff96`:<br>• `c52f8ed` 11.1 Compensation owns employee compensation assignment (ownership refactor, change engine, contract)<br>• `566e8cc` 11.2 versioned structures and grade pay ranges<br>• `8b75b53` 11.3 budgets, bulk cycles, planning integration<br>• `8fda92b` 11.4 access, self-service, analytics, API, notifications<br>• `6743630` 11.5 hardening and validation: architecture invariants, scale tests, migration rollback fixes, and the closeout defect fixes (§19)<br>• 11.6 documentation and this report |
| 5. Pushes | 0. No remote branch was changed, and no history was rewritten. |

## 6. Discovery findings

Recorded in `docs/architecture/compensation.md` §1–§2 (18-point discovery map and caller map).

**What existed before Phase 11:**
- **Payroll owned employee salary assignment.** `employee_salary_assignments` (effective-dated,
  CTC-based, monthly component values) was written by `Payroll\Services\Salaries::assign`, which:
  - had no approval boundary;
  - was reachable from Employee 360 with `payroll.manage`;
  - **deleted every later-dated row** whenever an earlier change was made.
- **Direct reads:**
  - the payroll calculator read the table directly, together with the structure's current
    (mutable, unversioned) items;
  - Letters, F&F, two AI services, the Payroll control room and the analytics dataset also read the
    table or `Salaries`.
- **Grades** had no pay ranges.
- **Performance** already exposed `PerformanceOutcomesReader`, a finalized-outcome contract meant for
  a future Compensation module.

**Stop and decision.** That was the prompt's §49 stop condition, so implementation stopped and the
reconciliation was escalated. The owner chose **Option B**:
- `employee_salary_assignments` stays the single canonical history;
- Compensation becomes its domain owner and sole write authority;
- Payroll and every other module read through contracts;
- no second salary table.

## 7. Architecture decisions

| Decision | Why |
|---|---|
| Move `EmployeeSalaryAssignment`, `SalaryStructure`, `SalaryStructureComponent` to `App\Domain\Compensation` (same tables). Keep audit history visible via `auditEntityAliases()` and morph aliases | Ownership without a parallel table. Audit rows are hash-chained and are never rewritten |
| Replace `Salaries` with an `@internal` `AssignmentWriter` that only executes an approved `CompensationChange`, plus a model guard | No uncontrolled write path remains (architecture test: one writer, one caller) |
| Write the canonical row at **scheduling** (executor); the effective-date processor marks it Effective | Future compensation must be visible to `getCompensation(date)` and to Payroll before the processor runs (see §10) |
| Structures become effective-dated versions reusing `salary_structure_components` as version lines | §6 immutability and historical recalculation without a parallel component table |
| Payroll component catalogue stays in Payroll | Statutory treatment (taxable, PF / ESI, statutory flags) belongs to Payroll / Statutory (§7) |
| Budget enforcement through a running total on the locked budget row | A locking read over change rows deadlocked two approvers on MySQL (race 3) |
| Cycle lines are ordinary compensation changes | One controlled path for single and bulk changes; per-employee audit for free |
| Domain-service separation of duties, not the generic WorkflowEngine | The established PeopleOS pattern (Phases 5–10). Four distinct people are enforced in the service and by a MySQL CHECK |

## 8. Database changes

All additive except one unique key widened on `salary_structure_components` (no data removed).

| Migration | Content |
|---|---|
| `2026_10_11_100001_compensation_ownership` | `compensation_changes` (proposal, previous snapshot, career references, four duties, lock_version).<br>`employee_salary_assignments` + lineage `compensation_change_id`, `status` (active / superseded / cancelled), `active_key`, `superseded_by_id`, `pay_frequency`, `variable_target_annual`, `approved_by` / `approved_at`; unique active start date.<br>MySQL CHECKs: amounts, dates, status, separation of duties. |
| `…100002_compensation_structures_and_ranges` | `salary_structure_versions`; version lines on `salary_structure_components` (+ `pay_nature`, `frequency`; version 1 backfilled since 2000-01-01).<br>`compensation_ranges` with CHECKs (min ≤ mid ≤ max, ≥ 0, dates, SOD). |
| `…100003_compensation_cycles_and_budgets` | `compensation_budgets` (running `charged_amount` ≤ amount), `compensation_cycles` (SOD CHECK); `compensation_changes` + cycle / budget / rating / % (unique per cycle and employee). |
| `…100004_compensation_reminder_logs` | Reminder throttling. |

**Dev:** 5 existing assignments became active rows; the STANDARD structure became version 1.
**Payroll engine** moved to `payroll-2.3`, so earlier draft runs are recalculated before finalisation
(the existing rule).

## 9. Compensation model

| Concept | Record |
|---|---|
| Definition | `salary_structures` → `salary_structure_versions` (draft → pending approval → scheduled / active → superseded, or archived) → lines (Payroll component + override + fixed / variable + frequency) |
| Range | `compensation_ranges`: per grade, optionally narrowed to structure / company / job family / designation; min / mid / max; configurable range model |
| Assignment | `employee_salary_assignments`: canonical effective-dated rows |
| Change | `compensation_changes`: types are configurable (annual increment, promotion, market adjustment, correction, allowance change, role / grade adjustment, retention, transfer, rehire, hire, revision, other) |
| Payroll consumption | `CompensationOutput` |

## 10. Effective dating

- **Dates:** inclusive.
- **A revision from D:**
  - closes the previous row on D − 1;
  - **keeps** any later row: the new row ends the day before it. `Salaries::assign` used to delete it.
- **Same start date:** refused, unless the change is a correction, which supersedes the row (kept).
- **Cancellation:** a cancelled scheduled change leaves a cancelled row (kept), and the timeline closes
  around it.
- **Database backstop:** a unique active start per employee.
- **History:** every date resolves to exactly one row.
- **Lifecycle eligibility** is configured explicitly (current / future / cycles / correction after
  exit).
- **Rehire** keeps the same record and all history.
- **Tests:**
  - the §11 example (Apr / Jul / Jan executed out of order);
  - the §12 boundary example;
  - correction history.

## 11. Approval / SOD

draft → submitted → under review → approved → scheduled → effective; rejected, returned and cancelled
along the way.

- **Four people:** proposer ≠ reviewer ≠ approver ≠ executor, enforced in the service and by a MySQL
  CHECK. Nobody acts on their own compensation.
- **Stale approvals** are refused: the compensation in force on the date must still be the one
  submitted.
- **Cycles:** the same rule for preparer, reviewer, approver and executor.
- **Structures, ranges and budgets:** the preparer never approves.

## 12. Payroll contract

`CompensationOutput` (`compensation-output-1`):
- `forPayroll` gives segments per compensation row × structure version, with that version's
  components;
- the other methods are `fingerprints`, `on`, `history`, `coveredEmployeeIds` and `annualCtcOn`.

**What Payroll can and cannot see:** it never sees draft, submitted, rejected, cancelled or
approved-but-unexecuted compensation.

**Payroll `payroll-2.3`:**
- amounts, proration and statutory treatment are unchanged;
- each entry records the contract version and a fingerprint;
- `finalize` refuses an entry whose compensation or structure version changed since calculation.

**`PayrollClosureReader`** (owned by Payroll) answers "closed on or after" with the run lock.

**Result:** Payroll does not reference any Compensation model or writer (architecture test).

## 13. Workforce integration

- **Plan pricing:** a plan version can be priced at the applicable ranges (read-only; unpriced lines
  reported).
- **Propose from position:** creates a draft change from the position's range, with no movement.
- **Budgets:** compensation budgets are a distinct, declared basis (annualised CTC increase); a
  Phase 10 workforce budget can be referenced.
- **Never written:** positions, plans, scenarios and workforce budgets (architecture test).
- **Performance:** finalized outcomes only, through `PerformanceOutcomesReader`, and only to prefill
  cycle lines.

## 14. Employee 360

- **Compensation tab** (replaces the Phase 4 "Salary" tab):
  - current, history, scheduled and superseded / cancelled rows (full readers);
  - component breakdown, and range position / compa-ratio (full readers);
  - "Propose compensation change" and "Propose from position".
  - **"Assign / revise salary" is gone.**
- **Compensation changes tab:** the approval chain for full readers and duty holders.
- **Read-only views:** managers (`compensation.team`) see approved rows only; employees use the
  My compensation page.
- **Audited:** opening either view.

## 15. Security

| Area | Result (tests) |
|---|---|
| Tenant isolation | Another tenant's compensation is invisible and cannot be proposed; API codes return 404 |
| Organisation scope | Reads and every step (propose, review, …) refused outside scope |
| Relationship scope | Configured manager types only; mentor / buddy / project grant nothing |
| Field security | Salary hidden without `compensation.view` (bank / statutory and payroll permissions grant none); analytics CTC field gated |
| Self-service | Own approved compensation only, behind the tenant setting; never proposals, reasons, notes or others' pay |
| API IDOR | Cross-tenant codes 404; amounts need `compensation.sensitive` (403 otherwise) |
| SOD | Every duty pair refused; nobody acts on their own pay |
| Payroll boundary | Direct writes refused; payroll / statutory configuration fingerprint unchanged by any compensation step |
| Performance / Career / Workforce boundaries | No writes (architecture scans); position history unchanged by proposals |
| RMS | No RecruitmentEdge / RMS reference |

## 16. API

`/api/v1/compensation`, read-only:
- **`compensation.read`:** structures (approved versions and components), grades, ranges (`?on=`),
  cycles.
- **Plus `compensation.sensitive`:** `employees/{code}` (`?on=`) and `employees/{code}/history`.
  Every read is audited.

**Write APIs are deferred:** they cannot yet meet the four-person chain.

## 17. Audit / events

- **Audit:** the existing hash-chained audit, with new actions REVIEWED, SCHEDULED, EFFECTED and
  CORRECTED, plus SUBMITTED, APPROVED, REJECTED, CANCELLED, SALARY_CHANGED, BULK_OPERATION (cycle
  execution, operation id), EXPORT and VIEW.
- **Masking:** amounts, component values, reasons and internal notes are masked. The closeout found
  that the canonical row's `reason` was not masked; it is fixed in 11.5, with a regression test.
- **Events:** `CompensationEvent` (changes, structures, ranges, cycles, reminders) never carries amounts.
- **Notifications:** in-app, to the chain only.
- **Webhooks:** allow-listed only.
- **Kept for integrations:** `employee.salary_changed`.

## 18. Queue / scheduler

| Job | Command | Schedule |
|---|---|---|
| `EffectDueCompensation` (makes scheduled changes effective and scheduled structure versions active) | `peopleos:compensation:effect` | 00:20 |
| `SendCompensationReminders` (throttled reminders) | `peopleos:compensation:send-reminders` | 08:15 |

- **Both jobs:** TenantAwareJob + BindTenantContext, unique per tenant, idempotent.
- **Both schedules:** `withoutOverlapping()->onOneServer()`.
- **Bulk execution:** one transaction and one operation id, idempotent.

## 19. Closeout validation

All results below are from the final code; nothing was reported from an intermediate run.

### Defects found and fixed during closeout (Category A, Phase 11)

| Found by | Defect | Fix |
|---|---|---|
| Full suite | The existing rule "every domain model is audited unless documented as append-only / derived" failed for the new `CompensationReminderLog` | Documented it with the Phase 7–10 reminder logs (same derived de-duplication table) |
| Full suite (earlier run) | A test helper name `appFilesMatching()` collided with the existing architecture test, so the suite could not load | Renamed the Phase 11 helpers |
| Audit inspection (replay database) | The canonical row's `reason` was written to audit unmasked | `reason` added to `EmployeeSalaryAssignment::auditSensitiveAttributes()`; regression test checks that the CREATE audit masks amounts, components and reason |
| Code sweep | A docblock in `PromoteEmployeeAction` still named the removed `Salaries::assign` | Docblock corrected (comment only) |
| Migration replay | MySQL silently let the new unique keys take over two implicit foreign-key indexes, so `down()` failed | `down()` restores / drops in order; `up()` converges a rolled-back database to the same indexes |

No Category B (unrelated) or Category C (environment) failure remained.

### Full test suite (`php artisan test`)

```text
Tests:      700
Passed:     677
Skipped:     23   (MySQL-only concurrency tests; run against MySQL below)
Failed:       0
Assertions: 7,077
Duration:   1,310 s (SQLite in memory)
```

**Skipped tests:** the 23 MySQL-only concurrency tests. They skip under SQLite and are run separately
below against real MySQL.

### Phase 11 tests

| File | Tests |
|---|---|
| `tests/Feature/Compensation/CompensationOwnershipTest.php` | 16: ownership regression, items 1–14 of the owner's list, cancellation and the effective-date processor |
| `CompensationStructuresAndRangesTest.php` | 7 |
| `CompensationCyclesAndBudgetsTest.php` | 4 |
| `CompensationAccessAndIntegrationTest.php` | 7 |
| `CompensationScaleTest.php` | 2 |
| `tests/Feature/Admin/CompensationPagesRenderTest.php` | 2 |
| `tests/Feature/Architecture/CompensationInvariantsTest.php` | 12 (15 invariants plus the contract boundary) |
| `tests/MySql/CompensationConcurrencyTest.php` | 7 (6 races plus the audit chain) |

### Compensation ownership: reference sweep

> employee_salary_assignments remains the single canonical physical assignment history, but
> Compensation is its domain owner and sole write authority. Payroll and other modules consume
> compensation through controlled read contracts.

The decision holds in code, as follows.

| Reference | Classification |
|---|---|
| `AssignmentWriter` (create / update rows) | **Compensation write**: the only writer. An architecture test proves that it is the only file creating rows and that only `CompensationChanges` calls it |
| `EmployeeSalaryAssignment` model guard | Refuses create / update outside the writer, every delete, and any change to amounts, structure, currency or start date |
| `CompensationLedger`, `CompensationChanges` (in-force reads), `CompensationCycles`, `CompensationPlanning`, `CompensationAnalytics`, `CompensationSnapshot`, `CompensationPolicy` | Compensation read |
| `PayrollCalculator`, `PayrollRuns` (fingerprint), `PayrollControlRoom` | Payroll read, through `CompensationOutput` only (no Compensation model or writer referenced) |
| `Letters::context` | Letters read (`CompensationOutput::on`) |
| `FinalSettlements` | Exit / F&F read (`CompensationOutput::on` and the calculator) |
| `Employee::salaryAssignments()`, `CompensationRelationManager`, `CompensationChangesRelationManager` | Employee 360 (read; "Propose" creates a draft change only) |
| `CompensationController` | API (read-only, via `CompensationOutput`) |
| `CompensationAnalyticsPage` export | Filament (read, audited) |
| `PayrollAnomalyDetector`, `AttritionRisk`, `EmployeesDataset` | Other (AI / analytics) read, through `CompensationOutput` |
| Migrations (`create_payroll_tables`, `2026_10_11_10000x`) | Schema |
| `tests/Pest.php` `compensate()` and the test files | Test / support: run the real four-person flow |
| "salary assignment" strings in `PayrollCalculator`, `FinalSettlements` and `config/peopleos.php` | User-facing labels (payroll exception texts) |

### Legacy direct writer

`Payroll\Services\Salaries` is **removed**; its writing logic was **internalized** as
`Compensation\Services\AssignmentWriter` (`@internal`).

- **Internal only:** the writer accepts only an approved `CompensationChange` locked by its caller.
- **No bypass path:** no remaining code path writes salary without the four-person approval chain:
  - Employee 360 offers "Propose" only, and the "Assign / revise salary" action is gone (tested);
  - Payroll, Letters, Exit and AI reference no Compensation model or writer (architecture test);
  - the API is read-only;
  - Filament actions call `CompensationChanges` / `CompensationCycles` / `CompensationStructures` /
    `CompensationRanges` only;
  - direct model writes are refused by the guard (tested).
- **No deletion:** future-dated compensation is never deleted.

### Future-dated compensation regression

`CompensationOwnershipTest` "5":
1. 600,000 from 01-Apr-2026 is in place.
2. 720,000 from 01-Jan-2027 is executed **first**.
3. 660,000 from 01-Jul-2026 is executed afterwards.

**Result:** three active rows. The January row is unchanged (same id, amounts, dates and status), and
the July row ends on 31-Dec-2026.

**Reconstruction:**
- past date (01-May-2026): 600,000;
- current date (21-Sep-2026): 660,000;
- future date (01-Feb-2027): 720,000.

A further proposal dated after the future row changes nothing until approved and executed.

Test "6" covers correction history (the superseded row is kept), and test "7" covers inclusive
boundaries and overlap refusal.

### Architecture

`tests/Feature/Architecture`: 63 / 63 PASS (816 assertions). This covers the general architecture test and the Phase 7–11 invariant files.

**Phase 11 invariants proven:**
- Compensation owns salary-assignment writes; Payroll is a consumer;
- no duplicate salary-assignment domain;
- no statutory-configuration writes; no payroll run or finalisation writes;
- no Performance writes; no implicit Position / plan writes;
- no RMS dependency; no automatic employment decision;
- queued jobs carry the tenant;
- sensitive fields are classified and permission-controlled.

### Security

The security set (tenant isolation, access scope, compliance security / isolation, learning / talent /
workforce security, API, audit, and the Phase 11 access and ownership tests): 169 / 169 PASS (1,765 assertions); the architecture suite on its own: 63 / 63 PASS (816 assertions).

| Check | Result |
|---|---|
| Tenant isolation | Tenant A cannot read or propose for tenant B; API returns 404 across tenants |
| Organisation scope | Out-of-scope reads and every workflow step refused |
| Manager scope | Configured manager relationships only (mentor refused) |
| Self-service | Own approved compensation only (not a colleague's, not proposals or reasons) |
| Sensitive fields | Hidden without `compensation.view` (including from bank / statutory and payroll permission holders) |
| Approval SOD | Every duty pair refused; nobody acts on their own pay |
| API | Amounts need `compensation.sensitive`; no cross-tenant leak |

### Pint

`./vendor/bin/pint --test`: PASS.

### MySQL concurrency (real MySQL, forked processes)

Full concurrency suite (Phases 8–11), two consecutive runs:

| Run | Result | Assertions |
|---|---|---|
| Run 1 | 23 / 23 PASS | 81 |
| Run 2 | 23 / 23 PASS | 82 |

After the 11.5 commit, the 7 Phase 11 concurrency tests were run again on the committed code: 7 / 7 PASS, before and after the lock-removal proofs.

The assertion count differs by one because race 2 legitimately allows one or two non-overlapping
executions to succeed; the invariant assertions are the same.

### Lock-removal proofs

With the implementation as committed, each race passes. Each lock was then removed in turn, the race
run, and the file restored from git:

| Race | Lock removed | Result |
|---|---|---|
| 1 approval | Change-row lock | Expected failure: both approvals succeed |
| 2 overlap | Employee lock + locking reads | Expected failure: overlapping rows |
| 3 budget | Budget-row lock | Expected failure: both charged |
| 4 bulk execution | Cycle-row lock | Expected failure: second run fails mid-way instead of a clean no-op |
| 5 cancel vs effect | Change-row locks | Expected failure: two decisions audited |
| 6 execution vs payroll finalisation | Payroll-run lock | Expected failure: MySQL deadlock every time (audit chain vs payslip FK). This is the expected lock-removal outcome, not a product failure |

**Deadlocks found and fixed while building the races:**
- the closure check's join locked `payroll_entries`, so it now locks run rows only;
- the writer locked the employee before the payroll runs, so the order now matches finalisation (run,
  then employee via the payslip FK);
- the budget check read other change rows with a lock, so a running total on the budget row replaced
  it.

### Migration replay

**PASS** on `hcm_phase11_replay`, with the final code:
- fresh migrate + seed (the seeder creates compensation through the four-person flow);
- then the four Phase 11 migrations rolled back, re-applied, rolled back and re-applied;
- after each rollback no Phase 11 table or column remains, and the 5 salary rows and 4 structure lines
  are intact.

**Compared with dev:**
- identical: 269 tables, all columns, all indexes, 447 unique keys, 1,025 foreign keys, 23 CHECK
  constraints (definitions compared), 327 tenant-leading indexes, generated (default) columns;
- the only difference is `tenants.base_currency`, the stray dev column documented since Phase 4 and
  deliberately left alone.

**Statutory state** on the seeded replay: 24 / 0 verified.

**Temporary databases:** the replay, scratch and concurrency databases created for this phase were
dropped after validation.

### Audit chain

| Database | Result |
|---|---|
| Development | PASS (platform 40, demo 587 events) |
| Replay | PASS (platform 2, demo 625 events) |
| Concurrency | PASS (24 tenants incl. platform, 0 problems) |

- **Tenant boundaries:** no compensation audit event belongs to a tenant other than its change's (490
  events checked).
- **Bulk operations:** each cycle execution has one BULK_OPERATION summary, with its line events
  sharing the operation id (one tenant).
- **Before / after:** recorded for every status step; amounts, components, reasons and internal notes
  masked (`••••`).
- **Masking on the final code:** the replay database was re-seeded with the final code after the 11.5
  fix. All 35 sensitive compensation values in its audit log are flagged and stored as `••••`.

### Failed jobs

0 (development and replay databases).

### Scale

SQLite in-memory; query counts measured during the closeout. Timings are indicative only, not
production claims.

| Operation | Dataset | Queries | Time |
|---|---|---|---|
| `forPayroll` + `history` | 1 row vs 10 rows (3 segments in the period) | 7 vs 7 | 4.2 vs 6.4 ms |
| `fingerprints` | 1 vs 14 employees | 2 vs 2 | 1.1 vs 1.9 ms |
| Analytics summary | 14 vs 44 people | 8 vs 8 | 4.5 vs 5.4 ms |
| Change queue page of 25 | 53 changes | 9 | 7.3 ms |

**No N+1:** query counts are constant as data grows (asserted in `CompensationScaleTest`).

**EXPLAIN on MySQL dev:** the payroll segment query uses `salary_assignments_active_start_unique`
(range), and budget and processor queries use `comp_changes_status_index`. Dev data is small, so the
plans are indicative.

## 20. Statutory status

```text
24 statutory rule versions
0 verified
5 open notices
UNCHANGED
```

- **Untouched in Phase 11:** EPFO, TDS, ESI and PT / LWF rules, the statutory production gate, the
  statutory engine and the payroll component catalogue's statutory flags.
- **Nothing inferred:** no evidence was marked verified and no statutory parameter was inferred.
- **The engine bump** (`payroll-2.3`) changes the compensation input path only; the statutory lines
  are identical (existing statutory tests pass unchanged apart from the engine-version assertion).

**Statutory production readiness: NOT DECLARED.**

## 21. Known limitations

- **Pay frequency:** only monthly pay (what Payroll calculates); other pay frequencies are refused.
- **Structure versions:** they apply to everyone on the structure from their date. They cannot start
  inside any finalized payroll period of the tenant (tenant-wide, conservative).
- **Analytics differencing:** the small-group rule suppresses each group and the filtered population,
  but two filters differing by fewer than five people can still be compared. Grant
  `compensation.analytics` narrowly.
- **Employee 360 range position** uses the employee's position today.
- **Corrections:** a correction replaces only compensation that has taken effect; a scheduled change is
  cancelled and proposed again.
- **Audit-chain deadlocks:** the pre-existing Phase 8 risk of concurrent writers deadlocking on the
  hash chain remains (no automatic retry). It did not occur in the final runs.
- **Static analysis:** none is configured in this repository (no PHPStan / Larastan / Psalm); `php -l`
  and Pint were run.
- **Pre-Phase-11 audit rows** of salary assignments (Phase 4, module `payroll`) keep their original
  unmasked reason text; the hash chain forbids rewriting them.

## 22. Deferred work

**Phase 11 improvements** (not started; need approval):
- compensation write APIs (with the same four-person chain);
- optional WorkflowEngine integration for compensation approvals;
- variable-pay / bonus payout, one-time awards and non-monthly pay frequencies (Payroll support first);
- currency conversion in analytics (amounts are reported per currency only).

**Statutory blockers** (carried from Phases 5–10; not Phase 11 work; untouched):
- 0 of 24 rule versions verified; 5 open regulatory notices;
- EPF v2 evidence gaps (EDLI ceiling contradicted, EPS 8.33% vs 8⅓% wording, admin rate / minimum not
  notified);
- the September-2026 EPF day split is not implemented in payroll;
- TDS v3 evidence gaps (3).

## 23. Production readiness

```text
PeopleOS production readiness: NOT DECLARED
Statutory production readiness: NOT DECLARED
```

Phase 11 passing its tests does not make PeopleOS production-ready. Before any production declaration:
- the statutory blockers above must be resolved by verification;
- a parallel payroll run and owner review are required.

## 24. Deviations from the prompt

- **§49 stop:** taken at discovery. The design follows the owner's Option B answer, not the prompt's
  default assumptions.
- **When the canonical row is written:** at scheduling, not on the effective date (reason in §7 and
  in the architecture doc §7).
- **Payroll component catalogue:** stays in Payroll. "Compensation components" are the version lines'
  compensation attributes (§7: statutory treatment stays with Payroll).
- **Changed existing tests** (necessary consequences, not weakening):
  - the payroll / Employee 360 render test asserts "Propose compensation change" instead of the
    removed "Assign / revise salary";
  - two engine-version assertions changed `payroll-2.2` → `payroll-2.3`;
  - an overtime test now adds its component through an approved structure version instead of editing
    a live structure;
  - eight direct `Salaries::assign` calls in tests now run the real four-person flow
    (`compensate()` helper).
- **Workflow:** the generic WorkflowEngine is not used; the established domain-service SOD pattern is.
- **API writes:** none; deferred.
- **Commit grouping:** 6 commits following the suggested 11.1–11.6 grouping, with 11.1 being the ownership refactor the owner asked to land (with its tests) before the rest.
