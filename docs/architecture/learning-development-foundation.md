# Learning, Development & Skills Foundation (Phase 8)

For: engineers extending PeopleOS learning, skills and development.

Phase 8 hardens and extends the Learning module from the blueprint build (`phase-11-learning-assets.md`).
Code lives in `app/Domain/Learning`, `app/Domain/Skills` and `app/Domain/Development`.

## 1. Model map

```
LearningProvider ── LearningInstructor (may be an Employee — no second person record)
Course (catalogue, lifecycle) ── CourseVersion (immutable: curriculum, assessment, provider,
   │                                instructor, validity, cost, skill outcomes, checksum)
   ├── LearningPath ── LearningPathVersion (immutable: items pinned to course versions,
   │                                        in-path prerequisites, milestones)
   └── LearningProgram ── LearningProgramVersion (eligibility, required / optional items,
                          completion rule, certificate) ── LearningProgramParticipant
LearningAssignment (employee / team / organisation unit / population; versioned; cancellable)
LearningEnrolment (pins course and path versions; controlled transitions)
   ├── TrainingSessionAttendee (registered / waitlisted, under a session row lock)
   ├── AssessmentAttempt (immutable, graded on the pinned version)
   ├── LearningEvidence (private disk, hashed, reviewed once) · LearningCost (learning.costs)
   └── LearningCompletion (append-only; corrections are new records) ── LearningCertificate
                                                    (verification code, private document,
                                                     revocation, expiry, recertification)
SkillScale ── SkillScaleVersion (immutable levels) ── EmployeeSkill (sourced history)
                                                   └─ SkillAssessment (draft → finalized → superseded)
Phase 7 DevelopmentNeed ── DevelopmentPlan ── DevelopmentPlanItem (goal / skill gap / learning /
                                                                   milestone / review)
```

**One lifetime record.** A rehire reactivates the same `Employee`, and learning, skills and plans
are keyed by `employee_id`, so history survives leaving and returning. No learning identity is ever
created.

## 2. Versioning and history

| Record | Rule |
|---|---|
| Course versions | Publishing snapshots the course; a version never changes or is deleted. Enrolments, completions and certificates pin `course_version_id`, so "what the employee completed" is reproducible after the catalogue changes. Courses published before Phase 8 get an implicit version on first use (under a course row lock, read with a locking read). |
| Path / program / skill-scale versions | Immutable snapshots with checksums; enrolments, participants, skills and assessments pin them. |
| Assignments | Changing target, conditions, due days, recurrence or the mandatory / required flags bumps `version`; enrolments record `assignment_version`. Cancelled assignments are read-only. |
| Completions | Append-only. Finalization locks the enrolment row; `(learning_enrolment_id, sequence)` is unique. A correction is sequence + 1 with `corrects_completion_id` and a reason, and the original becomes `superseded` (its values never change). |
| Certificates | Holder, learning, number and dates never change. An expired credential is never extended: recertification creates new learning, and the new certificate is linked from the old one (`renewed_by_certificate_id`). Revocation needs a reason and is final. |
| Skill history | Entries never change. A new entry supersedes only the current entry of the same skill and **class** (verified, manager, imported, system, self, target), so a self-declaration never overwrites a verified level. |
| Assessments | `finalized` is immutable; a correction is a new assessment that supersedes the original when finalized. |
| Development plans | Transitions are draft → active ⇄ on hold → completed / cancelled → archived. Closed plans and their items are read-only. |

## 3. Lifecycles

- **Course:** draft → pending approval → approved → published / scheduled → active → retired →
  archived. Approval is by a second person with `learning.publish`, never the submitter, when
  `learning.require_catalogue_approval` is on (default). Scheduled courses are released daily.
- **Enrolment:** `LearningEnrolment::TRANSITIONS` covers assigned, requested, pending approval,
  approved, enrolled, waitlisted, in progress (started), overdue, completed, failed, withdrawn,
  expired, cancelled and rejected.
  - `completed` requires a final completion record.
  - Closed enrolments are read-only.
  - Enrolments are never deleted.
- **Program participation:** enrolled → completed / withdrawn. Completion is evaluated from final
  completions: every required item, plus the number of optional items the rule asks for.

## 4. Who may do what

| Action | Rule |
|---|---|
| See learning, skills, plans | Own; the employees one manages through the **configured relationship types** (`PerformanceRelationships`; mentors, buddies and project leads excluded by default); everyone with `learning.view` / `skills.view` / `development.view`, still limited by the user's organisation scope (`AccessScopes::allows`) and tenant. |
| Assign | `learning.manage`: any target. `learning.assign`: employees and the team the user manages. Organisation units and rule populations need `learning.manage`. |
| Approve requests | Never the learner or the requester. Needs `learning.manage`, or `learning.approve` plus a relationship. When the course names an approval workflow, the workflow decides (instance pinned to its published version; `LearningWorkflowBridge`). |
| Progress | The learner, for self-paced / on-the-job / blended / external delivery; L&D and assigners for any. Never completes the learning. |
| Complete / correct | `learning.manage`, or automatically from modules plus a passed assessment, or from attendance. |
| Certificates | Verify, revoke and record external credentials with `learning.certificates`; a holder never verifies their own. Downloads go through a signed URL, a tenant-resolved route and the policy, and are audited. |
| Skills | Self-declarations by the employee (never verified). Manager assessments need `skills.assess` and a relationship. Formal / certification assessments need `skills.manage`. Private notes are readable by the assessor or with `skills.private_notes` (audited), never by the employee. |
| Development plans | The employee (`development.own`), a manager (`development.team` + relationship), or `development.manage`. Private notes are never shown to the employee. |
| Costs | `learning.costs` only; no payroll, salary or reimbursement link. |

## 5. Integration boundaries

- **Performance → Learning.** Learning reads Performance only through `DevelopmentNeedsReader`, the
  `DevelopmentNeeds` service (extended additively with skill, levels, states, owner, target date and
  new sources) and `PerformanceRelationships`. Plans import open needs; courses whose skill
  outcomes match a need are **recommended**, and enrolment happens only when a person chooses it.
  Nothing assigns learning from a rating (architecture test).
- **Never touched:** payroll, compensation and statutory data — in either direction (architecture
  tests). No RMS, recruitment, succession, talent pools or AI decisions.

## 6. Concurrency

| Path | Protection |
|---|---|
| Last seat | Session row `lockForUpdate` before counting seats; overflow is waitlisted; cancellation promotes the first waiter under the same lock. |
| Completion / certificate | Enrolment row lock + unique sequence; one certificate per completion. |
| First use of a legacy course or path | Course / path row lock, re-check, locking read of the version (REPEATABLE READ safe). |
| Skill assessment finalization, plan transitions, assignment cancellation, request decisions | Row lock + state re-check (+ `lock_version` for plans). |
| Bulk assignment | One audited bulk operation (`operation_id` on the assignment and every audit event); employees are processed with `chunkById`. |

`tests/MySql/LearningConcurrencyTest.php` forks real processes against a disposable MySQL database
(`PEOPLEOS_MYSQL_CONCURRENCY_DB=hcm_…_concurrency php artisan test tests/MySql`). The SQLite suite
skips it and does not claim MySQL lock semantics. Removing the session lock makes the seat race fail.

## 7. API, events, automation

- **API:** `/api/v1/learning/*` with scopes `learning.read`, `learning.write` and `learning.costs`.
  - Read endpoints: catalogue, courses and versions, learning paths, programs, enrolments,
    assignments, completions, certificates, skills, employee skills, assessments, development plans
    and analytics.
  - Writes: enrol, which is idempotent (one open enrolment per course), and set progress (an
    absolute value).
  - Employees are identified only by code.
  - Never returned: evidence, comments, private notes, verification codes, document paths or plan
    summaries. Costs need `learning.costs`.
- **Events** (`LearningEvent`, `SkillEvent`, `DevelopmentEvent`) carry the tenant-owned subject:
  - **learning:** assigned, enrolment requested / approved / rejected / cancelled, started,
    completed, failed, certificate issued / expiring / expired / revoked, program enrolled /
    completed, reminders;
  - **skill:** recorded, assessed, assessment reminder;
  - **development:** plan created / completed, milestone due.

  Notifications run rules first, otherwise in-app. Webhooks cover a curated subset, with redacted
  context.
- **Jobs:** `SendLearningReminders` and `GenerateCertificateDocument` are `TenantAwareJob` with
  `BindTenantContext`, unique and retry-safe.
- **Scheduler:**
  - `peopleos:learning:tick` (03:00) handles assignments, overdue, due soon, certificate expiry,
    recertification and scheduled releases.
  - `peopleos:learning:send-reminders` (07:30) is throttled through `learning_reminder_logs`.
  - Both run with `withoutOverlapping()->onOneServer()`.
- **Analytics** (`LearningAnalytics`):
  - Covers enrolment, completion rate, mandatory compliance, learning hours, certificates, popular
    courses, providers, skill gaps, plans, departments and costs (with permission).
  - All database aggregates; groups below `learning.analytics_min_group` (5) are suppressed.

## 8. Known limitations

- **Certificate documents** are rendered as HTML (no PDF library is installed).
- **Assessments** are single-choice JSON questions; there is no question bank or randomised pools.
- **Workflow-driven approval** needs a workflow configured with the course's key; otherwise managers
  or L&D decide directly.
- **Organisation-unit targeting** matches the unit on the employee's current position. It does not
  include child units.
- **Skill targets** are recorded per employee. There is no capability model by role (deliberately:
  no inference from job titles).
- **Learning costs** are recorded; there is no expense module integration.
