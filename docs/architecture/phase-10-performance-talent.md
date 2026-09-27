# Phase 10 — Performance and Talent

Blueprint §34–§36, §121 Phase 10. Built 2026-09-27.

## What exists

**Domain: `App\Domain\Performance`**

| Model | Purpose |
|---|---|
| `RatingScale` | Configurable ordered levels (value, label, description); one default per tenant. `labelFor()` maps any rating to the nearest level. |
| `Competency` | Library entry with category and behavioural indicators. |
| `Kra` | KRA library entry with suggested KPIs and default weight. |
| `PerformanceCycle` | Period, scale, ordered stages (`goal_setting, self_review, manager_review, peer_review, calibration, final`) with windows, goals/competencies weights, rated competency ids, rule-engine eligibility. `draft → active → closed`. Period, scale, weights and competencies are immutable once launched. |
| `Goal` + `KeyResult` + `GoalCheckIn` | Goal cascade (`company → business → department → team → employee`) via `parent_id`; org-level goals carry an `organisation_node_id`. Key results roll up by weight; check-ins are append-only. `is_locked` once reviewing starts. |
| `Appraisal` + `AppraisalReview` + `AppraisalRating` | One appraisal per employee per cycle; reviews of type self / manager / peer / upward / skip_level / external; per-goal and per-competency ratings; goal score, competency score, computed, calibrated and final rating with label; promotion / PIP recommendation. |
| `FeedbackEntry` | Praise, constructive, or request (answered by a reply linked via `parent_id`); visibility private / manager / public. |
| `OneOnOne` | Scheduled check-in with agenda, private notes and action items. |
| `ImprovementPlan` | PIP with objectives, extension and outcome; one active per employee. |
| `CareerPath` + `CareerPathStep` + `CareerAspiration` | Ladders of designations with required skills; the employee's target role and interests. |

**Services**

- `Goals` — `create` (with key results), `checkIn`, `recompute` (weighted roll-up, bubbles to parents that have no key results of their own), `close`, `lockForCycle`.
- `Appraisals` — `eligible` (rule engine on `EmployeeRuleContext`), `launch` (appraisal + self/manager reviews per employee), `advance` (stage transitions with side effects: goal lock at review start, review-assigned notifications, scoring at calibration/final), `addPeerReview`, `submitReview` (stage gating, scale bounds, resubmission policy), `score` (manager ratings drive: weighted goal % and competency % blended by cycle weights, expressed on the scale), `calibrate` (note mandatory, audited), `finalize` (label, summary, promotion/PIP flags, timeline entry, notification), `acknowledge`, `close` (all appraisals finalized; cycle goals completed), `distribution`.
- `Feedback` — `give`, `request`, `visibleTo` (own, authored, asked, public, or manager visibility for direct reports).
- `ImprovementPlans` — `open`, `extend`, `close`.
- `CareerPassport` — builds the §36 passport: skills, certifications, qualifications, experience, finalized appraisals, goal and feedback counts, aspiration, the applicable career path and skill gaps to the next step.
- `PerformanceDefaults` — seeds the five-point scale and six competencies at provisioning.

**Events** — `PerformanceEvent` with `recipientEmployeeIds`; the notification bridge fires rules first and otherwise sends a direct in-app note to those recipients.

**Admin UI (nav group "Performance")** — Cycles (governed edit; launch / advance actions; appraisals tab), Goals (cascade, key results repeater, check-in and close actions), Appraisals (scoped list; view page with self / manager / peer review forms, add reviewer, calibrate, finalize, acknowledge; reviews tab respecting anonymity), Calibration board (distribution + inline calibrate/finalize), Feedback (give / request / answer), One-on-ones, Improvement plans (open / extend / close), Career passport page (aspirations action), Rating scales, Competencies, KRA library, Career paths (steps tab). Employee 360 gains a **Performance** tab.

**Permissions** — `performance.view|manage|goals|review|calibrate|feedback|team`. Manager template: goals, review, feedback, team. Employee template: goals, review, feedback.

## Conventions

- Visibility is enforced in resource queries **and** `EmployeeOwnedPolicy` (own / direct report via current reporting relationship / HR). Reviewers see appraisals they must review.
- Scoring is deterministic from stored ratings; `score()` can be re-run at any time. Only the manager review feeds the computed rating; self and peer ratings are recorded alongside.
- Service methods that change state re-read the appraisal from storage (`submitReview`) so stale relations cannot bypass finalization.
- Models set in-memory `$attributes` status defaults, because DB defaults are invisible until refresh.
- Tests: `tests/Feature/Performance/{GoalsTest,AppraisalTest}.php`, `tests/Feature/Admin/PerformancePagesRenderTest.php`; helpers in `tests/Feature/Performance/PerformanceTestHelpers.php` (`draftCycle()`, `activeEmployee()`).

## Known gaps / deferred

- No 9-box / potential axis; succession planning and promotion execution (position change) are not wired to the recommendation flag.
- Goal templates per designation and automatic KRA assignment are not built; KRAs are picked manually when creating a goal.
- Peer nomination by the employee, review reminders by stage window and stage auto-advance by date are not scheduled.
- Skill gap analysis uses recorded person skills only; learning recommendations arrive with Phase 11.
