# Phase 11 — Learning and Assets

Blueprint §37, §38, §121 Phase 11. Built 2026-09-27.

## Learning (`App\Domain\Learning`)

| Model | Purpose |
|---|---|
| `Course` + `CourseModule` | Catalogue entry (type video / document / e-learning / classroom / virtual / assessment, category, duration, content link, mandatory flag, certificate validity in months, passing score, attempts allowed). `draft → published → retired`; only published courses can be enrolled. Modules are ordered content units. |
| `Assessment` + `AssessmentAttempt` | One quiz per course: `questions [{question, options[], answer (index), marks}]`; answers are audit-sensitive and never sent to learners (`questionsForLearner()`). Attempts store the learner's answers and score. |
| `LearningPath` + `LearningPathCourse` | Ordered course bundles with required / optional items. |
| `LearningAssignment` | Who learns what: one employee or rule-engine conditions (empty = everyone employed), due in N days, optional recurrence every N months, mandatory flag, auto-enrol later matches. |
| `LearningEnrolment` | One learner on one course: completed module ids, progress %, score, attempts, due date, started / completed, expiry. Statuses `enrolled, in_progress, completed, failed, overdue, expired, withdrawn`. One open enrolment per course per person. |
| `TrainingSession` + `TrainingSessionAttendee` | Classroom / virtual deliveries with trainer, venue or link, capacity; attendance marks completion. |
| `LearningCertificate` | Issued on completion of a course with validity (`CERT-<course>-<yyyymm>-<employee code>`); `valid → expiring → expired`. |

**Services** — `Learning` (`enrol`, `enrolPath`, `applyAssignment` with recurrence-aware coverage, `start`, `completeModule`, `recomputeProgress`, `complete` with certificate + timeline, `fail`, `withdraw`, `tick`), `Assessments::submit` (grades, closes on pass, fails when attempts run out), `TrainingSessions` (`register` creates the enrolment, `markAttendance` completes it unless a quiz remains, `close`).

**Automation** — `peopleos:learning:tick` (daily 03:00): applies rule-based / recurring assignments, marks overdue enrolments, sends due-soon reminders, moves certificates to expiring and expired (the linked enrolment expires too, so the recurring assignment re-enrols).

**Events** — `LearningEvent` (`learning.assigned|due_soon|overdue|completed|failed|certificate_expiring|session.registered`) bridged to notification rules with an in-app fallback to the learner.

## Assets (`App\Domain\Assets`)

| Model | Purpose |
|---|---|
| `AssetCategory` + `AssetModel` | Category (serial required, IT asset flag for IT clearance, useful life) and make/model with specifications. Eleven starter categories per tenant. |
| `Asset` | The item: tag (unique per tenant, uppercased), serial, status, condition, custodian, company, location, procurement details, warranty; supports custom fields (entity `asset`). |
| `AssetAssignment` | Custody rows: active until returned, with condition out / in, acknowledgement, expected return. Active rows are the exit-clearance list (`Assets::clearanceFor`). |
| `AssetMovement` | Append-only lifecycle trail: procured, assigned, transferred, returned, repair_out, repair_in, lost, found, retired, disposed, location. |
| `AssetRepair`, `AssetDisposal` | Repair tickets (vendor, issue, cost, resolution) and the single disposal record (method, date, value, reference). |

**Service** — `Assets`: `receive`, `assign` (in-stock assets to current employees only), `acknowledge`, `transfer`, `returnAsset` (optionally straight into repair), `sendForRepair`, `repaired`, `markLost`, `found`, `retire`, `dispose` (never while assigned), `relocate`, `clearanceFor`. Status changes happen only here; the edit form drops `status` / `custodian_id`.

**Events** — `AssetEvent` (`asset.assigned|transferred|returned|repair|disposed|lost`) with an in-app fallback to the custodian.

## Admin UI

- **Learning group**: My learning / Enrolments (badge = open enrolments; view page shows modules, marks them complete, runs the quiz as a modal, withdraw for staff; "Enrol employee" header action), Courses (governed edit; Modules and Assessment tabs), Learning paths (Courses tab), Training sessions (self-registration, Attendees tab with attended / absent and bulk attendance, "Mark session completed"), Assignments (rule conditions, "Apply now"), Certificates.
- **Assets group**: Asset register / My assets (view page with the full action set: assign, transfer, take back, repair, back from repair, change location, report lost, found, retire, dispose; Custody history with employee acknowledgement, Lifecycle and Repairs tabs), Categories, Models.
- **Employee 360**: Learning tab (enrolments, valid certificates) and Assets tab (custody with acknowledge).

**Permissions** — `learning.view|manage|assign|learn`, `asset.view|manage|assign|own`. Asset Admin template holds `asset.*`; Manager gains `learning.assign`, `learning.learn`, `asset.own`; Employee gains `learning.learn`, `asset.own`.

## Conventions

- Learner-facing queries never expose assessment answers; grading is server-side in `Assessments`.
- `Learning::enrol` is idempotent per open enrolment; assignments are applied through `applyAssignment`, never by creating enrolments directly, so recurrence and coverage stay consistent.
- Asset lifecycle transitions must go through `Assets` so a movement row and audit entry exist for every change.
- Tests: `tests/Feature/Learning/LearningTest.php`, `tests/Feature/Assets/AssetsTest.php`, `tests/Feature/Admin/LearningAssetsPagesRenderTest.php`; helper `publishedCourse()` in `tests/Feature/Learning/LearningTestHelpers.php`.

## Known gaps / deferred

- No file upload for course content; modules link to external URLs or hold text. SCORM / xAPI is out of scope.
- Session waitlists, reminders before sessions and trainer feedback reports are not built.
- Depreciation schedules, asset audits / stock counts and barcode scanning are not built.
- The exit-clearance consumer of `Assets::clearanceFor` arrives with Phase 13; the IT-asset flag is ready for it.
