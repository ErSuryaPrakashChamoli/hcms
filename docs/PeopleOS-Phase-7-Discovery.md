# PeopleOS Phase 7 — Discovery

- **Date:** 28 September 2026 · **Baseline:** `main` at `69eb7d1`, clean, 40 ahead / 0 behind, 86 migrations.
- **Scope:** Performance, Goals & Continuous Performance hardening, plus two controlled statutory
  rule updates (EPFO wage ceiling; salary TDS under the Income-tax Act, 2025).

## 1. Performance — what already exists (kept, not rebuilt)

| Area | Existing | Location |
|---|---|---|
| Cycles | `performance_cycles` (type, period, rating scale, stages, weights, settings, competencies, eligibility, status draft/active/closed, current stage); launch, advance stage, close | `Appraisals` |
| Rating scales | `rating_scales` (levels json, default) | `RatingScale` |
| Competencies | `competencies` (category, description, indicators) | `Competency` |
| KRA / KPI library | `kras` with KPIs | `Kra` |
| Goals / OKRs | `goals` (level company…employee, org node, parent, cycle, type goal/okr/kra/kpi, measure, start/target/current, weight, progress, status, dates, lock), `key_results` | `Goals` |
| Goal progress | `goal_check_ins` (value, progress, confidence, note) | `Goals::checkIn` |
| Reviews | `appraisals` (+ scores, calibrated / final rating, flags, acknowledgement), `appraisal_reviews` (self / manager / peer / upward / skip / external, anonymous flag), `appraisal_ratings` | `Appraisals` |
| Calibration | `Appraisals::calibrate()` + Calibration Board page (distribution) | `CalibrationBoard` |
| Feedback | `feedback_entries` (praise / constructive / request, visibility private / manager / public) | `Feedback` |
| One-on-ones | `one_on_ones` (agenda, notes, action items) | Filament resource |
| PIPs | `improvement_plans` (objectives, extend, close) | `ImprovementPlans` |
| Career | career paths, steps, aspirations, Career Passport | `CareerPassport` |
| UX | 11 Filament resources, My Team, Calibration Board | `app/Filament` |
| API | `GET /api/v1/performance/{appraisals,goals}` (scope `performance.read`) | `ReadController` |
| Events | `PerformanceEvent` (named) → webhook bridge | |
| Tests | AppraisalTest (5), GoalsTest (2) | `tests/Feature/Performance` |

## 2. Performance — gaps against the brief

| Brief | Gap | Decision |
|---|---|---|
| §15, §23, §26, §41 versioning | No templates; a cycle's rating scale, stages and competencies are live references; rating scales can be edited after use | Versioned **templates** (sections, rating-scale snapshot, competency snapshot, workflow stages, weights). A launched cycle pins a template version; its configuration is frozen; appraisals pin the version; rating scales in use cannot change |
| §14 cycle states | draft / active / closed only | Add *scheduled* and *archived*; Open / In progress / Review / Calibration are the active cycle's pinned stages |
| §17 weighting | Weight stored, never validated; no circularity, date or ownership checks | Configurable required total; negative, duplicate, circular, date and ownership validation |
| §18 progress history | `goal_check_ins` lacks previous value / source and is mutable | Previous value, previous progress, source, measurement; immutable |
| §19 check-ins | Only goal check-ins; no employee ↔ manager conversation | `performance_check_ins` (went well, blockers, support, priorities, feedback, actions, cadence) |
| §20 one-on-one privacy | One `notes` field, visible to the employee | Shared notes + encrypted manager-private notes, access audited |
| §21 anonymity | Feedback has no anonymous option | Anonymous feedback: author hidden from everyone without `performance.anonymous_identity` |
| §25, §35 relationship scope | `EmployeeOwnedPolicy::isReport` treats every reporting relationship (mentor, buddy…) as a manager | Configurable `performance.manager_relationship_types`; one resolver used by policies, pages and services |
| §26 workflow pinning / locking | Appraisals follow the cycle's live stages; closed appraisals editable | Pinned via the template version; appraisals lock when the cycle closes |
| §27 calibration | Calibrated rating overwritten, no history, no session | Calibration sessions; immutable adjustments (original, adjusted, reason, actor), optimistic concurrency |
| §28 PIP | No checkpoints; states not enforced by a transition map | Checkpoints; explicit transition map (draft → active → extended → completed / unsuccessful; cancelled; closed) |
| §29–30 boundaries | None | `development_needs` (read by a future Learning module); read-only `PerformanceOutcomes` contract for Compensation; no writes to payroll |
| §31 Employee 360 | No performance section | Permission-filtered performance tab |
| §32 analytics | Rating distribution only | Completion / progress / activity analytics with small-group suppression |
| §34 API | Two read endpoints | Cycles, goals, goal progress (idempotent write), reviews, check-ins, feedback, one-on-ones, competencies, PIPs, analytics — masked |
| §38–39 reminders | None | Tenant-aware scheduled reminders, de-duplicated per subject per day |
| §40 concurrency | No row locks | Row locks and state re-checks on review submit / finalize / calibrate / PIP close / cycle close |

## 3. Statutory — current state (Phase 6, authoritative)

22 rule versions (0 verified), 5 layouts (0 verified), open notices on EPF v1 (wage ceiling from
17 Sep 2026) and TDS v2 (legal basis for tax year 2026-27). Production gate, maker-checker,
evidence documents and coverage all in place. Nothing here changes those controls.

## 4. Statutory — evidence retrieved in Phase 7 (official sources)

- **Income Tax Department, "TDS Compliance FAQs"** (incometax.gov.in): salary for FY 2025-26 paid up
  to March 2026 → section 192 of the 1961 Act; salary paid from April 2026 → **section 392(1)** of
  the Income-tax Act, 2025; employers reset the TDS computation from 1 April 2026; the governing Act
  follows the date of payment or credit.
- **The Finance Act, 2026 (No. 4 of 2026)**, Gazette CG-DL-E-31032026-271439, assent 30 March 2026:
  salary TDS under section 392 at the rates in **Part III of the First Schedule**; Paragraph A slabs
  (₹2.5 lakh nil, 5%, 20%, 30%); Paragraph F surcharge (10% / 15% / 25% / 37%); Health and Education
  Cess 4%. The **new-regime** slabs, rebate, standard deduction and deduction limits sit in the
  Income-tax Act, 2025 itself (section 202 and others), which was not retrieved. Note: the eGazette
  server's TLS chain is incomplete; the file was retrieved without chain verification and its
  SHA-256 recorded.
- **EPFO / Ministry of Labour & Employment:** PIB releases (16 and 23 Sep 2026) — wage ceiling and
  pensionable wage ceiling ₹25,000 from 17 Sep 2026, citing Gazette Notification S.O. 5109(E). The
  gazette text was not found.

## 5. Statutory decisions

- **EPF v2**, effective 17 Sep 2026, DRAFT: wage ceiling and EPS wage ceiling ₹25,000; other
  parameters carried forward and marked **not confirmed**. A new notice blocks verification of v1 and
  v2 until the September 2026 intra-month treatment is established. v1 is untouched.
- **TDS v3** (tax year 2026-27, Income-tax Act 2025, section 392(1)), corrects v2, effective
  1 Apr 2026, DRAFT → REVIEW on the Finance Act evidence: old-regime slabs, surcharge and cess
  covered; new-regime and deduction parameters **not confirmed** (Act text not retrieved) — so
  it cannot be verified.
- **Payment-date trigger:** payroll periods gain a payment date; TDS resolves its rule, tax year,
  year-to-date and ledger by the payment date (not the earning month). Engine version `payroll-2.2`.
- Nothing is verified, no notice is resolved automatically, no readiness is declared.

## 6. Boundaries

Performance never writes payroll, salary, statutory or compensation data; the statutory engine
never reads performance. No RMS, AI scoring, ranking or automated decisions.
