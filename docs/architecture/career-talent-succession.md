# Career, Talent & Succession Foundation (Phase 9)

For: engineers extending PeopleOS career, talent and succession.

Code lives in `app/Domain/Career`, `app/Domain/Talent` and `app/Domain/Succession`. The phase builds
on Phase 7 (performance) and Phase 8 (learning, skills, development) through their contracts. It
does not rebuild them. Discovery: `docs/PeopleOS-Phase-9-Discovery.md`.

## 1. Model map

```
Career
  CareerTrack (configurable type) ── CareerPath (Phase 7 model, extended: track, unit, effective dates)
                                       └── CareerPathVersion (immutable snapshot of the steps, checksum)
  RoleRequirementVersion (per Designation [+ organisation unit]; effective-dated, immutable; skills
                          pinned to a Phase 8 skill-scale version; competencies, experience,
                          certifications, learning)
  CareerProfile (one per employee; sharing flags the employee controls; lock_version)
  CareerAspirationEntry (short / medium / long term; effective-dated history; mirrored into the
                         legacy career_aspirations snapshot for the Career Passport)
  CareerGoal (separate from performance goals; active ⇄ paused → achieved / abandoned)
  MobilityInterest (position / job family / department / location / track; active → withdrawn)
Talent
  TalentProfile (one per employee; mobility, priorities, latest review outcome, encrypted notes)
  TalentPool ── TalentPoolMembership (explicit, effective-dated, unique while active)
  TalentAssessmentModel ── TalentAssessmentModelVersion (immutable dimensions) ── TalentAssessment
                                                            (draft → final → superseded)
  TalentReviewSession (draft → in progress → completed / cancelled) ── TalentReviewItem (decision)
  TalentDevelopmentAction (immutable link: successor → Phase 8 DevelopmentPlanItem)
Succession
  CriticalPosition (Designation [+ unit]; unique while active) ── CriticalPositionAssessment (immutable)
  SuccessionPlan (one open per position; draft → active ⇄ under review → closed)
     └── Successor (unique while active; removed with a reason)
  ReadinessAssessment (configured label per employee and target; immutable; superseded)
```

**A position is a role.** PeopleOS has no position-seat entity. A position is a `Designation`,
optionally within an organisation unit. Incumbents and movements are read from
`employee_positions`, and nothing new duplicates them.

## 2. History and immutability

| Record | Rule |
|---|---|
| Path versions, requirement versions, assessment-model versions, criticality assessments | Immutable snapshots. A requirement version may only have its `effective_to` set once, when the next version closes its window. |
| Aspirations | A new entry supersedes the current entry of the same term (`effective_to` = new start − 1). Text is never edited. |
| Pool memberships, successors | Ended / removed with a reason; never edited or deleted. `active_key` (nullable unique) allows one active row and any number of ended ones. |
| Talent assessments | `final` is immutable. A correction is a new assessment that supersedes the original when finalized. |
| Readiness | Immutable. A new assessment for the same `target_key` (`position:<id>` or `role:<id>`) supersedes the current one under an employee row lock. Validity is `talent.readiness_validity_months` (12). |
| Review items | Read-only once decided, and read-only when the session is completed or cancelled. |
| Every Phase 9 model except the reminder log | `static::deleting` throws. |

## 3. Who may see and do what

`TalentAccess` is the single resolver. Its chain is: tenant → permission → organisation scope
(`AccessScopes`) → relationship scope (`PerformanceRelationships`, configured types only; mentor,
buddy and project lead are excluded) → field (sharing flags, confidentiality).

| Area | Rule |
|---|---|
| Career (profile, aspirations, goals, mobility) | **The employee:** always.<br>**`career.view`:** within scope.<br>**`career.team` + a managed employee:** only what the employee shares. Aspirations and mobility are off by default; goals are on by default.<br>**Edits:** the employee (`career.self`) or `career.manage` within scope. Only the employee changes sharing flags. |
| Talent (profiles, pools, assessments, review outcomes) | `talent.view` / `talent.manage` within scope.<br>**Never** the employee themself, and never a manager by relationship alone. |
| Succession (candidacy, readiness) | `succession.view` within scope, or `succession.team` for managed employees.<br>The employee only with `succession.own_candidacy` (granted to no default role).<br>Managing needs `succession.manage`, never about oneself. |
| Confidential notes | Encrypted and hidden. Read only through `TalentAccess::confidential()`, which needs `talent.confidential`, is never about oneself, stays within scope and audits a `VIEW` (`confidential_talent_access`). |
| Review participation | `TalentReviewPolicy`: participants with `talent.review` see only their sessions. Talent pools stay behind `talent.view`. |

**Default role grants:**
- tenant HR admin: all of the above except `succession.own_candidacy`;
- managers: `career.self` and `career.team` (no succession by default);
- employees: `career.self`;
- auditors: `career.view`.

**Policies:**
- `CareerRecordPolicy`;
- `CareerArchitecturePolicy`, which keeps the Phase 7 performance-admin access to career paths;
- `TalentRecordPolicy`;
- `TalentConfigPolicy`;
- `TalentReviewPolicy`;
- `SuccessionPolicy`.

**Data classification:** `confidential_notes` on talent profiles, assessments, succession plans and
successors is highly sensitive. Talent and succession records are confidential.

## 4. Integration boundaries

- **Skills:**
  - read through `SkillProfiles::profile()` (best-evidenced level and basis: verified, manager,
    self…);
  - requirement levels are validated on `SkillScales::versionForSkill()` and pinned to its version;
  - `RoleGaps` compares only levels on the same scale and flags a different scale version, never
    converting between them.
- **Learning:** completions, enrolments and certificates are read for gap facts (completed / in
  progress / missing / expired). Nothing enrols or assigns.
- **Development:** development actions call `DevelopmentPlans::create()` / `addItem()`.
  - The employee's open plan is reused; a new one is opened only if none exists.
  - Item titles are neutral and never mention succession.
  - Learning happens in the plan, so it is never assigned twice.
- **Performance:**
  - `CompetencyEvidenceReader` returns the latest finalized competency ratings;
  - `PerformanceRelationships` gives manager scope;
  - `CareerPath`, `CareerAspiration` and `Competency` are the career-related Phase 7 models.
    Phase 9 writes only the career models.
- **Employment and exit:** incumbents come from current `employee_positions`. "Upcoming incumbent
  exit" is the earliest recorded `last_working_day` on an open exit case: a fact, not a prediction.
- **Never:**
  - payroll, compensation or statutory data;
  - RMS / recruitment objects;
  - `AttritionRisk` or the AI gateway;
  - position, lifecycle or pay changes;
  - scores or rankings of people.

  `TalentInvariantsTest` enforces all of these.

## 5. Concurrency

| Path | Protection |
|---|---|
| Successor add, pool membership, critical-position designation, open plan per position | Nullable unique `active_key`. A violation becomes a clear message; the plan or pool row is also locked. |
| Readiness supersede | Employee row lock, plus a locking read of the current rows. |
| Plan transitions, goals, career / talent profiles | Row lock + state re-check + `lock_version`. |
| Review decision / completion | Session row lock, then an item row lock. Completion re-checks the status. |
| Requirement and path versions | Designation / path row lock, plus a locking read of the latest version. |

`tests/MySql/TalentConcurrencyTest.php` uses the shared fork harness
(`tests/MySql/ConcurrencyHelpers.php`):

```bash
PEOPLEOS_MYSQL_CONCURRENCY_DB=hcm_…_concurrency php artisan test tests/MySql
```

Removing the readiness row lock makes its race fail (MySQL deadlock instead of a clean supersede).

## 6. API, events, automation, analytics

- **API:**
  - Endpoints:
    - `/api/v1/career/*` (`career.read`): tracks, paths, role requirements, profiles, goals,
      mobility interests, gaps, movements;
    - `/api/v1/talent/*` (`talent.read`): pools, memberships, reviews, analytics;
    - `/api/v1/succession/*` (`succession.read`): critical positions, plans, successors,
      readiness, analytics.
  - Employees are identified by code only, and ids from another tenant return 404.
  - Never returned: confidential notes, strengths and gaps, reasons, evidence, ratings, aspiration
    text, or development priorities. Goals and mobility are returned only when the employee shares
    them.
- **Events:** `CareerEvent`, `TalentEvent` and `SuccessionEvent` carry the tenant-owned subject and
  explicit recipients.
  - `NotificationEventBridge::onTalent` notifies only those recipients, in-app. For talent and
    succession it never notifies the employee concerned and never fires tenant rules with their
    context.
  - Webhooks allow-list only `career.path.published`, `succession.critical_position.created` and
    `talent.review.completed`.
- **Workflows:** completing a talent review and activating a succession plan start the configured
  workflow, pinned to its published version. The workflows are set by
  `PEOPLEOS_TALENT_REVIEW_WORKFLOW` and `PEOPLEOS_SUCCESSION_PLAN_WORKFLOW`.
  `SuccessionWorkflowBridge` applies the outcome. Without a workflow the action completes directly.
- **Reminders:** `TalentReminders` covers critical-position reviews, plan reviews, expiring
  readiness and upcoming talent reviews. Reminders go to the record owners and are throttled
  through `talent_reminder_logs`.
  - Jobs: `SendTalentReminders` (tenant-aware, unique).
  - Schedule: `peopleos:talent:send-reminders` at 07:45, with `withoutOverlapping()->onOneServer()`.
- **Analytics (`TalentAnalytics`):**
  - Covers critical positions, coverage, ready-now coverage, positions without a ready-now
    successor, bench strength, readiness distribution, pools, development-action progress, review
    decisions, aspirations, mobility and successor skill gaps.
  - Database aggregates under the caller's scope; people counts below
    `talent.analytics_min_group` (5) are suppressed.
  - The coverage dashboard runs a constant number of queries (one grouped query for incumbent
    exits).

## 7. Screens

- **Talent navigation:**
  - Dashboard;
  - Career architecture (tracks);
  - Career paths (versioned);
  - Role requirements;
  - Career profiles;
  - Critical positions;
  - Succession plans;
  - Successors;
  - Talent pools;
  - Talent reviews;
  - Readiness;
  - Development actions;
  - Analytics.
- **Me:**
  - **My career:** profile and sharing, aspiration history, career goals, mobility, factual role
    gaps and movement history. Skills and development plans stay on My learning.
  - **Team career:** shared fields only, succession with `succession.team`, and the reviews one
    takes part in.
- **Employee 360:** Career, Talent and Succession tabs, each shown only when the viewer's policy
  allows it.

## 8. Known limitations

- **Positions:**
  - A position is a role (plus a unit); there is no seat or headcount entity.
  - Unit scoping matches the unit's own dimension on the current position, not child units.
- **Gap analytics:** successor skill-gap analytics read one Phase 8 skill profile per successor, in
  chunks. Requirements are resolved once per position.
- **Assessment model:** the default is two dimensions with three levels. Tenants configure others;
  there is no visual 9-box grid.
- **Mobility:** mobility interest is a record only. There is no internal marketplace or matching.
- **Workflows:** no review or plan approval workflow is configured by default.
