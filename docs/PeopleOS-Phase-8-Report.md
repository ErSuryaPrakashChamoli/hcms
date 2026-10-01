# PHASE 8 — LEARNING, DEVELOPMENT & SKILLS GROWTH FOUNDATION + CONTROLLED STATUTORY EVIDENCE RECONCILIATION

## Status

```text
Phase 8: COMPLETE
Phase 8 statutory production readiness: NOT DECLARED
```

Stopped for owner review. Phase 9 not started.

## Git

| | |
|---|---|
| Starting HEAD | `d75226e` on `feature/sep_28_phase_1` — owner-approved baseline (the prompt named `94c439f` on `main`; `d75226e` is one owner commit on top of it, changing the demo seed admin) |
| Ending HEAD | the phase-8.7 documentation commit (the commit that adds this report) |
| Branch | `feature/oct_1_phase_1` (the owner created it from the same commit and committed the in-progress 8.1 work as `9bf682f` "updated") |
| Commits | 7 on top of `d75226e`: `9bf682f` (owner commit of the 8.1 discovery, migration and models), then 6 by the agent — `aa6e1d9` 8.2 domain, `5a24820` 8.3 screens / API / security, `b058c42` 8.4 automation, `96a9b54` 8.5 statutory reconciliation, `4fe311a` 8.6 tests, 8.7 docs |
| Pushes | 0 by the agent. The owner pushed `feature/sep_28_phase_1` and `feature/oct_1_phase_1` at `9bf682f`; later commits are local |
| Working tree | clean after the final commit |

## Discovery

`docs/PeopleOS-Phase-8-Discovery.md`. A Learning module already existed (courses, modules, one
assessment per course, paths, rule-based assignments, enrolments, certificates, classroom sessions,
a skills table, Employee 360 tab, daily tick). It was extended, not rebuilt. A rehire reactivates
the same `Employee`, so learning history keyed by `employee_id` is one lifetime record.

Defects found and fixed on the way:
- **Enrolment and certificate lists leaked across teams.** Any user with `learning.assign` — every
  manager — listed every enrolment and certificate in the tenant.
- **Manager visibility used every relationship type.** `EnrolmentPolicy::isReport` used
  `directReports()` (all relationship types, including mentors).
- **Seat counting had no lock.** Seats were counted without a lock, so two learners could take the
  last seat.
- **Repeatable-read version gap.** First use of a legacy course or path could miss a
  just-committed version under REPEATABLE READ; it is now read with a locking read.
- **Mandatory report query error.** The report had an ambiguous `is_mandatory` column (would have
  failed at runtime).

## Architecture

Detailed in `docs/architecture/learning-development-foundation.md`. The layers are: catalogue →
versioned course / path / program → assignment / enrolment → activity and progress → completion
(append-only) → certificate → employee learning record. Skills and development sit alongside, and
the Phase 7 development-need boundary feeds them.

## Learning

| Capability | Delivered |
|---|---|
| Catalogue | Configurable taxonomy: type, category, topic, difficulty, delivery mode (9), language, provider, instructor, cost and currency (costs need `learning.costs`), prerequisites (acyclic), effective dates, self-enrol and approval flags, approval workflow key |
| Course lifecycle | Draft → pending approval → approved (second person, `learning.publish`) → published / scheduled → active → retired → archived |
| Versioning | Immutable course versions with checksum, pinned by enrolments, completions and certificates. A 2027 curriculum never rewrites a 2026 completion. |
| Learning paths | Immutable versions; ordered items with in-path, acyclic prerequisites (enforced when starting); milestones |
| Programs | Versions with dates, rule-engine eligibility, required and optional items, completion rule, program completion and certificate |
| Providers / instructors | Internal L&D and external providers; instructors may be employees (no duplicate person) |
| Assignments | Employee, team (configured relationships), organisation unit, rule population. Each records priority, reason, required / optional, due date and recurrence. Versioned and cancellable; one audited bulk operation with an `operation_id`; processed in chunks. |
| Mandatory learning | Mandatory assignments use rule-engine eligibility (organisation, department, location, role, lifecycle state, employment type, position) plus recurrence; mandatory compliance report; recertification |
| Enrolment | 14 controlled states: assigned, requested, pending approval, approved, enrolled, waitlisted, started, overdue, completed, failed, withdrawn, expired, cancelled, rejected |
| Approvals | Never the learner or requester; manager with `learning.approve` (relationship scope) or L&D; optional approval workflow pinned to its version |
| Capacity | Session row lock; waitlist positions; promotion when a seat frees |
| Progress | Module-based, or learner-reported for self-paced / on-the-job / blended / external delivery; distinct from completion |
| Completion | Append-only record of version, completer, score, grade, attendance, hours, evidence, provider and instructor; corrections are new records |
| Assessments | Graded against the pinned version's questions; attempts immutable |
| Certificates | Random verification code; private HTML document with SHA-256 (queued generation); authorised, audited downloads; external credentials verified by a second person; revocation; expiring → expired; never extended; recertification learning |
| Employee experience | My learning (learning, certificates, skills, assessments, plans) and request learning |
| Manager experience | Team learning (status, mandatory overdue, expiring certificates, approvals, skill gaps, plans) and assign learning |
| L&D administration | Dashboard; Courses, Paths, Programs, Assignments, Enrolments, Sessions, Certificates, Providers, Instructors, Skills, Skill levels, Skill assessments, Development plans |
| Employee 360 | Learning (summary and version), Skills, Certifications, Development tabs |
| Analytics | Enrolment, completion rate, mandatory compliance, learning hours, cost (with permission), certificate expiry, skill gaps, plan completion, course popularity, provider utilisation, by department. Database aggregates; groups below 5 suppressed. |
| Cost | Course, provider, employee, travel and other costs with currency; no payroll or reimbursement link |

## Skills

| | |
|---|---|
| Skill library | Existing `skills` extended with type (7 configurable), description, scale and effective dates |
| Levels | Configurable scales with immutable versions (value, label, description, behavioural indicator); default four-level scale seeded from config |
| Employee skills | Sourced history: self, manager, assessment, certification, learning, imported, system. Verified only for assessment, certification and learning. A self-declaration never overrides a verified level. Pinned to the scale version. |
| Assessments | Self, manager, formal or certification. Draft → finalized under a row lock (immutable); corrections are new assessments that supersede. Encrypted private notes, never shown to the employee; other readers need `skills.private_notes` and are audited. |
| Gaps | Target minus the best-evidenced level, on the pinned scale version; never inferred from job titles |
| Skills gained | Courses declare skill outcomes; a finalized completion records them as verified learning evidence |

## Development

- **Development plans:**
  - Statuses: draft, active, on hold, completed, cancelled, archived.
  - Items: goal, skill gap, learning, milestone, review.
  - Closed plans are read-only history.
  - `lock_version` guards concurrent changes.
  - Private notes are encrypted.
- **Performance integration:**
  - Plans import open Phase 7 development needs through `DevelopmentNeedsReader`.
  - The needs boundary was extended additively: skill, scale version, current / target level,
    states, reason, owner, target date, and new sources (employee, manager, skill assessment, career
    discussion, compliance, organisation).
  - A finalized skill assessment can record a need.
  - Courses are **recommended** from skill outcomes and enrolled only by a person's choice.
  - Learning never reads ratings or appraisals.
- **Milestones:** due-date reminders to the employee and the plan owner.

## Security

| Layer | Result |
|---|---|
| Tenant | Fail-closed tenant scope on every new model. Cross-tenant ids return 404 in the API and fail policies (tests). |
| Organisation | The access scope applies to every employee-linked learning, skill and plan model. Policies also check `AccessScopes::allows` before staff-wide permissions (test with a location-scoped L&D user). |
| Relationship | Manager scope comes only from `PerformanceRelationships` (configured types; mentors excluded — test). The architecture test forbids `directReports()` / `manager_id = me` shortcuts. |
| Field security | Encrypted, hidden private notes (assessments, plans) marked highly sensitive. Costs need `learning.costs` and are classified financial. Certificate codes and document paths are hidden. Evidence is on the private disk. |
| API | Field-filtered: no internal employee ids, evidence, comments, private notes, verification codes or plan summaries; costs need the `learning.costs` scope. |
| IDOR | Ids from another tenant resolve to 404; downloads need a signed URL and pass the policy and tenant resolution. |
| Bulk / admin | Population and unit assignment need `learning.manage`. Catalogue approval needs `learning.publish` and a different person. Costs need `learning.costs`. |
| Permissions added (15) | `learning.team / approve / publish / certificates / costs / analytics`, `skills.view / manage / assess / self / private_notes`, `development.view / manage / own / team`; API scopes `learning.read / write / costs` |

## Audit, events, queues, scheduler, notifications

- **Audit:** every new model is audited, except the documented append-only completion and reminder
  logs, which are audited through enrolment events.
  - Explicit events cover: version published, status changes, completion finalized / corrected,
    certificate issued / downloaded / document attached, request submitted / approved / rejected,
    progress changed, skill assessment finalized / corrected, plan transitions.
  - Bulk assignment carries an operation id. Sensitive fields are masked.
- **Events:** learning, skill and development events carry the tenant-owned subject.
- **Queues:** `SendLearningReminders` and `GenerateCertificateDocument` are `TenantAwareJob` with
  `BindTenantContext`, unique and retry-safe.
- **Scheduler:** the existing `peopleos:learning:tick` was extended (scheduled release,
  recertification), not duplicated. New `peopleos:learning:send-reminders` runs at 07:30. Both use
  `withoutOverlapping()->onOneServer()`.
- **Notifications:** rules first, else in-app — assigned, approved, rejected, due, overdue,
  mandatory overdue (also to the manager), completed, certificate issued / expiring / expired,
  assessment due, milestone due. Throttled by `learning_reminder_logs`.

## Statutory Evidence

Official texts were re-retrieved on 1 Oct 2026 from official domains only: egazette.gov.in,
epfindia / epfo.gov.in, pib.gov.in and incometaxindia.gov.in. The key quotes were re-checked against
the downloaded files. Each document is stored with its SHA-256 and attached to its rule version. The
findings are recorded as an append-only evidence revision. **No rule was verified and no notice was
resolved.**

### EPFO

| | |
|---|---|
| Rule version | EPF v2 (effective 17 Sep 2026); v1 untouched |
| Evidence retrieved | Gazette S.O. 5109(E); EPFO circular E-1345653 (25 Sep 2026, forwards the gazette); EPFO Wage Ceiling FAQs (24 Sep 2026); PIB 2314111 (23 Sep 2026) |
| Gazette status | **Retrieved.** S.O. 5109(E), Ministry of Labour and Employment, 17 Sep 2026: "the Central Government hereby notifies rupees twenty-five thousand (₹25,000) per month as the wage ceiling for the purposes of Chapter III of the said Code [on Social Security, 2020], with effect from the date of publication". It supersedes S.O. 2702(E) of 29 May 2026 (not retrieved). The legal basis is the Code on Social Security, 2020, not the EPF & MP Act, 1952. |
| Parameters verified | None (verification is a second person's act). Now **covered** by the gazette: wage ceiling ₹25,000 and effective date 17 Sep 2026. |
| Parameters still unconfirmed | EPS wage ceiling, EPS rate, employee rate, employer rate, EDLI rate, admin rate and rounding: these appear only in EPFO FAQ illustrations, and the scheme texts were not retrieved. Rounding is not stated anywhere. **Contradicted:** EDLI wage ceiling (v2 carries ₹15,000; FAQ Q5 applies the new ceiling to EDLI) and minimum admin charge (v2 carries ₹75; FAQ Q13: ₹500 with a contributing member). Values were not changed on an explanatory FAQ. 9 coverage gaps (was 10). |
| September 2026 transition | EPFO's FAQs split September by days (1–16 Sep at ₹15,000, 17–30 Sep at ₹25,000; single ECR due 15 Oct) but contradict themselves (Q7 vs the Q9 table). EPFO says ECR instructions "are being issued"; none were found. **PeopleOS payroll applies the version effective on the period end to the whole month, so it does not implement the split.** This is recorded as a blocking notice and deferred: it is an engine change, not evidence maintenance. |
| Employee eligibility / existing vs new members / contribution limits / exceptions | FAQ only: EPS membership for wages ≤ ₹25,000 on joining or on 17 Sep 2026; excluded employees to join from 17 Sep 2026; higher-wage contributors continue; wages as defined in s.2(88). Not in notified text retrieved. |
| Verification status | REVIEW, not verified |
| Blocking notices | EPF v1 wage ceiling (Phase 6); EPF v1 + v2 September treatment (Phase 7, required text); **new** EPF v2 contradictions and the September split-period treatment |
| Second verifier | **Required, not performed.** Needs the scheme texts, S.O. 2702(E) and EPFO's ECR instructions, then a corrected version and resolution of the notices. |

### Income Tax

| | |
|---|---|
| Rule version | TDS v3 (tax year 2026-27, corrects v2) |
| Act transition | ITD FAQ Q6.22–Q6.23 (verbatim): salary for March 2026 paid on 31 Mar 2026 is under the Income-tax Act, 1961, s.192; salary paid from April 2026 is under the Income-tax Act, 2025, s.392(1). The rule is the **date of payment**. The general "earlier of credit or payment" rule (Q6.1) is for non-salary TDS and is not applied to salary. Payroll already resolves salary TDS by payment date (unchanged). |
| Section 392(1) | "at the time of such payment at the average rate of income-tax computed on the basis of the rates in force for the tax year in which the payment is made, on the estimated income of the assessee under this head" |
| Evidence retrieved | Income-tax Act, 2025 (as enacted, Gazette 265620; and as amended by the Finance Act, 2026, ITD); ITD FAQs on Interplay and Transition; Finance Act, 2026 (held) |
| Parameters verified | None. Now **covered**: legal basis (s.392(1); Finance Act 2026 s.3(10)(ii)), deduction trigger (Q6.22), cess 4% (s.3(16)), surcharge (Part III Para F). |
| Parameters still unconfirmed | **New regime:** the values match s.202(1), s.19(1) (₹75,000) and s.156(2) (₹60,000 up to ₹12 lakh, marginal relief). No text was found applying the s.202 rates to s.392 salary TDS (s.2(90) → Finance Act Part III, which carries only the slabs from ₹2.5 lakh and excludes s.202 income only for advance tax), and 80CCD(2) is a 1961-Act section. **Old regime:** slabs, standard deduction (₹50,000) and rebate (₹12,500 up to ₹5 lakh) match; the deduction identifiers and limits and HRA are 1961-Act provisions. **New-regime surcharge cap:** only in the advance-tax table. **Rounding:** not stated in s.392. **Income-tax Rules, 2026** (Form 138 / 130 rules, declarations): not retrieved; ITD Form FAQs only. |
| Corrections | Phase 7 citations "Finance Act s.2(10)(ii)" and "s.2(16)" are s.3(10)(ii) and s.3(16). The Phase 7 deduction-trigger citation used the general rule. Recorded in the new submission; earlier records untouched. |
| Verification status | REVIEW, not verified (3 coverage gaps) |
| Blocking notices | TDS v2 legal basis (Phase 6); **new** TDS v3 open questions (s.202 → s.392 linkage, 1961-Act deduction references, cap). August 2026 payroll is now flagged by it. |
| Second verifier | **Required, not performed.** |

Statutory inventory: 24 rule versions — 3 REVIEW, 21 DRAFT, **0 VERIFIED**; 5 open notices.
`PEOPLEOS_ENFORCE_VERIFIED_RULES`, the production gate and enforcement are unchanged, with no
bypass added. **Statutory production readiness: NOT DECLARED.**

## Tests

| | |
|---|---|
| Tests | 544 (539 passed + 5 skipped); Phase 7 end: 499 |
| Assertions | 5,288 |
| Failures | 0 |
| Skipped | 5 — the MySQL concurrency suite in the default (SQLite) run; it was run separately on MySQL (below) |
| Architecture | 34 PASS (26 existing + 8 learning invariants) |
| Security | PASS — tenant isolation, employee self-service isolation, relationship scope (mentor excluded), organisation scope, certificate download authorisation, private assessment notes, assessment authorisation, catalogue administration, bulk assignment authorisation, API IDOR, API field filtering, sensitive-data protection |
| MySQL concurrency | 5 PASS on real MySQL (forked processes): last seat, first use of a legacy course, completion + certificate, skill assessment finalization, plan transition; audit chain intact afterwards. Removing the session lock makes the seat race fail. |
| Scale | 3 PASS: 240-employee population assignment in chunks (one bulk operation, bounded queries per employee), 300-course catalogue page with constant queries, 60-report team dashboard < 15 queries, mandatory report and skill-gap aggregation < 5 queries over 150 employees, chunked certificate expiry |
| Pint | PASS |
| Migration replay | PASS — fresh `migrate:fresh --seed` on a temporary MySQL database: 232 tables, 92 migrations, 770 foreign keys. Columns, indexes and foreign keys identical to dev except the known drift `tenants.base_currency`. The Phase 8 migrations rolled back and re-applied twice with an identical schema. Temporary databases dropped. |
| Audit chain | PASS — dev: platform 27, demo 587 events; replay database: platform 2, demo 535; MySQL concurrency database: 3 race tenants (192 / 209 / 168 events) written by concurrent processes |

Updated existing tests (behaviour changed on purpose):
- **Session test:** a full session now waitlists instead of refusing.
- **Phase 6–7 statutory expectations:** updated for the new notices, submissions and coverage.
- **Attendance pages test:** clock pinned; it was date-dependent and started failing on 1 October.

## Known Limitations

- **Certificate documents** are HTML; there is no PDF library.
- **Assessments** use a single-choice question format with no question bank.
- **Organisation-unit targeting** does not include child units.
- **Approval workflows** need a workflow with the course's key; otherwise managers or L&D decide
  directly.
- **The EPF September 2026 day-split is not implemented** (the notice blocks; it is an engine
  change that should wait for EPFO's ECR instructions).
- **EPF v2 EDLI ceiling and minimum admin charge** contradict EPFO's FAQs and must be corrected from
  the scheme texts by a verifier.
- **TDS v3 new-regime linkage and 2025-Act deduction references** are unresolved.
- **The MySQL concurrency suite needs an explicit environment variable**; the default suite skips
  it.
- **The repository now holds ~16 MB of official PDFs** as evidence copies.

## Deferred Work

- **Statutory:**
  - obtain the EPF / EPS / EDLI scheme texts, S.O. 2702(E), EPFO's ECR instructions and the
    Income-tax Rules, 2026;
  - get a qualified ruling on the s.202 / s.392 linkage;
  - publish corrected versions;
  - implement the September split-period EPF calculation when authoritative;
  - second-person verification and notice resolution.
- **Learning:**
  - PDF certificates;
  - question banks and randomised assessments;
  - SCORM / xAPI content;
  - an external LMS connector;
  - expense integration for costs (read contract only);
  - a capability model (target skills by role).
- **Excluded by design (future phases):**
  - Succession, talent pools, promotion prediction and replacement planning;
  - Workforce Planning and Compensation;
  - AI employee decisions;
  - RMS integration;
  - production deployment.
