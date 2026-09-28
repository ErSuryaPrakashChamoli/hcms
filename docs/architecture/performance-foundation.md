# Performance Foundation (Phase 7)

For: engineers extending PeopleOS performance, goals and continuous performance.

Phase 7 hardens the performance module built earlier (see `phase-10-performance-talent.md`); it does
not replace it. Code lives in `app/Domain/Performance`.

## 1. Model map

```
PerformanceTemplate ── PerformanceTemplateVersion (immutable: sections, rating-scale snapshot,
        │                competency snapshot, workflow, weights, goal rules, checksum)
        ▼ pinned at launch
PerformanceCycle (draft → scheduled → active → closed → archived)
        └─ Appraisal (pins the version; locked_at when the cycle closes)
              ├─ AppraisalReview ─ AppraisalRating        (frozen once locked)
              └─ CalibrationAdjustment (append-only) ◄── CalibrationSession (population, participants)
Goal (cascade company → business → department → team → employee; lock_version)
  └─ GoalCheckIn (append-only progress history: previous/new value and progress, source, measurement)
PerformanceCheckIn (employee ↔ manager, per cadence period)
OneOnOne (+ encrypted private_notes) · FeedbackEntry (+ is_anonymous)
ImprovementPlan (transition map, lock_version) └─ ImprovementPlanCheckpoint
DevelopmentNeed (boundary for Learning) · PerformanceReminderLog (one reminder per subject per day)
```

## 2. Versioning and pinning

- `PerformanceTemplates::publish()` snapshots the rating scale levels and the chosen competencies,
  so later edits to the libraries never change a review that used the version.
- `Appraisals::launch()` pins a version: the cycle's chosen version (which must match the cycle's
  stages, scale, competencies and weights; `applyTo()` aligns a draft cycle) or an implicit version
  built from the cycle configuration. The cycle and every appraisal store
  `performance_template_version_id`.
- Scores, ranges, labels and distributions read `PerformanceCycle::ratingScale()`, which returns the
  pinned snapshot once launched. A rating scale used by a launched cycle cannot change its levels.
- A scheduled or launched cycle cannot change period, scale, stages, weights, competencies,
  eligibility, settings, type or template version. An archived cycle is read-only.

## 3. Manager scope

`PerformanceRelationships` is the only answer to "whom does this person manage for performance?".
It reads current reporting relationships whose type is in
`peopleos.performance.manager_relationship_types` (default `line, functional, dotted, secondary,
hrbp`; mentors, buddies and project leads excluded). `EmployeeOwnedPolicy::isReport`, the goal,
check-in, PIP, feedback and development-need services, and the Filament resources use it. An
architecture test forbids `->directReports()` and `where('manager_id', me)` shortcuts in
performance code.

## 4. Goals

| Rule | Where |
|---|---|
| Weight ≥ 0; progress 0–100; due ≥ start | `Goal` saving guard (every write path) |
| No self or circular alignment; align only to the same level or above | `Goal::assertAlignment()` |
| Locked-for-review goals keep their definition | `Goal::LOCKED_FIELDS` guard |
| Duplicate title per owner and cycle refused | `Goals::assertNotDuplicate()` |
| Required weight total from the template's goal rules or the cycle's `required_goal_weight` setting; nothing hard-coded | `Goals::requiredWeight()`, `assertWithinWeightTotal()`, `submitPlan()` |
| Ownership: own goals, or managed reports with `performance.team`; organisation goals need `performance.manage` | `Goals::assertOwnership()` |
| Optimistic concurrency | `lock_version`; `Goals::update($goal, $attrs, $expectedVersion)`; the Filament edit page passes the loaded version |
| Progress only via `Goals::checkIn()`; history immutable; row lock per goal; API idempotency key | `Goals::checkIn()`, `GoalCheckIn` guard |

## 5. Continuous performance

- **Check-ins:** `PerformanceCheckIns::save()` (employee, one per cadence period: weekly, biweekly
  anchored on 1 Jan 2024, monthly, custom). Submitted goal progress becomes goal history with source
  `check_in`. `respond()` is limited to someone who manages the employee; reviewed check-ins are
  read-only.
- **One-on-ones:** `private_notes` is encrypted, hidden from serialization and masked in audit. It
  is written only by the manager who holds the meeting, never readable by the employee, and readable
  by others only with `performance.private_notes`; each such read is audited
  (`OneOnOnes::privateNotesFor()`).
- **Feedback:** anonymous entries keep `author_id` for moderation but hide it from serialization,
  labels, notifications and webhooks. `Feedback::authorFor()` reveals it only with
  `performance.anonymous_identity` and a reason, and audits the reveal. Author, recipient and
  anonymity cannot change.

## 6. Reviews, calibration and PIPs

- Closing a cycle (row lock) locks every appraisal; reviews and ratings of a locked appraisal cannot
  be saved or deleted. Submit, calibrate and finalize take a row lock and re-check the stored state.
- `Calibrations::adjust()` records original, previous and adjusted rating, reason, actor and time
  (append-only), refuses a stale expected rating, and respects the session population and status.
  `Appraisals::calibrate()` delegates to it. There is no ranking, forced distribution or automation.
- `ImprovementPlan::TRANSITIONS`: draft → active → extended → completed (successfully) / unsuccessful
  → closed; draft / active / extended → cancelled. Final states are read-only; `lock_version` guards
  concurrent changes; checkpoints fall inside the plan period and are reviewed once.

## 7. Boundaries

- `DevelopmentNeedsReader` (read-only) is for a future Learning module; no courses or enrolments
  live in performance.
- `PerformanceOutcomesReader` (read-only) returns finalized outcomes only, for a future
  Compensation module. Performance never writes payroll, salary, statutory or compensation data, and
  payroll and compliance never read performance (architecture tests).
- No AI scoring, ranking, promotion, termination or PIP decisions.

## 8. Platform

- **API** `/api/v1/performance/*` (scope `performance.read`): cycles, goals, goal progress,
  reviews (ratings only after finalization), check-ins, one-on-ones, feedback, competencies and PIPs
  (metadata only), and analytics. `POST goals/{id}/progress` needs `performance.write` and honours
  `Idempotency-Key` (≤ 64 characters; unique per goal).
- **Events** (`PerformanceEvent` names): `cycle.published`, `goal.created`, `goal.progress_updated`,
  `review.submitted`, `appraisal.finalized`, `feedback.received`, `check_in.submitted/reviewed`,
  `calibration.decision_recorded`, `pip.opened/completed`, `reminder.*`. Webhooks carry only the
  names in `enterprise.webhook_events` and drop `enterprise.webhook_redacted_context`. Calibration
  and PIP events are never webhooks.
- **Reminders:** `peopleos:performance:reminders` (daily 07:00,
  `withoutOverlapping()->onOneServer()`, `--queue` dispatches the tenant-bound unique
  `SendPerformanceReminders` job). `performance_reminder_logs` makes each run idempotent.
- **Analytics:** `PerformanceAnalytics::summary()` groups by department and suppresses any group
  smaller than `performance.analytics_min_group` (default 5). Rating distributions appear only when at
  least that many appraisals are final.
- **Permissions added:** `performance.checkins`, `performance.pip`, `performance.analytics`,
  `performance.private_notes`, `performance.anonymous_identity`; API scope `performance.write`.
- **Screens:** Templates, Check-ins, Calibration sessions, Development needs, Performance analytics;
  schedule and archive actions on cycles; PIP activate, checkpoint and close-out actions; Employee
  360 performance summary.

## 9. Known limitations

- The audit trail records the acting user, so an audit reader with `audit.view` can see who created
  an anonymous feedback entry (the author id itself is masked). The audit chain must stay complete,
  so this is by design; restrict `audit.view` accordingly.
- Calibration sessions do not yet invite participants or record meeting minutes.
- The analytics grouping is by department only; there is no trend history yet.
- SQLite (tests) does not exercise row locks; the lock paths are exercised on MySQL only.
