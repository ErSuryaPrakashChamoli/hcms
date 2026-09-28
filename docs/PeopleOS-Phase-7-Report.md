# PHASE 7 — PERFORMANCE, GOALS & CONTINUOUS PERFORMANCE FOUNDATION + CONTROLLED EPFO / INCOME-TAX RULE UPDATES

## 1. STATUS

Implementation complete. Statutory production readiness **NOT DECLARED**. Stopped for owner
review; Phase 8 not started.

## 2. GIT

| | |
|---|---|
| Starting HEAD | `69eb7d1` (Phase 6 end; clean, 40 ahead / 0 behind) |
| Ending HEAD | the phase-7.7 documentation commit (the commit that adds this report) |
| Branch | `main` |
| Commits | 9 (phase-7.1 × 2, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7 × 2) |
| Pushes | 0 |
| Working tree | clean after the final commit |

| Commit | Scope |
|---|---|
| `68ec1dd` phase-7.1 | EPF v2, TDS v3, notice, payment-date trigger, evidence store, rule identity hardening |
| `6e34c13` phase-7.1 fix | evidence files byte-exact (`.gitattributes -text`) |
| `53ae8f1` phase-7.2 | templates, pinned cycles and appraisals, cycle states, locks, relationship scope |
| `c3165ed` phase-7.3 | goal validation, weight totals, optimistic updates, immutable progress history |
| `e2b0152` phase-7.4 | check-ins, private one-on-one notes, anonymous feedback |
| `1ce7506` phase-7.5 | calibration sessions and history, PIP lifecycle and checkpoints, Learning / Compensation read contracts |
| `43b3ba8` phase-7.6 | API, events, webhooks, reminders, analytics, Filament screens |
| `e5d8325` phase-7.7 | architecture invariants, data classification, reversible migrations |
| phase-7.7 docs | architecture doc, verification guide update, this report |

## 3. PERFORMANCE

| Area | Delivered |
|---|---|
| Cycles | Draft → Scheduled → Active (Open / In progress / Review / Calibration are its pinned stages) → Closed → Archived. A scheduled or launched cycle's configuration is frozen; an archived cycle is read-only. |
| Templates | `performance_templates` + immutable `performance_template_versions` (sections, rating-scale snapshot, competency snapshot, workflow, weights, goal rules, SHA-256 checksum). Publish from Filament. |
| Pinning | Launch pins the chosen version (it must match the cycle; `applyTo` aligns a draft) or an implicit one. Cycles and appraisals store the version id. Scoring, labels and distributions read the pinned scale. Scales used by a launched cycle keep their levels. |
| Reviews | Self and manager reviews unchanged in flow; row locks and re-checks on submit, calibrate, finalize and close. Closing locks every appraisal, review and rating. |
| Manager scope | `PerformanceRelationships` from configured reporting-relationship types (default line, functional, dotted, secondary, hrbp; not mentor, buddy or project). Used by policy, services and screens; architecture test forbids shortcuts. |
| Competencies | Level, weight and effective dates (validated). |
| Employee 360 | Performance tab adds template version and lock, check-in activity, open development needs and, for authorised viewers only, PIP status. |
| Analytics | Completion, goal progress, check-in activity, rating distribution per department; groups below 5 suppressed. |

## 4. GOALS

- Company → business → department → team → individual alignment; a goal aligns only to its own level
  or above. No self or circular alignment.
- No negative weights; progress 0–100; due date not before start date; duplicate titles per owner and
  cycle refused.
- Ownership: own goals; reports' goals with `performance.team` via configured relationships;
  organisation goals need `performance.manage`.
- Weight total is configurable (template goal rules or the cycle's `required_goal_weight`). Nothing
  is hard-coded to 100%. `planStatus` and `submitPlan` enforce it.
- Optimistic concurrency (`lock_version`) on edit; locked-for-review goals keep their definition.
- Progress history is append-only: old / new value and progress, measurement, comment, source
  (manual, check-in, API, import, system), confidence, actor and time. API writes are idempotent per
  goal and `Idempotency-Key`.

## 5. CONTINUOUS PERFORMANCE

- **Check-ins:** weekly, biweekly, monthly or custom. They record what went well, blockers, support
  needed, priorities, goal progress (flows into goal history), feedback both ways and actions.
  - The manager response is limited to configured managers.
  - Reviewed check-ins are read-only.
- **One-on-ones:** shared notes plus encrypted manager-private notes.
  - The employee never sees the private notes.
  - Others need `performance.private_notes`, and every read is audited.
- **Feedback:** named or anonymous; private, manager or public visibility.
  - The anonymous author is hidden everywhere.
  - Revealing the author needs `performance.anonymous_identity` plus a reason, and is audited.
- **Reminders:** reviews due, check-ins awaiting a manager, PIP checkpoints.
  - Each subject is reminded at most once per day.
  - The reminder job is tenant-bound and unique.
  - It is scheduled with `withoutOverlapping()->onOneServer()`.

## 6. CALIBRATION / PIP

- **Calibration:**
  - Sessions carry a facilitator, participants and a population.
  - The append-only history records the original, previous and adjusted rating, reason, actor and
    time.
  - Stale ratings are refused (the expected rating must match).
  - Closed sessions are read-only.
  - There is no ranking, forced distribution or automation.
- **PIP:**
  - Statuses: Draft, Active, Extended, Successfully completed, Unsuccessful, Closed, Cancelled
    (legacy Withdrawn is kept for existing rows).
  - Transitions follow an explicit map, and final states are read-only.
  - Checkpoints must fall inside the plan period and are reviewed once.
  - `lock_version` guards concurrent changes.
  - Every change is audited and recorded on the timeline.
- **Boundaries:**
  - `DevelopmentNeedsReader` serves a future Learning module.
  - `PerformanceOutcomesReader` serves a future Compensation module: finalized outcomes only,
    read-only.
  - Performance writes nothing outside its own tables.

## 7. SECURITY

- **Policies:**
  - Templates use `PerformanceConfigPolicy`.
  - Check-ins and development needs use `EmployeeOwnedPolicy` with the configured relationships.
  - Calibration uses `CalibrationPolicy` (`performance.calibrate`; never deleted).
- **New permissions (synced):** `performance.checkins`, `performance.pip`,
  `performance.analytics`, `performance.private_notes`, `performance.anonymous_identity`.
- **API scope:** `performance.write` for progress writes.
- **The API never returns:**
  - review, feedback, check-in or one-on-one text, or private notes;
  - calibration notes or history;
  - anonymous authors;
  - PIP reasons, objectives or outcomes;
  - unfinalized ratings.
- **Webhooks:**
  - Payloads drop author names and free text.
  - Calibration and PIP events are never sent.
- **Data classification:**
  - `OneOnOne.private_notes` is highly sensitive (encrypted and masked).
  - Feedback, check-ins, calibration and PIP checkpoints are confidential.
- **Architecture invariants (8 new):**
  - no payroll, salary, compensation or statutory writes from performance;
  - payroll and compliance never read performance;
  - no RMS or recruitment concepts;
  - no automated ranking, promotion, termination or PIP;
  - relationship-resolver scope only;
  - tenant-bound jobs;
  - no sensitive API or webhook fields;
  - immutable history and pinned scales.

## 8. STATUTORY CHANGES

**EPFO**

| | |
|---|---|
| Old ceiling | ₹15,000 (EPF v1, effective 1 Sep 2014, untouched) |
| New ceiling | ₹25,000 wage ceiling and EPS wage ceiling |
| Effective date | 17 Sep 2026 |
| Rule version | EPF v2 (new version; v1 not overwritten) |
| Evidence | PIB releases of 16 Sep 2026 (PDF) and 23 Sep 2026 (HTML), stored with SHA-256 — corroborating only; Gazette S.O. 5109(E) text not obtained |
| Verification status | REVIEW, 10 coverage gaps, **not verified**. EDLI, admin charges, rounding and eligibility carried from v1 and marked NOT CONFIRMED (not inferred) |
| September 2026 treatment | **Blocked.** Open notice on v1 and v2: "EPFO wage ceiling changed effective 17-Sep-2026. Exact intra-month September payroll treatment requires authoritative implementation evidence before verification." Payroll resolves by period end, so a September 2026 period uses v2 and is flagged; enforcement blocks finalization. |

**Income tax**

| | |
|---|---|
| Old Act | Income-tax Act, 1961 (s.192) — salary paid on or before 31 Mar 2026 (TDS v1, FY 2025-26) |
| New Act | Income-tax Act, 2025 — salary paid on or after 1 Apr 2026 |
| Effective date | 1 Apr 2026 (tax year 2026-27) |
| Salary TDS section | 392(1) |
| Rule version | TDS v3 (corrects v2) |
| Evidence | Income Tax Department "TDS Compliance FAQs"; the Finance Act, 2026 (No. 4 of 2026), Gazette CG-DL-E-31032026-271439 — both stored with SHA-256 |
| Verification status | REVIEW, **not verified**. Covered: legal basis, payment-date trigger, cess 4%, surcharge rates. Not confirmed: `old` (standard deduction, rebate and limits are in the 2025 Act, not retrieved), `new` (s.202, not retrieved), new-regime surcharge cap |

The payment date is the trigger: payroll periods gain `payment_date` (defaulting to the period end).
TDS resolves its rule, tax year, months remaining, year-to-date and ledger month by it (engine
`payroll-2.2`). The rule reference exposed on payroll carries `rule_version_id`, `rule_code`,
`effective_from/to`, `verification_status`, `evidence_reference` and `calculation_basis`.

Statutory inventory: 24 rule versions — 3 REVIEW (ESI v1, EPF v2, TDS v3), 21 DRAFT, **0
VERIFIED**. There are 3 open notices, and 4 evidence documents stored.

## 9. STATUTORY PRODUCTION READINESS: NOT DECLARED

No rule, layout, establishment or registration was verified. No notice was resolved automatically.
The production gate and enforcement are unchanged. Passing tests are not statutory verification.

## 10. TESTS

| Measure | Result |
|---|---|
| Tests | 499 (Phase 6 end: 465) |
| Assertions | 4,825 |
| Failures | 0 |
| Architecture | 26 PASS (18 existing + 8 performance invariants) |
| Security | Performance: private notes, anonymity reveal, API masking and write scope, relationship scope, analytics suppression, locked appraisals; plus the Phase 6 compliance security suites — all PASS |
| Pint | PASS |
| Migration replay | PASS — `migrate:fresh --seed` on a temporary MySQL database: 215 tables, 90 migrations. Columns and indexes identical to dev except the pre-existing dev drift `tenants.base_currency`. The four Phase 7 migrations rolled back and re-applied twice with an identical schema. The temporary database was dropped. |
| Audit verification | PASS — platform 15 events, demo 582 events |

New suites:
- `StatutoryUpdatesTest` (7, TDS cases A–F and EPF transitions);
- `PerformanceFoundationTest` (4);
- `GoalsTest` (+4);
- `ContinuousPerformanceTest` (4);
- `CalibrationAndPipTest` (3);
- `PerformanceApiTest` (3);
- `PerformancePagesRenderTest` (+1);
- `PerformanceInvariantsTest` (8).

## 11. KNOWN LIMITATIONS

- **EPF v2 and TDS v3 cannot be verified yet.** They need the S.O. 5109(E) gazette text and the
  Income-tax Act, 2025 text.
- **September 2026 EPF treatment is not established.** Payroll uses the rule effective on the
  period end.
- **The audit trail shows who created anonymous feedback.** It records the acting user, so readers
  with `audit.view` can see it (the author id value is masked). Restrict `audit.view`.
- **Calibration sessions** have no invitations or minutes.
- **Analytics** groups by department only and has no trends.
- **Row locks** are exercised on MySQL only; SQLite tests do not take them.
- **Carried from earlier phases:**
  - split-month statutory attribution;
  - EPS eligibility, the EPF 10% option and the ESI disability limit are not modelled;
  - export layouts are unverified.

## 12. DEFERRED WORK

- **Statutory:** obtain the authoritative texts, then second-person verification of EPF v2 and TDS
  v3, and resolution of the September notice by a platform administrator.
- **Out of scope for Phase 7 (not started):**
  - Learning, Compensation, Succession and Workforce Planning;
  - AI performance decisions;
  - RMS integration;
  - production deployment.
- **Possible later performance work:**
  - calibration meeting workflow;
  - 360 invitations;
  - analytics trends;
  - template sections rendered in the review form.
