# PeopleOS Phase 8 — Discovery

- **Date:** 28 September 2026.
- **Baseline:** owner-approved `feature/sep_28_phase_1` at `d75226e`. That commit changes the demo seed
  admin and is one commit on top of Phase 7's `94c439f`. `origin/main` = `94c439f`; the tree is clean
  and there are 90 migrations.
- **Scope:** Learning, Development & Skills Growth foundation, plus reconciliation of the open
  EPF / TDS statutory evidence.

## 1. What already exists (kept and extended, not rebuilt)

| Area | Existing (blueprint Phase 11, `app/Domain/Learning`) | Gap against the Phase 8 brief |
|---|---|---|
| Courses | `courses` (type, category, duration, mandatory flag, validity, pass mark, attempts, owner, status draft / published / retired), `course_modules`, one `assessments` row per course (JSON questions, server-side grading) | No versions; no provider, instructor, delivery mode, language, cost, prerequisites or effective dates; no approval lifecycle |
| Learning paths | `learning_paths` + `learning_path_courses` (order, required) | No versions, prerequisites or milestones |
| Programs | — | Missing |
| Providers / instructors | `training_sessions.trainer_id` / `trainer_name` only | Missing |
| Assignments | `learning_assignments` (course or path, employee or rule-engine conditions, due days, recurrence, mandatory, auto-enrol new joiners) | No target type (team, organisation unit), priority, reason, required/optional, operation id, cancellation or version |
| Enrolments | `learning_enrolments` (enrolled / in_progress / completed / failed / overdue / expired / withdrawn, progress, score, attempts, due, expiry) | No transition map, request / approval, waitlist, course version, lock version or cancellation |
| Sessions | `training_sessions` (capacity) + `training_session_attendees` | Capacity checked without a lock (race on the last seat); no waitlist |
| Completion | Written onto the enrolment row | No separate immutable completion record, corrections, grade, attendance, evidence or hours |
| Certificates | `learning_certificates` (number, issue, expiry, status valid / expiring / expired) | No revocation, verification code, private document, audited download, issuer or recertification |
| Assessments | `assessment_attempts` (not audited) | Attempts are not immutable |
| Skills | `skills` (name, code, category) and `person_skills` (free-text proficiency per person) | No skill type, description or effective dates; no scales, levels, sourced employee skill history, assessments or gaps |
| Development | Phase 7 `development_needs` + `DevelopmentNeedsReader` | No skill, current / desired state, owner or target date; no development plans |
| Security | `EnrolmentPolicy::isReport` uses `directReports()` (every relationship type) | Must use the relationship resolver |
| UI | Courses, paths, assignments, enrolments, certificates, sessions and skills resources; Employee 360 Learning tab | No providers, instructors, programs, skills or development screens; no L&D dashboard |
| API / automation | No learning API; `peopleos:learning:tick` daily (assign, overdue, due soon, expiry) | API, reminder throttling and tenant-bound jobs |
| Tests | `LearningTest` (4), pages render test | — |

**Identity.** A rehire reactivates the same `Employee` (`RehireEmployeeAction`), and one person has one
employee record, so learning history keyed by `employee_id` is a lifetime record. No new learning
identity is created.

**Approvals.** The existing `WorkflowEngine` pins each instance to the published workflow version.
Enrolment approval uses it when a course names a workflow key, following the ServiceDesk and Leave
bridge pattern; otherwise approval is direct, by a manager or L&D user.

## 2. Decisions

| Brief | Decision |
|---|---|
| §7–10 catalogue and versioning | Extend `courses` with the catalogue fields. Add immutable `course_versions` (curriculum and assessment snapshot, provider, instructor, validity, pass mark, cost, checksum). Lifecycle: draft → pending approval → approved (by someone else) → scheduled / published → active → retired → archived. Enrolments, completions and certificates pin `course_version_id`. Legacy published courses get an implicit v1 on first use. |
| §11 paths | Immutable `learning_path_versions`. Prerequisites between path items are checked for cycles, and starting a course requires its prerequisites to be completed. Milestones live on the path. |
| §12 programs | `learning_programs` + immutable `learning_program_versions` (dates, rule-engine eligibility, required and optional items, completion rule, certificate) + participants. |
| §13–14 providers, instructors | New tables. An instructor may reference an existing employee; no duplicate person is created. |
| §15–19 skills | Extend `skills` (type, description, dates, scale). Versioned `skill_scales`. `employee_skills` is effective-dated history keeping its source and verification. `skill_assessments` go draft → finalized; a correction is a new assessment, and private notes are encrypted. Gaps are computed on the pinned scale version. `person_skills` stays as the résumé-style self record. |
| §20–21 development | Extend Phase 7 `development_needs` additively (skill, current / desired state, owner, target date, new sources). New `development_plans` + items; completed plans are read-only. |
| §26–29 assignment, enrolment, approval, capacity | Extend assignments (target type, priority, reason, required, operation id, version, cancellation). Controlled enrolment transitions. No self-approval; manager scope comes from `PerformanceRelationships`. The session row is locked for last-seat registration, with a waitlist. |
| §30–34 progress, completion, certificates | Progress stays separate from completion. `learning_completions` is append-only with correction records. Certificates get revocation, a random verification code, a private document and an authorised, audited download; no silent extension. |
| §40 cost | `learning_costs`, visible only with `learning.costs`; no payroll link. |
| §41 API | `/api/v1/learning/*`, scopes `learning.read` / `learning.write`, idempotent progress writes, field filtering. |
| §45–47 automation | Tenant-bound unique jobs; reminders throttled to once per subject per day (`learning_reminder_logs`); the existing scheduler entry is extended, not duplicated. |
| §52–57 statutory | Evidence reconciliation only. Official sources are retrieved again; nothing is verified by the implementer, and the gate is untouched. |

## 3. Boundaries

- Learning reads Performance only through `DevelopmentNeedsReader` and the development-need boundary.
- It never touches appraisals, ratings, goals, payroll, compensation or statutory tables.
- No automatic course assignment from ratings; no RMS, succession, promotion or AI decisions.
