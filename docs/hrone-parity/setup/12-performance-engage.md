# HROne Setup → Performance (12) and Engage (13): field-by-field gap against PeopleOS

Date: 2026-10-28. Read-only analysis; nothing in the repository was changed.

**Sources.** HROne: `spec/12_Performance.md`, `spec/13_Engage.md`, `spec/common-settings-performance-engage.md` (compiled Angular templates), plus the page captures in `setup/12_*` / `setup/13_*` (visible text and screenshots, used to read options and default rows the templates do not carry) and `docs/hrone-parity/hrone-configuration-catalogue.md` (field names HROne's API returned). PeopleOS: `config/peopleos.php`, `config/peopleos_setup.php`, `app/Domain/Performance`, `app/Domain/Talent`, `app/Domain/Engagement`, `app/Domain/Communication`, `app/Domain/Knowledge`, `app/Domain/People/Services/Birthdays.php`, `app/Filament/**`, migrations, `docs/architecture/{performance-foundation,phase-10-performance-talent,career-talent-succession,engagement-communication,birthdays,hrone-parity}.md`, `docs/hrone-parity/HROne-Gap-Register.md` (PF-xx, EN-xx, CF-24, CF-25).

**Status.** HAVE = PeopleOS has it (same or richer). PARTIAL = a related capability exists but the HROne option is missing or narrower. MISSING = nothing. N-A = not a PeopleOS concern for this section (form plumbing, another module's panel, or a concept that does not apply).

**How the build notes are written (PeopleOS conventions).**
- *Tenant setting*: a default in `config/peopleos.php` → `settings` (the default reproduces today's behaviour) and a row in `config/peopleos_setup.php` → `modules.performance|engage.settings`, read through `SettingsRepository`. The Setup centre lists a key only when code reads it (`SetupCentreTest`).
- *Policy type*: an entry in `config/peopleos.php` → `policies.types` (fields rendered by `App\Filament\Support\PolicySettingsSchema`, follow-up questions via `visible_when`), listed in the module's `policy_types`, resolved per employee by `App\Domain\Configuration\Services\PolicyResolver` (assignment rules = HROne "Applicability"; rule operators `in/gte/lte…` + version `effective_from/to` = HROne "Advance applicability"; version view + `ImpactPreview` = HROne "Review" step). New `options_from` sources (competencies, goal templates, letter templates, KPI formulas, badges) are added in both `PolicySettingsSchema::options()` and `SetupCatalogue::optionsFor()`.
- *New table*: tenant-owned model with `BelongsToTenant` + `Auditable` (+ `HasEffectiveDates` where history matters), classified in `app/Domain/Offboarding/Support/DataInventory.php`, guarded by a policy, wired in `App\Providers\ParityServiceProvider`, shown on PeopleOS base pages; permission keys in `config/peopleos.php` → `permissions` then `php artisan peopleos:sync-permissions`.

**What PeopleOS has today (performance).** Cycles with stages, eligibility and per-cycle options (`PerformanceCycleResource`), versioned review templates (`PerformanceTemplateResource`, `PerformanceTemplateVersion`: sections, scale/competency snapshots, workflow, weights, `goal_rules.required_total_weight`), rating scales, competencies, KRA library with suggested KPIs, goals/OKRs with key results and check-ins, self/manager/peer/upward/skip-level/external reviews, calibration sessions and board (actual distribution), finalize + acknowledge, check-ins, one-on-ones (private notes), feedback (praise/constructive, anonymous), PIPs with checkpoints, performance analytics. The Setup centre's Performance module has **no settings and no policy types** (`config/peopleos_setup.php` `performance.settings = []`, `policy_types = []`); every HROne performance "policy" is therefore at best PARTIAL.

**What PeopleOS has today (engage).** Birthdays (8 `celebration.birthdays.*` settings, wishes, day/month lists), announcements, audiences, surveys (incl. pulse), HR handbook = knowledge-base articles with versions, review, acknowledgement and audience rules, engagement campaigns, employee feedback. No recognition, badges, points, social wall, moodbot or mission statement.

**Defects found on the way (worth fixing in the first package).**
1. `PerformanceCycleResource` saves `settings.show_self_to_manager` and `settings.peer_anonymous`, but no code reads either (`grep` finds only the form). The manager always sees the self review (`Appraisals/RelationManagers/ReviewsRelationManager` treats the line manager as privileged) and peer anonymity is set per reviewer in the *Add reviewer* action. Wire them or remove them. **→ Fixed (HP-8b): both are read (`AppraisalVisibility::selfReviewVisible`, `Appraisals::addPeerReview`).**
2. Employees see each **submitted** review about them, ratings included, before the appraisal is finalized (`ReviewsRelationManager` non-privileged branch: `status = submitted` on their own appraisal). HROne's "publish rating: Manual" exists to prevent exactly this. **→ Fixed (HP-8b): `AppraisalVisibility`; the employee sees ratings and reviews by others only once published.**
3. `competencies.weight` is stored but scoring ignores it (`Appraisals::averageScore()` is a plain average). **→ Fixed (HP-8d): competency scores are weighted by the competencies' weightage (as pinned with the cycle's template version) when they carry one; a plain average otherwise.**

---

## 12 Performance

> **Progress (2026-10-29, HP-8a / HP-8b / HP-8c built).** Performance masters (perspectives, initiative types, PIP
> reasons, units of measurement and their links), terminology with "Use OKR terms", the Settings panel (rating
> publication + bulk publish, goal-edit window, decimals and rounding, cycle type and year, 1-on-1 scope and feedback
> cards, goal check-in prompts, 360 on the profile), level ranges, a competency scale per cycle and KPI rating formulas
> with reverse KPIs. Defects 1 (inert `show_self_to_manager` / `peer_anonymous`: now read) and 2 (employees saw ratings
> before publication: fixed in every screen) are closed; defect 3 (competency weights) is left to HP-8d. Still open in
> section 12: KPI sharing (no package), copy previous level (HP-8d), competency tags, goal sheet / templates / PIP /
> performance / 360 policies, 9-box and bell curve, initiatives, delete transaction (HP-8d…HP-8k). Rows built are
> marked "→ built (HP-8x)" in the Status column. Details: `docs/architecture/hrone-parity.md`.
>
> **Progress (2026-10-29, HP-8d / HP-8e / HP-8f built).** Goal sheet policy (§12.3: KRA / KPI / competency weightage and
> number rules, equal weightage, field rights per role, goal sheet approval in the Approval Center or by a workflow,
> review level / input / evidence, an approver review level, scorecard fields hidden from the employee, copy the previous
> level's inputs; "Duplicate" on every policy; defect 3 fixed), goal templates with system mapping, "Use template" and a
> feedback form (§12.5), PIP policy with period, extensions, PIP / PIP evaluation letters, start and close checklists and
> approval of the plan and its outcome (§12.10; PIP reasons were HP-8a, §12.11). Every row of §12.3, §12.5 and §12.10 is
> HAVE, N-A or built. Still open in section 12: KPI sharing, competency tags, performance / 360 policies, 9-box and bell
> curve, initiatives, delete transaction (HP-8g…HP-8k).
>
> **Progress (owner decision 2026-10-29, goal sheet system mapping):** "Choose role for system mapping" (§12.3 step 1) is
> settled: the reporting manager owns the final mapped goal sheet. The employee's additions and changes to a mapped sheet
> wait in `pending_approval` on the sheet's goal plan (opened for the manager, or the policy's workflow, even when the
> policy asks for no approval) until the manager confirms them; the manager changes the sheet directly; the employee
> cannot make a mapped goal active, complete or cancel it. `goal_sheet_owner` now only says who fills the sheet in first.
> Manual goal sheets are unchanged.
>
> **Progress (2026-10-29, HP-8g / HP-8j built).** Bell curve (§12.14): target % per rating level per cycle or as the
> default, target vs actual on the calibration board (chart, department / grade filter), and the check
> `performance.distribution.check` (off by default / warn / block closing outside `performance.distribution.tolerance_pct`).
> 9-box (§12.13): bands per axis per cycle or default (thirds by default), `NineBoxGrid` placing the rating that stands
> against the competency rating (or, by setting, the talent assessment's potential for people who may see talent data);
> HR in scope, managers their team, analytics counts only. Initiatives (§12.9, PF-05): `initiatives` + feedback asked of
> colleagues or given by the manager, My initiatives / team / HR screens, notifications, My tasks, report "Initiative
> details". Every row of §12.9, §12.13 and §12.14 is built. Still open in section 12: KPI sharing, competency tags,
> performance / 360 policies, delete transaction (HP-8h, HP-8i, HP-8k). Details: `docs/architecture/hrone-parity.md`.
>
> **Progress (2026-10-29, HP-8h / HP-8i built to the SV-12 / SV-11 designs of `13-setup-reverification.md`).** 360 policy
> (§12.2, PF-1 … PF-5): HROne's real form — frequency and window, manager / functional-manager feedback, peers and
> subordinates by up to three criteria with maximums, clients by a signed, expiring, one-time link (name and e-mail only),
> anonymity and a feedback template (P13, process `feedback_360`) per source, who nominates from which pool, minimum and
> approval (Approval Center) — with rounds (launch, extend, close, automatic launch), anonymity-safe results (aggregated,
> minimum answers, employee after the round closes by default), an export and an optional blend into the appraisal rating.
> Performance policy (§12.12, PF-6 … PF-11): goal window, review feedback form, periodic reviews with windows and manual
> initiation rolled up as the policy says, sum of all goals, goal / competency weightage, ratings above the scale,
> per-level visibility of ratings, final rating and final comments, TNI / promotion / increment / variable recommendations
> (to learning as development needs, to compensation cycles as a suggestion), eligibility by joining date, performance
> letter; answers not yet read are recorded on the policy. Every row of §12.2 and §12.12 is built. Still open in section
> 12: KPI sharing, competency tags, delete transaction (HP-8k).

### 12.0 Performance — Settings panel (`<app-setting>`, the gear on every Performance setup page) and common "Delete transaction"

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Performance cycle — `appraisalType` — Half yearly / Yearly | `performance_cycles.type` chosen per cycle (`peopleos.performance.cycle_types`: annual, half_yearly, quarterly, probation, project) in `PerformanceCycleResource` | HAVE → built (HP-8b) | Optional tenant default `performance.cycle.default_type` (select over `cycle_types`, default `annual` = today's form default) read as the `type` default in `PerformanceCycleResource`; list under Setup → Performance. |
| Cycle starting month — `performanceStartMonth` (placeholder "Cycle starting month") | `period_start` / `period_end` typed per cycle; no tenant year start (only `leave.year_start_month` for leave) | PARTIAL → built (HP-8b) | Setting `performance.year_start_month` (number 1–12, default `null` = no prefill, today). When set, the cycle form pre-fills period start/end from the type (annual = 12 months, half-yearly = 6) and the 9-box / bell-curve / analytics cycle pickers default to the current period. |
| How do you want to publish performance rating? — `isPublishPerRatingAuto` — Auto / Manual | Finalize publishes at once (`Appraisals::finalize` → event `performance.appraisal.finalized` to the employee; `final_rating`, `final_label`, summary shown). Submitted reviews are visible to the employee even earlier (defect 2) | PARTIAL → built (HP-8b) | Setting `performance.rating_publish` (select `auto`/`manual`, default `auto`). Add `appraisals.published_at`, `published_by`; `Appraisals::publish(PerformanceCycle, ?array $appraisalIds)` + bulk "Publish ratings" action on `CalibrationBoard` and the cycle (PF-03). In `manual`, the employee-facing rating, label, summary, finalize notification and *Acknowledge* wait for `published_at`; `auto` stamps it at finalize. Also hide manager/peer reviews from the employee until published in `ReviewsRelationManager` (self review stays visible). |
| Goal edit allowed only during goal period — `allowGoalEditDuringOnlyGoalPeriod` — Yes / No | Goals are editable until reviewing starts: `Appraisals::onStageEntered()` calls `Goals::lockForCycle()` at self/manager review (`Goal::LOCKED_FIELDS`); before that, edits are allowed at any time, including before the goal-setting window | PARTIAL → built (HP-8b) | Setting `performance.goals.edit_only_in_goal_stage` (boolean, default `false`). When on, `Goals::create/update/close` refuse non-`performance.manage` users unless the goal's cycle is `active`, `current_stage = goal_setting` and today is inside that stage's `starts_on/ends_on`. |
| Do you want to share KPI? — `isKeyResultKeyPerformanceShared` — Yes / No, with scope options *Across organization / Creator / Reporting Manager / Functional Manager* | Goals cascade/align (`goals.parent_id`, `goal_levels` company→employee) but each goal has one owner; no shared KPI with common progress | MISSING → OPEN — not in HP-8a…c (no package names it): needs `goal_shares` and shared progress | Setting `performance.goals.sharing` (select `off`/`organisation`/`creator`/`reporting_manager`/`functional_manager`, default `off`) = who may share a KPI with whom (relationship types via `PerformanceRelationships`: `line` = reporting, `functional`). Table `goal_shares` (goal_id, employee_id, shared_by, weight) ; `Goals::checkIn()` progress on a shared goal is the progress of every holder; reviews rate it per holder. |
| Goal check-in: company — `okrIntervalIdForCompanyScheduler`, `okrIntervalValueForCompany` (Select interval; Week start day / Month start day) | No scheduled check-in prompts; progress is recorded ad hoc (`Goals::checkIn`); `PerformanceReminders::tick()` reminds only reviewers, managers of submitted check-ins and PIP checkpoints | MISSING → built (HP-8b) | Settings `performance.checkin.company.interval` (`none`/`weekly`/`monthly`, default `none`) and `performance.checkin.company.day` (weekday or 1–28). `PerformanceReminders::tick()` adds reminder `goal_checkin_company` to creators of open company/business goals on that day (idempotent via `performance_reminder_logs`). |
| Goal check-in: team — `okrIntervalIdForTeamScheduler`, `okrIntervalValueForTeam` | As above (team/department goals carry `organisation_node_id`, no prompt) | MISSING → built (HP-8b) | `performance.checkin.team.interval` / `.day` (default `none`); reminder `goal_checkin_team` to creators of open department/team goals. |
| Goal check-in: employee — `okrIntervalIdForEmployeeScheduler`, `okrIntervalValueForEmployee` | `performance_check_ins.cadence` (weekly/biweekly/monthly/custom) chosen per check-in by the employee; nobody is prompted | PARTIAL → built (HP-8b; prompts only, no pre-created draft check-in) | `performance.checkin.employee.interval` / `.day` (default `none`); reminder `goal_checkin_employee` to employees with active goals; optional: pre-create the draft `PerformanceCheckIn` for the period (`PerformanceCheckIns::save`). |
| Scheduled time (time of day for the three prompts) | — | MISSING → built (HP-8b; whole hour) | `performance.checkin.send_at` (time, default `09:00`), applied on the tenant clock like `peopleos:birthdays:send` (hourly run, tenant timezone, `scheduler_claims`). |
| 360 degree feedback will be visible on employee profile — `showThreeSixtyFeedbackEmployeeProfile` — Yes / No | Employee 360 *Performance* tab (`Employees/RelationManagers/PerformanceRelationManager`) shows manager/final rating and label only; `appraisals.peer_rating` and peer reviews are not shown | MISSING → built (HP-8b) | Setting `performance.profile.show_360` (boolean, default `false`). When on, the tab adds peer rating and submitted peer/upward/skip-level/external reviews (author masked when `is_anonymous`, as in `ReviewsRelationManager`) for viewers who may see the employee's performance; only after publish (see above). |
| Do you want to show custom rating? — `showCustomRating` — Yes / No | No formula-derived ("custom") KPI rating exists | MISSING → built (HP-8c) | Setting `performance.review.show_formula_rating` (boolean, default `false`); needs §12.17 KPI rating formulas; the review form shows the system rating next to each goal. |
| Do you want to show reverse rating? — `showReverseRating` — Yes / No | `Goal::progressFor()` already handles a target below the start value (lower is better), but there is no explicit reverse flag or reverse display | PARTIAL → built (HP-8c) | `goals.is_reverse` and `key_results.is_reverse` (boolean, default `false`; also on KRA library KPIs) + setting `performance.review.show_reverse_rating` (default `false`); with a reverse KPI the formula uses target/achievement (§12.17). |
| `settings`, `status` (form plumbing) | — | N-A | — |
| *(catalogue)* `allowFinalRatingInDecimal`, `roundOffFormula` | Computed rating keeps 2 decimals (`Appraisals::score`), but calibrate/finalize pick a scale level from a select and default to `round(effectiveRating())` | PARTIAL → built (HP-8b) | Settings `performance.rating.final_decimals` (0/1/2, default `0` = today's level select) and `performance.rating.rounding` (`round`/`floor`/`ceil`, default `round`), used by the calibrate/finalize options and defaults in `PerformanceActions` and by `RatingScale::labelFor()`. |
| *(catalogue)* `isRatingCommentCopyToNextLevel` | Manager review form starts blank | MISSING → built (HP-8d: `performance.review.copy_previous_level`; per group `copy_goal_inputs` / `copy_competency_inputs`) | Same as the goal-sheet advance settings (§12.3), as a tenant default: `performance.review.copy_previous_level` (boolean, default `false`). |
| *(catalogue)* `isOkrLabel` ("COPY OKR LABELS") | — | MISSING → built (HP-8a) | See §12.6 terminology (an action that sets the OKR labels). |
| *(catalogue)* `addDueDateWithKeyPerformanceIndex` (KPI due date) | `goals.due_date` exists; `key_results` has no due date | PARTIAL → built (HP-8a) | `key_results.due_date` nullable + setting `performance.goals.kpi_due_date` (boolean, default `false`) showing it in the goal form's key-result repeater. |
| *(catalogue)* `isAppraisalTypeEditable`, `isRatingEditable`, `isFinalRatingInDecimalEditable`, `isAllowedGoalEditEditable` (HROne locks a setting once data exists) | PeopleOS freezes a cycle's configuration once scheduled/launched (`PerformanceCycle` saving guard) and pins template versions | N-A | System flags, not admin settings; PeopleOS's pinning covers the intent. |
| Delete Transaction — "Select the Performance years" `performanceCycleId`; "Select the process you want to delete transactions for" `taskTypeIds`; Comments `remarks`; confirmation ("I'm sure, delete"), queued request | History is immutable by design: `GoalCheckIn`, `CalibrationAdjustment`, `PerformanceTemplateVersion`, `ImprovementPlanCheckpoint` refuse deletes; `Appraisal`/`AppraisalReview`/`AppraisalRating` guard deletes; only goal deletion on `Goals/Pages/EditGoal`; cycles can be archived. PF-14 PARTIAL | MISSING → OPEN — HP-8k, owner decision (immutability) | Needs an owner decision (immutability). Proposal: `App\Domain\Performance\Services\PerformanceResets` + "Reset cycle data" action on `PerformanceCycleResource` with new permission `performance.reset`; per process (goals, check-ins, reviews of non-finalized appraisals, peer nominations, drafts) for a cycle that is draft/active and not locked; reason required; audited as one `AuditAction` with counts; never touches finalized/locked appraisals, calibration history or template versions. |

### 12.1 1-on-1 (`<app-1-on-1-home>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Choose who the initiator (person starting the 1-on-1) can schedule a session with — `allowOneOnOneFor` — Team members / Anyone in the organization | `OneOnOneResource`: the initiator is the manager (`manager_id` defaults to me); non-admins can only pick their reports (`PerformanceRelationships::reportIds`); `performance.manage` can pick anyone. Equivalent to "Team members" only; an employee cannot start one with their manager | PARTIAL → built (HP-8b) | Setting `performance.one_on_one.scope` (`team`/`organisation`, default `team`). Add `one_on_ones.initiated_by` (employee id). `organisation`: any employee with `performance.goals` may schedule with any employed colleague (the other party becomes the participant); private notes stay with the host (`OneOnOnes::isOwningManager` → "host"). |
| Select the roles who will receive feedback card for 1-on-1 — Initiator of 1-on-1 `isFeedbackCardForInitiator`; Participant in 1-on-1 `isFeedbackCardForParticipant` (checkboxes) | No post-meeting feedback or acknowledgement (PF-06 MISSING) | MISSING → built (HP-8b) | Setting `performance.one_on_one.feedback_from` (multiselect `initiator`/`participant`, default `[]` = today). When a meeting moves to `held`, a task appears in My tasks (new `OneOnOneFeedbackTaskSource` in `peopleos.experience.task_sources`); answers in new table `one_on_one_feedback` (one_on_one_id, employee_id, rating 1–5, comment, acknowledged_at) — the same row is the "1-on-1 Acknowledgement". |
| UPDATE | Setup centre save (reason, audited) | HAVE | — |

### 12.2 360 Feedback policy (`<app-policy360-layout>`, `<app-policy360-form>`, `<app-policy360-filter>`)

The inner controls of the *Policy* step are not in the compiled templates (the tenant has no 360 policy, "NO 360 POLICY"); they are inferred from HROne's 360 processes (nomination request workflow, "Initiate 360 feedback", "Extend 360", 360 nomination status/feedback reports).

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| 360 policy list ("360 policy - ##", ADD POLICY) and filter — Policy name (placeholder "Select 360 policy"), Status | No 360 policy. 360 = extra reviews inside an appraisal: `Appraisals::addPeerReview()` / *Add reviewer* action (`PerformanceActions`), types peer, upward, skip_level, external (`peopleos.performance.review_types`), anonymity per reviewer | MISSING → built (HP-8h: policy type `feedback_360` in Setup → Performance and the policy wizard) | Policy type `feedback_360` (list/filter = Policies screen filtered by type, as for other types). |
| Step *Policy* (name, description and the 360 rules) | Name/description: platform `Policy.name/description`. Rules: none | MISSING → built (HP-8h, to HROne's real form PF-1 … PF-5: sections Frequency & duration, Manager, Peer, Subordinate, Client, Nomination settings, plus Results and privacy; replaces the inferred field list below) | Fields: `enabled`; `nominated_by` (employee/manager/hr); `nomination_approval` (none/manager/hr); `relations` (multiselect over review_types except self/manager); `min_reviewers`, `max_reviewers` per relation; `anonymity` (always/reviewer_choice/never — replaces the inert cycle toggle `settings.peer_anonymous`); `feedback_form` (Forms engine form code; empty = rating + comments of the review form); `due_days`; `results_visible_to_employee` (after_publish/never); `include_in_rating` (yes/no — today peer ratings are stored in `appraisals.peer_rating` but not blended). |
| Step *Applicability* | Policy Engine assignment rules (`PolicyAssignmentRule.conditions`, `RuleConditionsSchema`) | HAVE | Platform, once the type exists. |
| Step *Advance applicability* | Rule operators + version effective dates | HAVE | Platform. |
| Step *Review* | Policy version view + `ImpactPreview` | HAVE | Platform. |
| *(process behind the policy)* standalone 360 rounds, nominations, approval, extend (PF-04, PF-02) | Peer reviews only inside a running appraisal, added by HR/manager | PARTIAL → built (HP-8h: `feedback_rounds`, `feedback_participants`, `feedback_nominations`, `feedback_responses`; Approval Center source; extend / close / automatic launch; client links; anonymity-safe results and export; optional blend. Workflow approval of nominations not built) | New `feedback_rounds` (cycle optional, window, status) and `feedback_nominations` (round, subject, reviewer, relation, status, decided_by); nominations as an Approval Center source (`ApprovalSource`, registered in `ParityServiceProvider`) and workflow subject type; responses reuse `appraisal_reviews` when tied to an appraisal, else a `feedback_responses` table; *Extend* = audited window change. |

### 12.3 Goal → Goal sheet policy (`<app-goal-sheet>`, `<app-goal-sheet-form>`, `<app-goal-configration>`, `<app-goal-sheet-review>`, `<app-goal-sheet-advance-setting>`)

PeopleOS keeps goal rules in two places today: `PerformanceTemplateVersion.goal_rules.required_total_weight` and the cycle setting `settings.required_goal_weight` (`Goals::requiredWeight()`, `assertWithinWeightTotal()`, `submitPlan()`). Proposal: one policy type **`goal_sheet`** resolved per employee (precedence: goal-sheet policy → template version → cycle setting, so tenants without a policy behave as today), read by a new `App\Domain\Performance\Services\GoalSheetRules` used by `Goals`, `GoalResource`, `PerformanceActions::reviewForm()` and `AppraisalResource`.

**List and form frame**

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Policy cards: "Goal sheet policy - ##", ADD POLICY, Active / Inactive, APPLICABLE ON, more_vert, SHOW MORE; filter Policy name ("Select goal sheet policy"), Status, APPLY FILTERS, Filters applied / CLEAR ALL | No goal-sheet policy (rules per template/cycle only) | MISSING → built (HP-8d) | Policy type `goal_sheet`; list/filter = Policies screen. |
| Clone (card menu) | Policies have no duplicate action (`app/Filament/Resources/Policies` has none) | MISSING → built (HP-8d: "Duplicate" on every policy) | Platform: "Duplicate" action on `PolicyResource` copying the latest version's `settings` into a new draft policy (benefits every policy type). |
| Wizard steps: Goal sheet policy · Configuration · Other setting · Applicability · Advance applicability · Review; Next / Previous | Policy form: details + versioned settings form + assignment rules | HAVE → built (HP-8d: the policy wizard's steps are the type's sections) | Group the `goal_sheet` fields with `type: section` (Goal creation, Weightage, Field rights, Review configuration, Scorecard, Advanced) so the form reads like the wizard. |

**Step 1 — Name & description, goal creation (`<app-goal-sheet-review>`)**

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Goal sheet policy name — `goalSheetName` | `Policy.name` | HAVE | Platform. |
| Description — `goalSheetDescription` | `Policy.description` | HAVE | Platform. |
| Goal Creation: Manually create goals — `isGoalSheetCreatedManually` — Yes / No ("Note: By default, goals are mapped by the system") | Goals are always created by hand (`GoalResource`, `Goals::create`, API progress) | PARTIAL → built (HP-8d) | Field `goal_creation` (select `manual`/`system`, default `manual` = today). |
| Choose template for system mapping — `templateId` | No goal templates (PeopleOS "templates" are review templates) | MISSING → built (HP-8d/HP-8e) | Field `goal_template_code` (`options_from: goal_templates`, `visible_when goal_creation = system`); on cycle launch / entering `goal_setting`, `GoalTemplates::assign(Employee, PerformanceCycle)` creates draft goals (`source = system`). Needs §12.5. |
| Choose role for system mapping — `roleId` | — | MISSING → built (HP-8d/8e) → owner decision 2026-10-29 built: the reporting manager owns the final mapped goal sheet; the employee's additions and changes are proposals (`pending_approval` on the sheet's goal plan) the manager confirms or changes directly; the employee never finalises it alone. `goal_sheet_owner` now only says who fills the mapped sheet in first (stored values keep their meaning) | Interpreted as the role that owns/creates the system-mapped goal sheet (e.g. the reporting manager receives the "Create goal" task). Field `goal_sheet_owner` (select `employee`/`manager`/`hr` or `options_from: roles`). Confirm meaning against a live HROne policy before building. |

**Step 2 — Configuration (`<app-goal-configration>`, `<app-goal-creator-settings>`, `<app-kra-form>`, `<app-popup-setting>`, `<app-review-configuration>`, `<app-scorecard-configuration>`)**

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Goal creation configuration — Goal creator: per field View / Edit / Mandatory — `isVisibleForCreator`, `isEditableForCreator`, `isMandatoryForCreator` (hover a field chip in the KRA card or use the toggle; `app-kra-form` chips, `app-popup-setting` View/Edit/Mandatory) | `GoalResource` shows the same fields to everyone; ownership only (`Goals::assertOwnership`) | MISSING → built (HP-8d) | Field `field_rules` (repeater: `field` ∈ title, description, kra, perspective, measure_type, unit, start_value, target_value, weight, start_date, due_date, key_results; per role `view`/`edit`/`required`). Support class `GoalFieldRules::for(Employee, role)` hides/disables/requires fields in `GoalResource` and the key-result repeater; `Goals::create/update` re-check *required* and *edit* server-side. |
| … Employee — `isVisibleForEmployee`, `isEditableForEmployee`, `isMandatoryForEmployee` | as above | MISSING → built (HP-8d) | Same `field_rules`, role `employee`. |
| … Manager — `isVisibleForManager`, `isEditableForManager`, `isMandatoryForManager` | as above | MISSING → built (HP-8d) | Role `manager` (anyone `PerformanceRelationships::manages()` the owner). |
| … Approver — `isVisibleForApprover`, `isEditableForApprover`, `isMandatoryForApprover` | No goal-plan approval step (`Goals::submitPlan()` activates drafts directly; PF-01 PARTIAL) | MISSING → built (HP-8d) | Role `approver` + field `plan_approval` (none/manager/workflow, default none = today); `submitPlan()` moves drafts to `pending_approval` (new goal status) and an Approval Center source / workflow subject `Goal plan` decides. |
| Performance review configuration — Employee / Manager / Approver, each: KRA / KPI (level rated), Percentage / Custom (input type), Upload (evidence) | Reviews rate each goal (not key results) on the cycle scale (`PerformanceActions::reviewForm`); levels = self, manager, peers; no percentage input, no attachments | PARTIAL → built (HP-8d; approver = new stage `approver_review`; evidence kept on the review, listed by name) | Fields per level (`self`, `manager`, `approver`): `review_level` (goal/key_result, default goal), `review_input` (scale/percentage, default scale), `evidence` (off/optional/required, default off). `appraisal_ratings.subject_type` already generic → add `key_result`; add `achievement_pct` (decimal, nullable); evidence via Documents on the review. "Approver" = a third review level (`review_types` gains `approver`, stage `approver_review`). |
| Performance scorecard configuration — hide/unhide fields with the eye icon/toggle (Reviewer Rating, KRA Details, Competency Details, KRA, Competency) | Fixed `AppraisalResource` infolist; template `sections` choose what a review covers | PARTIAL → built (HP-8d) | Field `scorecard_hidden` (checkboxes: reviewer_ratings, goal_details, competency_details, peer_ratings, computed_rating, goal_score, competency_score) applied to what the *employee* sees on `AppraisalResource` and Employee 360; HR/managers unaffected. |

**Step 2b — Weightage rules (shown on the Review step; `<app-goal-sheet-review>` controls)**

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| KRA details — `keyResultAreaDetails` — weight mode Any / Specific / Equal | Free goal weights (0–100); no "equal" mode | PARTIAL → built (HP-8d) | Field `goal_weight_mode` (any/specific/equal, default any). `equal`: `Goals` sets every goal's weight to total ÷ count on create/close. |
| Total KRA weightage — `enableTotalKeyResultAreaWeightage` + `totalKeyResultAreaWeightage` ("Specify value") | `goal_rules.required_total_weight` / cycle `required_goal_weight` → `Goals::requiredWeight`, `assertWithinWeightTotal`, `submitPlan` | HAVE → built (HP-8d: the policy's total first) | Field `total_goal_weight` (number, empty = none) taking precedence over template/cycle in `Goals::requiredWeight()`. |
| Single KRA weightage — `enableSingleKeyResultAreaWeightage` + `minSingleKeyResultAreaWeightage`, `maxSingleKeyResultAreaWeightage` (Minimum value / Maximum value) | Only 0–100 bounds | MISSING → built (HP-8d) | Fields `min_goal_weight`, `max_goal_weight` (0 = none) checked in `Goals::create/update`. |
| Number of KRAs — `enableNoOfKeyResultArea` + `minNoOfKeyResultArea`, `noOfKeyResultArea` (Minimum number / Maximum number) | — | MISSING → built (HP-8d) | Fields `min_goals`, `max_goals`; max checked on create, min on `submitPlan()`. |
| KPI details — `keyPerformanceIndicatorDetails` — Any / Specific / Equal; "All KPI of equal weightage" | Key results with weights; progress rolls up by weight (`Goals::recompute`); no rules | PARTIAL → built (HP-8d) | Field `kpi_weight_mode` (any/specific/equal; `equal` auto-splits key-result weights). |
| UOM and target mandatory — `isUnitOfMeasurementAndTargetMandatory` — Yes / No | `target_value` required (default 100); `unit` optional free text | PARTIAL → built (HP-8d) | Field `unit_and_target_required` (yes/no, default no) → `unit_id` (§12.8) and target required on goals and key results. |
| Total KPI weightage within a KRA — `enableTotalKeyPerformanceWeightage` + `totalKeyPerformanceWeightageWithinKeyResultArea` (options incl. "Equal to KRA weightage") | Key-result weights not validated | MISSING → built (HP-8d) | Field `kpi_total_weight` (none / equal_to_goal / 100). |
| Single KPI weightage — `enableSingleKeyPerformanceWeightage` + `minSingleKeyPerformanceWeightage`, `maxSingleKeyPerformanceWeightage` | — | MISSING → built (HP-8d) | Fields `min_kpi_weight`, `max_kpi_weight`. |
| Number of KPIs — `enableNoOfKeyPerformance` + `minNoOfKeyPerformance`, `noOfKeyPerformance` | — | MISSING → built (HP-8d) | Fields `min_kpis`, `max_kpis` per goal. |
| Include competency — `isIncludeCompetency` — Yes / No | Per cycle / template: `competency_ids` and `weights.competencies` (set 0 or empty to exclude) | PARTIAL → built (HP-8d) | Field `include_competencies` (yes/no, default yes); `Appraisals::score()` ignores competencies for employees whose policy says no. |
| Competency details — `goalSheetCompetencyDetails` (which competencies) | Same competency set for everyone in the cycle | PARTIAL → built (HP-8d) | Field `competency_codes` (multiselect, `options_from: competencies`; empty = the cycle's set). The review form and `score()` use the employee's set. |
| Total competency weightage — `enableTotalCompetencyWeightage` + `totalCompetencyWeightage` | `weights.competencies` = blend weight of the competency block (default 30) | PARTIAL → built (HP-8d) | Field `competency_block_weight` (empty = cycle weight). |
| Single competency weightage — `enableSingleCompetencyWeightage` + `minSingleCompetencyWeightage`, `maxSingleCompetencyWeightage` | `competencies.weight` stored but unused (defect 3) | MISSING → built (HP-8d) | Fields `min_competency_weight`, `max_competency_weight`; `averageScore()` becomes weighted when weights exist (unweighted otherwise, as today). |
| Number of competencies — `enableNumberOfCompetency` + `minNumberOfCompetency`, `numberOfCompetency` | — | MISSING → built (HP-8d) | Fields `min_competencies`, `max_competencies` (validated when the set is chosen). |

**Step 3 — Other setting (`<app-goal-sheet-advance-setting>`), Applicability, Advance applicability, Review**

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Do you want to copy last level review inputs (achievement, comments etc.) to the next level? — `isAllowToCopyLastLevelReviewInputs` — Yes / No | The manager review form starts empty; the manager can read the self review in the Reviews tab | MISSING → built (HP-8d) | Field `copy_goal_inputs` (yes/no, default no; tenant default from `performance.review.copy_previous_level`). `PerformanceActions::reviewForm()` pre-fills goal ratings/comments from the previous submitted level (self → manager → approver). |
| Do you want to copy last level competency inputs (achievement, comments etc.) to the next level? — `isAllowToCopyLastLevelCompetencyInputs` — Yes / No | as above | MISSING → built (HP-8d) | Field `copy_competency_inputs` (same mechanism for competency rows). |
| Applicability (`<app-ou-applicability>`, "Search here") | Policy assignment rules | HAVE | Platform. |
| Advance applicability (Optional) — `from`, `to` | Rule operators + version effective dates | HAVE | Platform. |
| Review (summary of everything above) | Version view + `ImpactPreview` | HAVE | Platform. |

### 12.4 Goal → Goal library (`<app-goal-bank>`, `<app-kra>`, `<app-competency>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| KRA — Tag `keyResultAreaTag` | `kras.category` (free text) in `KraResource` | HAVE | Optional: relabel "Category / tag"; switch to `TagsInput` (json `tags`) only if several tags per KRA are wanted. |
| KRA — name `keyResultAreaName` | `kras.name` (+ `code`, `description`, `default_weight`, `status`) | HAVE | — |
| KRA — KPI details `keyPerformanceIndicatorDetails` / `keyPerformanceIndicatorName` | `kras.kpis` repeater (name, measure, target) | HAVE → built (HP-8a/HP-8c: per-KPI unit, reverse, formula) | Add per-KPI `unit_id`, `is_reverse`, `kpi_rating_formula_id` when §12.8/§12.17 land, so a goal picked from the library inherits them. |
| Competency — Tag `competencyTag` | `competencies.category` limited to `peopleos.performance.competency_categories` (core, functional, leadership, behavioural) | PARTIAL → OPEN — not in HP-8a…c (json tags) | Add json `tags` (TagsInput) for tenant-defined tags; keep `category`. |
| Competency — name `competencyName`; "Tip:" helper | `competencies.name` (+ code, level, weight, effective dates, description, behavioural indicators) in `CompetencyResource` | HAVE | — |
| Library counts ("## ITEMS", "NO KRA YET") | Resource tables with counts | HAVE | — |

### 12.5 Goal → Goal template (`<app-goal-template>`, `<app-goal-template-form>`, `<app-goal-template-filter>`)

"Goal templates created here can be used to auto-assign goal sheets or select at the time of manual goal creation." PeopleOS `PerformanceTemplate` is a *review* template (sections, scale, workflow, weights); there is no set of goals to assign (PF-09 PARTIAL).

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| List (ADD TEMPLATE, "NO GOAL TEMPLATE") and filter — Template name ("Select template"), Status | — | MISSING → built (HP-8e) | `GoalTemplateResource` (Performance → Goal templates; Setup link), permission `performance.manage`. |
| Step *Settings* (name, description, status) | — | MISSING → built (HP-8e) | Table `goal_templates` (tenant, code, name, description, status, feedback_form_id nullable). |
| Step *Add goals* (KRAs and KPIs with weights, from the library or new) | KRA library suggests KPIs; goals pick one KRA (`goals.kra_id`) | PARTIAL → built (HP-8e) | Table `goal_template_items` (template, parent_item_id for KPI-under-KRA, kra_id, type goal/kpi, title, description, measure_type, unit_id, start/target, weight, is_reverse, perspective_id, sort_order); "Add from library" action copies a KRA and its KPIs. |
| Step *Map template* (to whom it applies for auto-assignment) | Cycle eligibility rules exist (`RuleConditionsSchema`) | MISSING → built (HP-8e) | `goal_templates.applicability` (json rule conditions, evaluated with `RuleEngine` + `EmployeeRuleContext`) — or mapped through the goal-sheet policy's `goal_template_code` (§12.3); `GoalTemplates::assign()` uses `Goals::create` (source `system`, status draft) so all goal rules apply. Also a "Use template" picker on Goal create. |
| Step *Review* | — | N-A | Summary only. |
| Step *Feedback template* (feedback form attached to the goal sheet) | Forms engine (`Form`, `FormVersion`, `Forms` service) exists; not linked to goals | PARTIAL → built (HP-8e: a feedback template of the P13 Feedback domain, process `goal_review`, asked of the manager at the manager review) | `goal_templates.feedback_form_id` → shown on check-ins/reviews for goals created from the template. |

### 12.6 Goal → Performance terminology (`<app-performance-methodology>`; control `displayName` per row: Label · Original label · Description; "COPY OKR LABELS"; EDIT)

PeopleOS labels are fixed in code/config (`peopleos.performance.goal_types`: Goal, Objective (OKR), KRA, KPI; resource labels). Proposal: settings `performance.labels.<key>` (text, default `null` = built-in label) + helper `App\Domain\Performance\Support\PerformanceLabels::get('kra')` used by Goal/KRA/Competency/UOM resources, review form, scorecard, reports and notifications.

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| KRA — `displayName` (description: Key Responsibility Area …) | "KRA" fixed (`goal_types.kra`, `KraResource` "KRA library") | MISSING → built (HP-8a) | `performance.labels.kra`. |
| KPI — `displayName` | "KPI" / "Key results" fixed | MISSING → built (HP-8a) | `performance.labels.kpi`. |
| Competency — `displayName` | fixed | MISSING → built (HP-8a) | `performance.labels.competency`. |
| Perspectives — `displayName` | concept absent (§12.7) | MISSING → built (HP-8a) | `performance.labels.perspective`. |
| UOM — `displayName` | "Unit" free text | MISSING → built (HP-8a) | `performance.labels.uom`. |
| Target — `displayName` | "Target value" fixed | MISSING → built (HP-8a) | `performance.labels.target`. |
| Feedback — `displayName` (feedback template) | "Feedback" fixed | MISSING → built (HP-8a; used on the 1-on-1 feedback card; goal templates in HP-8e) | `performance.labels.feedback`. |
| Achievement — `displayName` | "Current value / progress" fixed | MISSING → built (HP-8a) | `performance.labels.achievement`. |
| KPI due date — `displayName` | "Due date" fixed | MISSING → built (HP-8a) | `performance.labels.kpi_due_date`. |
| COPY OKR LABELS (`isOkrLabel`) | `goal_types.okr` exists as a goal type, not as terminology | MISSING → built (HP-8a) | Header action on the Setup centre Performance module "Use OKR terms" → sets kra = Objective, kpi = Key result, achievement = Progress (audited like any setting change). |

### 12.7 Goal → Perspective (`<app-perspective>`; `groupName`; defaults Customer, External Process, Financial, Internal process, Learning and growth; catalogue `isActive`, `isGroupSystemDefined`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Perspective list + Add Perspective — `groupName` | None (KRA `category` is free text) | MISSING → built (HP-8a) | Table `performance_lookups` (tenant, kind ∈ perspective / initiative_type / pip_reason, code, name, description, is_system, status, sort_order); `PerformanceLookupResource` with a tab per kind; seeds the five balanced-scorecard perspectives in `PerformanceDefaults::seed()`. |
| Active / system-defined flags | — | MISSING → built (HP-8a) | `status`, `is_system` (system rows can be deactivated, not deleted). |
| *(use)* perspective on KRA/KPI | — | MISSING → built (HP-8a; goal-sheet field rights in HP-8d) | `kras.perspective_id`, `goals.perspective_id` nullable; select on `KraResource` / `GoalResource`; available to `field_rules` (§12.3) and the goal dataset for reports. |

### 12.8 Goal → Unit of measurement (`<app-uom>`, `<app-uom-form>`; defaults Percent %, INR, Rating TRL, Units)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Label (`unitOfMeasurementName`) and Code (`unitOfMeasurementCode`) | `goals.unit` / `key_results.unit` free text (max 32) + `measure_type` from `peopleos.performance.measure_types` (percentage, numeric, currency, boolean, milestone) | PARTIAL → built (HP-8a) | Table `performance_units` (tenant, code, name, measure_type, default_target, is_default, is_system, created_by, applicability json, status) + `PerformanceUnitResource`; `goals.unit_id`, `key_results.unit_id` nullable (keep `unit` text for history). Seed Percent / Amount (tenant currency) / Rating / Units. |
| Default value (`defaultTargetValue`) | Target defaults to 100 in the form | PARTIAL → built (HP-8a) | `default_target` pre-fills the goal form when the unit is chosen. |
| Progress is calculated as (`okrCalculationOn`) | `Goal::progressFor()` by measure type (boolean, linear start→target, clamp 0–100) | PARTIAL → built (HP-8a; reverse is a per-goal flag, HP-8c) | `measure_type` on the unit drives `progressFor()`; add option `reverse` (§12.0). |
| Admin / User tabs (`isAdmin`, `uomCreatedBy`) — units created by admins vs by users | — | MISSING → built (HP-8a) | Setting `performance.units.employee_can_add` (boolean, default `false`); user-created units have `created_by` and are visible to their creator (and HR). |
| Step *OU Mapping* (`isUomSpecific`, `performanceOrganization`, `employeeNames`, `employeeDetails`) | — | MISSING → built (HP-8a) | `applicability` json (rule conditions); the goal form offers only units that match the goal owner. |
| Default unit (`isDefaultUnitOfMeasurement`) | — | MISSING → built (HP-8a) | `is_default` (one per tenant) pre-selected on new goals. |
| Active (`isActive`), system-defined (`isSystemDefined`) | — | MISSING → built (HP-8a) | `status`, `is_system`. |
| Step *Review* | — | N-A | Summary only. |

### 12.9 Initiative types (`<app-initiative-type>`; `initiativeTypeDisplayName`; defaults BUDDY, INDUCTION, INITIATIVE, PIP, TNI)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Initiative type list + Add initiative — `initiativeTypeDisplayName` (catalogue `isActive`, `isSystemDefine`) | No initiatives (PF-05 MISSING); PIPs and development needs are separate models | MISSING → built (masters HP-8a; initiatives HP-8j) | `performance_lookups` kind `initiative_type`, five system rows seeded. Useful only with the Initiative record itself (PF-05): table `initiatives` (employee, type_id, title, description, owner, start/due, status, goal_id, outcome) + feedback (`initiative_feedback`), Me/Team screens, notification "Initiative". |

### 12.10 PIP → PIP policy (`<app-pip-policy>`, `<app-pip-pol-form>`, `<app-pip-pol-filter>`)

HROne reuses induction/transfer step components here (control names `inductionPolicyName`, `isTransferLetterRequired` …); the build notes give the PIP meaning. PeopleOS PIPs: `ImprovementPlans` (open, activate, extend with reason, checkpoints, close completed/unsuccessful/cancelled, close-out), `ImprovementPlanResource` (end date defaults to +2 months), permission `performance.pip`; no policy.

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| PIP policy list ("NO PIP POLICY", ADD POLICY) and filter — Policy ("Select policy"), Status | — | MISSING → built (HP-8f) | Policy type `pip`. |
| Wizard: Settings · Advance settings · Applicability · Advance Applicability · Review; Next / Previous / Reset | Policy form | HAVE | Platform (sections). |
| Policy name `inductionPolicyName`; Description `inductionPolicyDescription` | `Policy.name/description` | HAVE | Platform. |
| Period (in days) — `inductionPriorDays` (labelled "Induction period (in days)") | Form default end = start + 2 months; no rule | PARTIAL → built (HP-8f) | Field `duration_days` (number, default 60 ≈ today); `ImprovementPlanResource` defaults `end_date` from the employee's policy; field `max_extensions` (0 = unlimited, today). |
| Would you like to send letter? — `isTransferLetterRequired` — Yes / No; `letters`, `letterTypeId` ("Select letter"), `letterTemplateId` ("Letter template") | Letters exist (`Letters::generate($template, $employee, $extra, $requester, $source)`), but `peopleos.letters.types` has no PIP or performance types (PF-07 PARTIAL) | MISSING → built (HP-8f) | Add letter types `pip` (PIP letter), `pip_outcome` (PIP evaluation letter), `performance` (Performance letter). Fields `letter_on_activate` (yes/no) + `activate_letter_template` and `letter_on_close` + `close_letter_template` (`options_from: letter_templates`, filtered by type). `ImprovementPlans::activate()` / `close()` call `Letters::generate(..., source: $plan)` (letter approval flow unchanged). |
| Would you like to map pre … checklist? — `isPreChecklistMapped`, `preChecklistId` ("Select pre checklist") | PIP checkpoints are dated reviews; no task checklist | MISSING → built (HP-8f) | Field `start_checklist` (repeater: title, due_offset_days, owner manager/hr/employee) → creates `ImprovementPlanCheckpoint`s or workflow tasks on activate. |
| Would you like to map post … checklist? — `isPostChecklistMapped`, `postChecklistId` ("Select post checklist") | — | MISSING → built (HP-8f) | Field `close_checklist` (same shape) → tasks on close. |
| Would you like to map allowance? — `isAllowanceMapped`, `allowance`, `allowanceId` ("Allowance"), `amount` ("Enter amount"), `allowanceVariableId` ("Variable") | — | N-A | Transfer-allowance component shared by HROne's policy forms; no PIP meaning. |
| Applicability (`<app-ou-applicability>`) | — | HAVE | Platform once the type exists. |
| Advance applicability — `from`, `to` | — | HAVE | Platform. |
| Review — Condition, Operator, Value, Role | PIPs open directly (no approval; HROne has "Pip request" / "Pip evaluation request" workflows) | PARTIAL → built (HP-8f) | Field `approval` (none/workflow, default none). Workflow subject type `ImprovementPlan` + trigger `performance.pip.requested`; evaluation at close as a workflow step; Approval Center source. |

### 12.11 PIP → PIP reason (`<app-pip-reason>`; `pipReasonTagName`; defaults Absenteeism, Inappropriate attitude, Insubordination, Punctuality, Unsatisfactory performance)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| PIP reason list + Add Pip Reason — `pipReasonTagName` (catalogue `isActive`) | `improvement_plans.reason` required free text | MISSING → built (HP-8a) | `performance_lookups` kind `pip_reason`, five system rows seeded; `improvement_plans.reason_codes` json (several tags) chosen in `ImprovementPlanResource` (free-text `reason` stays as details); PIP dataset gains the reasons. |

### 12.12 Performance → Performance policy (`<app-goal-policy-layout>`, `<app-goal-policy-form>`, `<app-goal-policy-filter>`; card shows Performance cycle Yearly · Goal cycle Monthly · Review cycle Monthly; catalogue `policyName`, `isActive`, `appraisalType`, `goalCycle`, `reviewCycle`, `performanceCyclePeriod`)

PeopleOS runs one appraisal per `PerformanceCycle` (goal setting → self/manager/peer review → calibration → final). HROne's policy adds periodic goal and review cycles inside the performance year that roll up into the appraisal.

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Policy list (ADD POLICY, card with cycle frequencies) and filter — Policy name ("Select performance policy"), Status | Cycles list (`PerformanceCycleResource`) | PARTIAL → built (HP-8i: policy type `performance`) | Policy type `performance`. |
| Step *Goal* — goal cycle (`goalCycle`, e.g. Monthly) and goal-setting rules | One `goal_setting` stage window per cycle | PARTIAL → built (HP-8i, PF-6: goal window applied, feedback template on the performance review asked of the manager; goal cycle frequency, manual goal initiation, new joiners and start month recorded on the policy) | Fields `goal_cycle` (yearly/half_yearly/quarterly/monthly, default yearly = today) and `goal_window_days`. |
| Step *Goal review* — review cycle (`reviewCycle`, e.g. Monthly), levels | Reviews once per cycle; `PerformanceCheckIns` (monthly cadence) carry no rating | PARTIAL → built (HP-8i, PF-7 / PF-8 / PF-9: periodic reviews `performance_period_reviews` with windows, automatic or manual initiation; sum of all goals; ratings above the scale; copy last level; per-level visibility of ratings, final rating, final comments; TNI / promotion / increment / variable recommendations) | Fields `review_cycle` (yearly/…/monthly, default yearly), `review_levels` (self/manager/approver/peer), `review_window_days`. Periodic reviews: add `rating`, `ratings` json and `performance_cycle_id` usage to `performance_check_ins`, or child cycles (`performance_cycles.parent_id`); service `PerformanceCycles::periodsFor()`. |
| Step *Appraisal* — appraisal type (`appraisalType` yearly/half-yearly) and how periodic reviews feed the final rating | `Appraisals::score()` blends goal and competency scores with cycle weights (default 70/30) | PARTIAL → built (HP-8i, PF-10 / PF-11: average / last / custom roll-up with the appraisal weightage, goal / competency weightage, eligibility by joining date, performance letter; blank = today) | Fields `appraisal_basis` (final_review/average_of_periodic_reviews, default final_review = today), `goal_weight`, `competency_weight` (defaults `peopleos.performance.default_weights`). |
| Steps *Applicability*, *Advance applicability*, *Review* | Cycle `eligibility` rule conditions | HAVE | Platform once a policy. |

### 12.13 Performance → 9 box (`<app-nine-box>`; Performance cycle picker; KRA scale rows 1 Below Average … 5 Outstanding → Status select; Competency scale rows → Status; SAVE; catalogue `scaleName`, `scaleType`, `fromScale`, `toScale`, `scaleRatingType`, `priorityStatus`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Performance cycle picker | Talent assessment model "Performance and potential" (`peopleos.talent.default_model`, 3 × 3 levels, `TalentAssessmentModel`) assessed by hand; `career-talent-succession.md`: "there is no visual 9-box grid"; nothing per performance cycle | PARTIAL → built (HP-8g) | Page `App\Filament\Pages\NineBoxGrid` (Performance → 9-box) with a cycle picker; access `performance.calibrate` or `performance.analytics` (names only with `performance.view`/calibrate; groups below `performance.analytics_min_group` suppressed). |
| KRA scale → Status (Low / Moderate / High per level) | — | MISSING → built (HP-8g) | Table `performance_nine_box_bands` (tenant, performance_cycle_id, axis goals/competencies, from_value, to_value, band low/moderate/high), editable after launch (analysis, not part of the pinned version). Default bands = lower/middle/upper third of the cycle scale, so the grid works with no setup. |
| Competency scale → Status | — | MISSING → built (HP-8g) | Same table, axis `competencies` (uses the competency scale of §12.16 when set). |
| Grid (performance × potential) | — | MISSING → built (HP-8g; performance = the rating that stands, potential = competency rating or talent potential; "record as talent assessment" not built) | Places each appraisal by goal rating (`goal_score` → scale) × competency rating; optional action "Record as talent assessment" writing a `TalentAssessment` on the default model. |

### 12.14 Performance → Bell curve (`<app-bell-curve>`; Performance cycle picker; per scale level "Performance rating of employee" target; Graph; SAVE; catalogue `scaleValue`, `scaleMasterDetails`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Performance cycle picker | `CalibrationBoard` cycle picker | HAVE | — |
| Target % of employees per rating level | `Appraisals::distribution()` gives actual counts only; docs state no forced distribution (PF-03 PARTIAL) | MISSING → built (HP-8g) | Table `performance_distribution_targets` (tenant, performance_cycle_id, level_value, target_pct), sum = 100 validated; editable after launch. |
| Graph | Distribution shown as counts on `CalibrationBoard` | PARTIAL → built (HP-8g) | Chart widget on `CalibrationBoard`: target vs actual per level. |
| Enforcement (implied) | — | MISSING → built (HP-8g) | Setting `performance.distribution.check` (off/warn/block, default `off`): `warn` shows the gap on finalize/close; `block` refuses closing a cycle outside a tolerance `performance.distribution.tolerance_pct` (default 5). Default keeps PeopleOS's "no forced ranking". |

### 12.15 Review → Review rating (`<app-review-rating>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Select period — `performanceCycleId` (scale per performance cycle) | `performance_cycles.rating_scale_id`, pinned at launch | HAVE | — |
| Scale — `scaleValue` (number of points, e.g. 5) | `RatingScaleResource` levels repeater (≥ 2 levels, any values) | HAVE | — |
| Scale rows — `scaleRatingDetails`: Label `scaleName`, Description `scaleDescription` | `rating_scales.levels[].label/description` | HAVE | — |
| Range per level — `fromScale`, `toScale` | `RatingScale::labelFor()` picks the nearest level value | PARTIAL → built (HP-8c) | Optional `from`/`to` per level in the repeater and in the version snapshot; `labelFor()` uses the containing range when ranges exist, else nearest (today). |
| UPDATE | Edit allowed until a launched cycle uses the scale (then create a new scale) | HAVE | — |

### 12.16 Review → Competency rating (`<app-competency-rating>`; same controls)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Select period — `performanceCycleId` | Competencies are rated on the cycle's single scale | PARTIAL → built (HP-8c) | `performance_cycles.competency_rating_scale_id` nullable (null = same scale, today) + `competency_scale_snapshot` on `performance_template_versions`; locked with the rest of the cycle. |
| Scale — `scaleValue` | One scale for goals and competencies | PARTIAL → built (HP-8c) | Uses any `RatingScale`; `PerformanceActions::reviewForm()` uses its options for competency rows; `averageScore()` already normalises to % per scale. |
| Label / Description — `scaleName`, `scaleDescription` | via the shared scale | PARTIAL → built (HP-8c) | As above. |
| Range — `fromScale`, `toScale` | nearest value | PARTIAL → built (HP-8c) | Same range support as §12.15. |

### 12.17 Review → KPI rating formula (`<app-kpi-rating-formula>`, `<app-kpi-rating-formula-form>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| List ("NO KPI RATING FORMULA") and filter — Formula ("Select formula"), Status Active / Inactive | — | MISSING → built (HP-8c) | `KpiRatingFormulaResource` (Performance → KPI rating formulas). |
| Select the formula for KPI rating — `customRatingFormulaBasedOn` — Achievement/Target · Target/Achievement | Progress = (current − start) ÷ (target − start), clamped 0–100 (`Goal::progressFor`); never turned into a rating | MISSING → built (HP-8c) | Table `kpi_rating_formulas` (tenant, code, name, based_on achievement_over_target/target_over_achievement, bands json, status). Achievement must not be clamped at 100 here (use raw ratio). |
| Formula name/code — `customCodename` | — | MISSING → built (HP-8c) | `code`, `name`. |
| Rating calculation formula — `customRatingFormulaDetails`: `fromRatingScale`, `toRatingScale` (achievement band), `displayRatingScale` (rating given) | — | MISSING → built (HP-8c) | `bands` [{from_pct, to_pct, rating}] validated against the cycle scale. `goals.kpi_rating_formula_id` (default from KRA KPI or unit); service `KpiRatings::suggest(Goal)`; review form shows it when `performance.review.show_formula_rating` is on; setting `performance.review.formula_rating_mode` (suggest/prefill/lock, default suggest). |

### 12.x Shared panels inside `<app-common-settings>` on the Performance pages

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| `<app-time-office-setting>` (enableArrear … isHolidayFinancialYear) | Covered in the Time office section | N-A | Other section. |
| `<app-task-setting>` (groups, settings, status, declarationText; "apply this change to New Employee / All employee") | Generic task declaration panel | N-A | Other section (Automation). |
| `<app-payroll-setting>` (specialAllowancePartOfProvidentFund … showPayByDefaultOnReimbursementReport) | Payroll section | N-A | Other section. |
| `<app-help-desk-settings>` (showSLAStatus, isFeedbackMandatory) | Helpdesk section | N-A | Other section. |
| `<app-timesheet-setting>` (groups, settings, status, conditionalTimesheetProjects) | Timesheet section | N-A | Other section. |
| `<app-asset-setting>` (onboardingDueDays … isRecoveryMandatory) | Asset section | N-A | Other section. |
| `<app-recruitment-settings>` | Recruitment is out of scope (RMS) | N-A | — |
| `<app-expense-settings>` (isProjectBasedApproval … isAllowDuplicateInvoiceNo) | Expense section | N-A | Other section. |

---

## 13 Engage

> **Progress (2026-10-29, HP-9a / HP-9b / HP-9c built).** Celebrations: work and wedding anniversaries beside birthdays
> (off by default; greeting, nudges, roles told, wishes, a Celebrations tab per occasion, Home), roles told of
> birthdays, "Preview messages", the first-login welcome switch and text. HR handbook: due days (My tasks due date and
> reminders), download of the published version, "Who acknowledged" + CSV, created / published date filters. Mission
> statement and core values per audience on Home. Badges: HROne's nine system badges, custom badges with every rule
> (givers, receivers, nature, receiving frequency, reason, attachment, certificate through Letters), the monthly
> quota, withdrawal, the wall of fame, LinkedIn and certificate settings, the badges ledger report, permissions
> `recognition.*`. Still open in section 13: WhatsApp delivery (IP-07 EXT), reward points and badge-linked points
> (HP-9d), the social wall and its settings (HP-9e), the mood bot (HP-9f). Rows built are marked "→ built (HP-9x)" in
> the Status column. Details: `docs/architecture/hrone-parity.md`.
>
> **Progress (2026-10-29, HP-9d / HP-9e / HP-9f built).** Reward points: the one-time points value and currency
> (`locked_after`), the points budget, the reward points policy (credit cycle, points per cycle, reward limits, carry
> forward, expiry, eligibility rows) with the daily credit run, awards between colleagues, badge-linked points (shown on
> the wall when asked) and redemption through payroll (`RewardPointsInput`, off by default, payroll unchanged otherwise).
> Social wall: posts (HR posts, badges, rewards, celebrations, announcements, new joiners, achievements), reactions,
> comments with per-type restrictions, moderation and reports, wall scope, festive greeting, attachments, Home card.
> Happiness index: the mood bot (frequency, weekday, privacy), reason tags (20 active, 14 seeded), check-ins through
> the engagement anonymity boundary, Home question, dashboard and dataset. Still open in section 13: WhatsApp delivery
> (IP-07 EXT), vendor vouchers for redemption (EXT), skill endorsement posts (no endorsement feature), a festive colour
> theme (needs a theme rebuild; built as a greeting).

PeopleOS has no recognition/points/wall domain. Proposal: a new domain `app/Domain/Recognition` (badges, recognitions, points ledger, wall) next to `Engagement` (surveys, mood), with permissions `recognition.give`, `recognition.manage`, `recognition.points`, `wall.post`, `wall.moderate`, `engagement.mood` (employee/manager/HR role templates updated), module `engage` in the Setup centre extended with these settings and policy types.

### 13.0 Engage — Settings panel (`<app-eng-setting>`, gear on every Engage page) and catalogue "Engage setup"

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Set the monetary worth of each point — `points` ("Set points value") | No points | MISSING → built (HP-9d) | Setting `engage.points.value_per_point` (number, default `null` = points carry no money value). Built (nullable number; locked once the ledger has a row). |
| Currency — placeholder "Select currency" | `tenant.base_currency` (INR) exists | PARTIAL → built (HP-9d) | Setting `engage.points.currency` (default `null` → `tenant.base_currency`). Built (ISO code; locked with the value; payroll redeems only in the base currency). |
| "Note: This is a one-time setting which cannot be undone." | Setup centre settings are always editable | MISSING → built (HP-9d) | Generic: `'locked_after' => <callable/check>` on a Setup catalogue entry, enforced in `SetupCatalogue::save()` (refuse once a points ledger row exists). Built: `locked_after` => [RewardPoints::class, `ledgerStarted`]; the Setup centre shows it read-only once locked. |
| *(catalogue)* `availablePoints`, `currentDeposit` (company points budget) | — | MISSING → built (HP-9d) | Budget ledger rows (`reward_point_entries` kind `deposit`) and a balance on the Reward points screen. Built: deposits (`recognition.points`), the budget balance on Reward points; credits and badge points are paid from it. |
| *(catalogue)* `isShowSelfRedeemPoint`, `pointRedeemType`; Redeem types (`pointsRedeemType`, `vendorTitle`, `vendorLogo`, `bulkTransaction`) | — | MISSING → built (HP-9d) except vendor vouchers (EXT) | Settings `engage.points.redeem_modes` (multiselect: payroll/voucher, default `[]`); payroll redemption through a `PayrollInputSource` (`RewardPointsInput`, taxable earning `REWARD`); vendor vouchers = EXT. Built: `engage.points.redeem_modes` (payroll), `engage.points.self_redeem`, `RewardPointsInput` (taxable `REWARD`). Vendor vouchers wait for a voucher provider. |
| *(catalogue/inventory)* `isCustomBadgesAdded` ("Do you want to allow to create custom badges?") | — | MISSING → built (HP-9c) | Setting `engage.badges.custom_allowed` (boolean, default `true`). |
| *(catalogue)* `isCustomBadgesPeriodCalendar`, `isPeriodOnceAdd` (frequency counted per calendar period vs rolling) | — | MISSING → built (HP-9c) | Per-badge `receive_period_basis` (calendar/rolling) — §13.2. |
| *(catalogue)* `isShowAttachment` | — | MISSING → built (HP-9e) | Setting `engage.wall.attachments` (boolean, default `true`). Built: attachments on HR posts while on. |
| *(catalogue)* `handbookDownloadAllowed`, `wallApplicability`, `restrictedCommentType`, `showFestiveTheme`, `linkedinClientName`, `badgeShareAllowed`, `letterTemplateName` | Counted where they belong | N-A | Covered in §13.4 and §13.8. |

### 13.1 Badges → Badges (`<app-badge-setting>`, `<app-badges-default>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Tabs Badges / Custom Badges; Type filter (All, Badges, Custom Badges) | Closest: performance feedback `type = praise`, visibility public (`FeedbackEntryResource`) — no badge | MISSING → built (HP-9c) | `BadgeResource` (Engage → Badges) with tabs *System* and *Custom*. |
| Badge quota — Badge per month — `badgePerMonth` (required; tenant shows 10) | — | MISSING → built (HP-9c) | Setting `engage.badges.per_month` (number, default 10): badges one person may give per calendar month (tenant timezone), checked in `Recognitions::give()` under a row lock. |
| Badge allowed — YES/NO per system badge: Super Woman, Making an Impact, Making Work Fun, Amazing Mentor, Great Job, Team Player, Thank You, Going Above and Beyond, Out of the Box Thinker | — | MISSING → built (HP-9c) | Table `badges` (tenant, code, name, description, icon/image, colour, is_system, status + custom-badge fields of §13.2); the nine seeded active by a `RecognitionDefaults::seed()`; the toggle = `status`. Ledger `recognitions` (badge, giver, receiver, message, visibility, points, attachment, created_at; append-only), Me → *Give a badge*, Home "Wall of fame" (EN-03), report "Badges ledger" (dataset). Built: badges, recognitions ledger, Recognition → Give a badge, Home "Wall of fame", dataset `badges_ledger`. |
| FINISH | — | N-A | — |

### 13.2 Badges → Custom badges (`<app-badges-custom>`, `<app-custom-badge-add>`, `<app-step-3>`, `<app-step-review>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Wizard: Appearance · Badge applicability · Reward applicability · Review; Previous / Next | — | MISSING → built (HP-9c) | Sections on the `BadgeResource` form for custom badges. |
| Step *Appearance* (name, image/icon, description) | — | MISSING → built (HP-9c) | `badges.name`, `description`, `icon` (heroicon) or uploaded image (Documents storage, image MIME check), `colour`. Built with an icon (16 heroicons) and a colour; an uploaded badge image is not offered (every render would be an audited file download). |
| Step *Badge applicability* (who can give / receive) | Audience criteria exist (`AudienceCriteriaSchema`, `RuleConditionsSchema`) | MISSING → built (HP-9c) | `badges.givers` and `badges.receivers` (json rule conditions; empty = everyone) + `giver_roles` (roles); checked in `Recognitions::give()`. |
| Nature of Badge — Public / Private | Feedback visibility private/manager/public | MISSING → built (HP-9c) | `badges.visibility` (public/private; private = receiver, their manager, giver, HR). |
| Do you want to restrict the frequency of receiving custom badges for employees? — Yes / No (+ frequency) | — | MISSING → built (HP-9c) | `badges.receive_limit` (0 = none), `receive_period` (month/quarter/year/ever), `receive_period_basis` (calendar/rolling). |
| Is reason mandatory while giving this custom badge to employees? — Yes / No | — | MISSING → built (HP-9c) | `badges.reason_required`. |
| Is attachment mandatory while giving this custom badge to employees? — Yes / No | — | MISSING → built (HP-9c) | `badges.attachment_required` (Documents upload on the recognition). |
| Map certificate for this custom badge | Letter templates with type `appreciation` exist (`LetterTemplate`, `Letters::generate`) | PARTIAL → built (HP-9c) | `badges.certificate_template_id` (letter templates of new type `badge_certificate` or `appreciation`); `Recognitions::give()` generates the certificate letter (source = recognition) without approval when the template has `requires_approval = false`. |
| Do you want to link this badge with reward points? — `isLinkedReward` — Yes / No | — | MISSING → built (HP-9d) | `badges.reward_points` (0 = not linked). Built ("Reward applicability" section of a custom badge). |
| Reward amount — `rewardAmount` | — | MISSING → built (HP-9d) | Same field; posts `award_received` to `reward_point_entries` (needs §13.7). Built: `badge_award` from the budget on giving, returned on withdrawal. |
| Do you want to show reward amount on social wall? — `isRewardShowOnWall` — Yes / No | — | MISSING → built (HP-9d / HP-9e) | `badges.show_points_on_wall`. Built: the badge post carries the points. |
| Step *Review* | — | N-A | Summary. |

### 13.3 Celebration (`<app-celebration-setup>`; placeholder Role; catalogue `settingName`, `isActive`, `celebrationRoleDetail`, `achievementAttachmentDetail`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Do you want to send WhatsApp message of an employee's birthday to: [Role] — Yes / No; Preview Message | Birthdays: in-app + email (`celebration.birthdays.greeting_enabled`, `greeting_message`, `announce_to` none/manager/team, `digest_enabled`, `digest_lead_days`, `notify_admins_on_day`, `email`, `visible_to`). WhatsApp channel is a log stub (`notifications.channels.whatsapp` → `LogChannel`) | PARTIAL → built (HP-9a) except WhatsApp | Recipients by role: setting `celebration.birthdays.notify_roles` (type `roles`, default `[]`). WhatsApp waits for a provider (IP-07 EXT); then `celebration.birthdays.channels` (multiselect in_app/email/whatsapp). Preview: a "Preview" action on Birthdays → Set up rendering `greeting_message`. Built: `notify_roles` and "Preview messages"; WhatsApp still waits for a provider (IP-07 EXT). |
| … employee's work anniversary to: [Role] — Yes / No; Preview Message | Report-library templates only (`work_anniversaries_this_month`, `upcoming_work_anniversaries`); `birthdays.md`: "Work anniversaries are not celebrated" (EN-09 PARTIAL) | MISSING → built (HP-9a) | Generalise `People\Services\Birthdays` into a celebrations service: settings `celebration.work_anniversary.enabled` (default `false`), `.greeting_message`, `.announce_to`, `.notify_roles`, `.visible_to`; the hourly `peopleos:birthdays:send` run also emits `celebration.work_anniversary.*` and `employee.work_anniversary` (new workflow trigger); wishes table gets `kind` (birthday/work_anniversary/wedding_anniversary); Birthdays page → "Celebrations" with tabs. Built in-app (greeting, nudges, role lists, wishes, Celebrations tab, Home); the Birthdays page is renamed only while an anniversary is celebrated. |
| … employee's wedding anniversary to: [Role] — Yes / No; Preview Message | `people.marital_status` only; no anniversary date | MISSING → built (HP-9a) | `people.wedding_anniversary_month/day` + `wedding_anniversary_shared` (default false; day and month only, like birthdays), profile and hire-wizard fields, settings `celebration.wedding_anniversary.*` (enabled default `false`). Data inventory: personal. |
| Do you want to send welcome message when employee login for the first time? — Yes / No; Preview Message | Home shows a one-time welcome card (`Home::showWelcome()`, `ExperiencePreferences.welcomed_at`); fixed text, no switch | PARTIAL → built (HP-9a) | Settings `experience.welcome.enabled` (default `true` = today) and `experience.welcome.message` (textarea, `{name}`, `{organisation}`; default `null` = built-in text). |
| Do you want to show achievements on Social wall? — Yes / No (`achievementAttachmentDetail`) | No wall | MISSING → built (HP-9e) | Setting `engage.wall.show_achievements` (default `false`): system posts for completed goals, badges, learning certificates (needs §13.8). Built: completed goals (`performance.goal.completed`) and learning certificates; badges are posted whenever public. |
| Role selector (placeholder "Role") per message | Relationship-based recipients (manager/team), not roles | PARTIAL → built (HP-9a) | The `notify_roles` settings above (Setup centre supports type `roles`). |
| UPDATE (per question) | Birthdays → Set up / Setup centre save | HAVE | — |

### 13.4 HR Handbook (`<app-hr-handbook-policy>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Handbook list with SEARCH (placeholder "Search keyword") | `ArticleResource` (Engage → HR handbook and articles): title search, categories (`peopleos.kb.categories`), versions, review/publish, acknowledgement, mandatory reading, audience rules | HAVE | — |
| Filter — created date `createdFrom` | — | MISSING → built (HP-9a) | Date-range filter on `created_at` in `ArticleResource`. |
| Filter — publish status `status` (placeholder "Publish status") | Status filter (writers) | HAVE | — |
| Filter — published date `publishedFrom` | `published_at` column sortable, no filter | PARTIAL → built (HP-9a) | Date-range filter on `published_at`. |
| *(inventory)* Due days for HR handbook acceptance | Weekly reminder after `peopleos.servicedesk.policy_acknowledgement_reminder_days` (config, 7) via `ServiceDeskProcessor::acknowledgementReminders()`; no due date (EN-10 PARTIAL) | PARTIAL → built (HP-9a) | Setting `kb.acknowledgement.due_days` (number, default 7 = today's config) replacing the config read; due date shown on the My tasks item (`PolicyAcknowledgementTaskSource`). Built with default 0 = no due date (reminders after the 7-day config as before), so a due date on My tasks appears only once set. |
| *(catalogue)* `handbookDownloadAllowed` | Articles are Markdown, view only | MISSING → built (HP-9a) | Setting `kb.download_allowed` (default `false`); "Download PDF" action (existing PDF rendering used for letters). Built as a printable HTML page of the published version (PeopleOS has no PDF renderer; the browser saves it as PDF). |
| *(EN-10)* per-employee acknowledgement list | "Read / ack" counts column | PARTIAL → built (HP-9a) | "Who acknowledged" table action (audience members, read/ack time) + CSV. |
| *(notification)* Ack HR Handbook | Acknowledgement reminders exist | HAVE | — |

### 13.5 Happiness index (`<app-moodbot-setting>`, `<app-moodbot-rating>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Enable mood bot? — `enableMoodBot` — Yes / No | No moodbot (EN-07). Pulse surveys exist (`survey_type = pulse`, `response_rule = per_period` week/month/quarter, but per-period answers only for *identified* surveys; no daily) | MISSING → built (HP-9f) | Setting `engage.mood.enabled` (default `false`). |
| Frequency of feedback — `feedbackFrequency` (Daily …) | — | MISSING → built (HP-9f) | `engage.mood.frequency` (daily/weekly/fortnightly/monthly, default weekly). |
| Day of week — `weekday` | — | MISSING → built (HP-9f) | `engage.mood.weekday` (weekday; `visible_when` weekly/fortnightly). Built: default Friday (5); the question opens on that day. |
| Privacy control — `anonymousFeedback` | Engagement anonymity architecture (anonymous/confidential/identified, `EngagementIdentity`, group keys, `analytics_min_group`) | PARTIAL → built (HP-9f) | `engage.mood.privacy` (anonymous/confidential/identified, default anonymous) using the same storage rules: anonymous check-ins store only the group key and period. Built: participations (date only) apart from check-ins (random UUID, no timestamps); confidential reveal through `ConfidentialIdentities`. |
| Reason tags — "You can add up to 20 active tags"; Active / Inactive tags; "Add new reason" (defaults Appraisal Salary, Appreciation, Colleagues, Employee engagement, Extracurricular activities, Facilities, Growth, Learning opportunity, Management, Other, Reporting Manager, Work environment, Work timings, Work-life balance) | — | MISSING → built (HP-9f) | Table `mood_reasons` (tenant, name, status, sort_order), max 20 active validated, 14 seeded; managed on a `MoodbotSettings` page (Engage). Built: `MoodReasonResource` (Engage → Mood reasons) with "Add new reason", "Add standard reasons" and "Preview"; a reason is made inactive, never deleted. |
| Mood prompt — "How's your mood today?", See more, Comments, Share anonymous feedback, SUBMIT; UPDATE / PREVIEW | — | MISSING → built (HP-9f) | Home card + table `mood_checkins` (period_key, mood 1–5, reason ids, comment, group_key; employee id only when identified); one per period; dashboard and dataset "Happiness index" with small-group suppression (`engagement.analytics_min_group`, text at `text_min_group`). Built: Home card, `MoodAnalytics`, page Happiness index, dataset `happiness_index` (period × group rows, suppressed cells left out, no comments); only periods that ended. |

### 13.6 Mission statement (`<app-core-value-grid>`, `<app-core-value-stepper>`, `<app-core-value-filter>`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| List "Mission statement policy - ##" (ADD POLICY, "NO MISSION") and filter — Policy name ("Select policy"), Status | — (EN-08 MISSING) | MISSING → built (HP-9b) | `MissionStatementResource` (Engage → Mission & values). |
| Step *Basic details* (mission, vision, purpose) | — | MISSING → built (HP-9b) | Table `mission_statements` (tenant, code, name, mission, vision, purpose, status, effective_from). |
| Step *Advance details* (core values, display) | — | MISSING → built (HP-9b) | `values` json [{title, description, icon}], `show_on_home` (bool); Home card for employees in the audience (`HomeComposer`); optional `badges.core_value_key` to tie recognition to values. |
| Steps *Applicability*, *Advance applicability* | Audience criteria engine | PARTIAL → built (HP-9b) | `audience` json (rule conditions; empty = everyone), most specific match wins. Advance applicability = `effective_from` and named employees through the `employee_id` rule field. |
| Step *Review* | — | N-A | Summary. |

### 13.7 Reward points policy (`<app-points-policy-layout>`, `<app-points-policy-grid>`, `<app-points-policy-form>`; card "Maximum Disbursal · ACTIVE · Cycle Monthly"; catalogue `policyName`, `description`, `creditCycle`, `pointsPerCycle`, `employeeRewardLimit`, `activeStatus`; eligibility `eligibility`, `points`, `firstValue`, `secondValue`)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Policy list (ADD POLICY) and filter — Policy ("Select Policy"), Status | — (EN-02 MISSING) | MISSING → built (HP-9d) | Policy type `reward_points` (Setup → Engage `policy_types`). |
| Step *Basic* — name, description, credit cycle (`creditCycle`: Monthly …) | — | MISSING → built (HP-9d) | `Policy.name/description` + field `credit_cycle` (monthly/quarterly/half_yearly/yearly). Built (calendar cycles on the tenant clock). |
| Step *Reward Points* — points per cycle (`pointsPerCycle`), employee reward limit (`employeeRewardLimit`) | — | MISSING → built (HP-9d) | Fields `points_per_cycle` (giving budget credited to each eligible giver), `max_per_award`, `max_received_per_cycle`, `carry_forward` (yes/no), `expiry_months`. Ledger `reward_point_entries` (employee, kind credit/award_given/award_received/redeem/expire/deposit/adjust, points, source morph, cycle_key; append-only like `GoalCheckIn`); job `peopleos:engage:credit-points` (tenant-aware, idempotent per cycle_key). Built: `max_per_award` = HROne employee reward limit; job `peopleos:engage:credit-points` (daily 01:30); unused points lapse back to the budget. |
| Eligibility rows (`eligibility`, `points`, `firstValue`, `secondValue`) | — | MISSING → built (HP-9d) | Field `eligibility` (repeater: criterion tenure_months/grade/role, from, to, points) — overrides `points_per_cycle` for matching givers. Built with criteria months of service and number of direct reports (grade / role groups are the policy's applicability). |
| Steps *Applicability*, *Advance applicability* | Policy Engine | HAVE | Platform once the type exists. |
| Step *Review* — Condition, Operator, Value, Role | Version view + impact preview | HAVE | Platform. |

### 13.8 Social wall (`<app-eng-setting>`, `<app-eng-setting-festive-theme>`, `<app-eng-setting-linkedin-badge-share>`, page capture)

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Social wall applicability — Enterprise (feeds, celebrations, directory search across all companies) / Company (company-wide) (`wallApplicability`) | `celebration.birthdays.visible_to` (organisation/company/team) for birthdays; no wall; People directory follows access scopes | PARTIAL → built (HP-9e) for the wall | Setting `engage.wall.scope` (organisation/company, default organisation) used by the wall feed, as the default for celebration visibility, and by the People directory search. Built for the wall feed. Celebrations keep their own `visible_to`; the People directory is unchanged (it follows access scopes and shows employees only their circle). |
| Select the post on which you want to restrict comment — Hr post, Badges, Skill endorsement, Rewards, Goal achievement, New joiner intro (`restrictedCommentType`; feed types `postType`, `isRestricted`) | No feed (EN-04) | MISSING → built (HP-9e) | Setting `engage.wall.comments_disabled_for` (multiselect hr_post/badge/skill_endorsement/reward/goal_achievement/new_joiner, default `[]`). Wall tables `wall_posts` (type, subject morph, author, body, audience, pinned, status), `wall_reactions`, `wall_comments` (moderation, report), permission `wall.moderate`; posts created by recognitions, celebrations, announcements (HR post), new joiners. Built (wall, reactions, comments, hide / restore / pin, report to moderators; skill endorsement has no posts yet). |
| Do you want to enable festive theme on social wall? — Yes / No (`showFestiveTheme`) | — | MISSING → built (HP-9e) as a greeting | Setting `engage.wall.festive_theme` (default `false`): on a holiday from the employee's `HolidayCalendar`, the wall and Home hero (`--pos-hero-*` tokens, `.pos-home`) switch to a festive variant. Built as a holiday greeting on the wall and the Home card (no colour variant: the theme is not rebuilt). |
| Do you want to enable share badge on linkedin? — `enable` — Yes / No (`badgeShareAllowed`, `linkedinClientName`) | — | MISSING → built (HP-9c) | Setting `engage.badges.linkedin_share` (default `false`): "Share on LinkedIn" opens LinkedIn's share URL pointing at a signed public certificate page (no LinkedIn API key needed). Built as LinkedIn's own "Add to profile" certification link (no API key, no public page). |
| Select certificate format — `letterTemplateId` (Select format: Default; PREVIEW; document viewer) | Letter templates (HTML/DOCX) + preview (`Letters::preview`) | PARTIAL → built (HP-9c) | Setting `engage.badges.certificate_template` (letter template code, default `null` = built-in certificate), preview via the letter preview. |
| Points value (`<app-eng-setting>` on this page) | — | MISSING → built (HP-9d) | See §13.0. |
| UPDATE (per block) | Setup centre save | HAVE | — |

### 13.x Shared panels inside `<app-common-settings>` on the Engage pages

| HROne setting (question/label, control name(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| `<app-time-office-setting>`, `<app-task-setting>` (groups, settings, status, declarationText), `<app-payroll-setting>`, `<app-help-desk-settings>`, `<app-timesheet-setting>`, `<app-setting>` (performance), `<app-asset-setting>`, `<app-recruitment-settings>`, `<app-expense-settings>`; "Delete Transaction" (performanceCycleId, taskTypeIds, remarks) | Covered in their own sections (performance ones in §12.0) | N-A | — |

---

## Counts

| Section | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---|---|---|---|---|
| 12 Performance | 141 | 27 | 37 | 64 | 13 (8 are other modules' panels) |
| 13 Engage | 65 | 7 | 12 | 41 | 5 (1 is other modules' panels) |

By page (HAVE / PARTIAL / MISSING / N-A): 12.0 Settings 1/7/9/2 · 12.1 1-on-1 1/1/1/0 · 12.2 360 3/1/2/0 · 12.3 Goal sheet policy 7/9/17/0 · 12.4 Goal library 5/1/0/0 · 12.5 Goal template 0/2/3/1 · 12.6 Terminology 0/0/10/0 · 12.7 Perspective 0/0/3/0 · 12.8 UOM 0/3/4/1 · 12.9 Initiative types 0/0/1/0 · 12.10 PIP policy 4/2/4/1 · 12.11 PIP reason 0/0/1/0 · 12.12 Performance policy 1/4/0/0 · 12.13 9-box 0/1/3/0 · 12.14 Bell curve 1/1/2/0 · 12.15 Review rating 4/1/0/0 · 12.16 Competency rating 0/4/0/0 · 12.17 KPI formula 0/0/4/0 — 13.0 Settings 0/1/7/1 · 13.1 Badges 0/0/3/1 · 13.2 Custom badges 0/1/10/1 · 13.3 Celebration 1/3/3/0 · 13.4 HR handbook 3/3/2/0 · 13.5 Happiness index 0/1/5/0 · 13.6 Mission statement 0/1/3/1 · 13.7 Reward points 2/0/4/0 · 13.8 Social wall 1/2/4/0. Most HAVE rows in policy pages are the platform's Applicability / Advance applicability / Review steps, which come free once the policy type exists.

## Biggest gaps

1. **Goal sheet policy** (§12.3): weight/count rules for goals, KPIs and competencies, per-role field rights, review configuration, scorecard visibility, copy-previous-level and goal-plan approval — PeopleOS has only a required total weight.
2. **Recognition** (§13.1, §13.2, §13.7, §13.0): badges, custom badges, monthly quota, certificates and the reward-points policy/ledger — nothing exists.
3. **Social wall** (§13.8, §13.3 achievements): feed, comments with per-type restrictions, wall scope, festive theme, LinkedIn share.
4. **Performance policy / periodic cycles and KPI rating formulas** (§12.12, §12.17): monthly goal and review cycles rolling into the appraisal; achievement→rating bands with reverse KPIs.
5. **Performance masters and settings** (§12.0, §12.6–§12.9, §12.11): rating publish mode (and the early-visibility defect), goal-edit window, check-in schedulers, terminology, perspectives, units of measurement, initiative types, PIP reasons — the Setup centre's Performance module has zero settings today.

---

## Build packages (ordered)

Each package is additive; every new setting's default reproduces today's behaviour; each closes with Pest coverage in `tests/Feature/HroneParity/`, `DataInventory` entries, policies, Setup centre entries (`SetupCentreTest` must pass) and an update of `docs/architecture/hrone-parity.md` and the Gap Register.

| # | Package | Scope | Files likely touched | Depends on | Size |
|---|---|---|---|---|---|
| 1 | **HP-8a Performance masters** | `performance_lookups` (perspective, initiative_type, pip_reason) with seeds; `performance_units` (+ `goals.unit_id`, `key_results.unit_id`, `kras/goals.perspective_id`, `improvement_plans.reason_codes`); terminology settings `performance.labels.*` + `PerformanceLabels` helper + "Use OKR terms" action | new migrations; `app/Domain/Performance/Models/{PerformanceLookup,PerformanceUnit}.php`; `Services/PerformanceDefaults.php`; `Support/PerformanceLabels.php`; `app/Filament/Resources/{PerformanceLookups,PerformanceUnits}/*`; `GoalResource`, `KraResource`, `ImprovementPlanResource`; `config/peopleos.php` (settings), `config/peopleos_setup.php` (performance settings + links); `DataInventory.php` | — | M |
| 2 | **HP-8b Performance general settings + defect fixes** | `performance.rating_publish` (+ `appraisals.published_at/by`, bulk publish), goal-edit window, profile 360, decimals/rounding, `cycle.default_type`, `year_start_month`, 1-on-1 scope + feedback cards (`one_on_one_feedback`, task source), check-in schedulers (company/team/employee + send time) in `PerformanceReminders`; wire or remove `show_self_to_manager` / `peer_anonymous`; hide unpublished reviews from employees | `Services/{Appraisals,Goals,OneOnOnes,PerformanceReminders}.php`; `Filament/Support/PerformanceActions.php`; `Resources/Appraisals/**` (`ReviewsRelationManager`), `PerformanceCycles/PerformanceCycleResource.php`, `OneOnOnes/OneOnOneResource.php`, `Employees/RelationManagers/PerformanceRelationManager.php`; `Pages/CalibrationBoard.php`; `config/peopleos.php` (`settings`, `experience.task_sources`), `config/peopleos_setup.php` | — (labels from 1 optional) | M |
| 3 | **HP-9a Handbook and celebration settings** → built (WhatsApp delivery open, EXT) | `kb.acknowledgement.due_days`, `kb.download_allowed`, date filters, per-employee ack list; work and wedding anniversaries, `notify_roles`, preview, welcome message settings (`experience.welcome.*`) | `Knowledge/Services/KnowledgeBase.php`, `ServiceDesk/Services/ServiceDeskProcessor.php`, `Resources/Articles/ArticleResource.php`; `People/Services/Birthdays.php` (→ celebrations), `People/Models/{Person,BirthdayWish}.php`, `Console/Commands/BirthdayNotices`, `Pages/{Birthdays,Home}.php`, `EmployeeForm`; migration on `people`, `birthday_wishes.kind`; `config/peopleos.php` (settings, workflow trigger `employee.work_anniversary`), `config/peopleos_setup.php` | — | M |
| 4 | **HP-8c Rating scales and KPI formulas** | Level ranges (`from`/`to`) + `labelFor()`; separate competency scale per cycle (+ snapshot); `kpi_rating_formulas` + `KpiRatings::suggest`; `is_reverse` on goals/KRs/library KPIs; `show_formula_rating`, `show_reverse_rating`, `formula_rating_mode` | `Models/{RatingScale,PerformanceCycle,PerformanceTemplateVersion,Goal,KeyResult}.php`; `Services/{PerformanceTemplates,Appraisals}.php`; new `Models/KpiRatingFormula.php`, `Services/KpiRatings.php`, `Resources/KpiRatingFormulas/*`; `RatingScaleResource`, `PerformanceCycleResource`, `PerformanceActions::reviewForm` | 1 (units), 2 (settings pattern) | M |
| 5 | **HP-8d Goal sheet policy** → built | Policy type `goal_sheet` (goal creation, weight/count rules for goals, KPIs and competencies, field rights per role, review level/input/evidence, scorecard hidden fields, copy previous level, plan approval); `GoalSheetRules`, `GoalFieldRules`; weighted competency scoring; goal status `pending_approval` + Approval Center source; `Policies` duplicate action | `config/peopleos.php` (`policies.types.goal_sheet`), `config/peopleos_setup.php`; `Services/{Goals,Appraisals}.php`; new `Services/GoalSheetRules.php`, `Support/GoalFieldRules.php`; `Resources/Goals/*`, `Appraisals/*`; `Filament/Support/{PerformanceActions,PolicySettingsSchema}.php`; `Domain/Configuration/Services/SetupCatalogue.php` (options_from competencies); `Resources/Policies/PolicyResource.php`; `Providers/ParityServiceProvider.php` | 1, 4 | L |
| 6 | **HP-8e Goal templates and system mapping** → built | `goal_templates`, `goal_template_items`, applicability, feedback form link; `GoalTemplates::assign()` on cycle launch / goal setting; "Use template" on goal create; goal-sheet `goal_creation = system` | new models/services/resources under `app/Domain/Performance` and `app/Filament/Resources/GoalTemplates`; `Services/Appraisals.php` (launch hook); `GoalResource` | 5 | M |
| 7 | **HP-8f PIP policy, reasons and letters** → built | Policy type `pip` (duration, extensions, letters on activate/close, start/close checklists, approval); letter types `pip`, `pip_outcome`, `performance`; workflow subject `ImprovementPlan` + trigger `performance.pip.requested`; Approval Center source | `config/peopleos.php` (`policies.types.pip`, `letters.types`, `workflows.subject_types/trigger_events`); `Services/ImprovementPlans.php`; `Resources/ImprovementPlans/*`; `Letters/Services/Letters.php` (source only); `ParityServiceProvider` | 1 (reasons) | M |
| 8 | **HP-8g Calibration aids: bell curve and 9-box** → built ("record as talent assessment" and box renaming open) | `performance_distribution_targets` + chart and `performance.distribution.check/tolerance_pct`; `performance_nine_box_bands` with default thirds + `NineBoxGrid` page (+ optional talent assessment write) | new migrations/models; `Pages/{CalibrationBoard,NineBoxGrid}.php` (+ views); `Services/Appraisals.php` (close/finalize warn); `config/peopleos_setup.php` links | 2 (publish), 4 (competency scale) | M |
| 9 | **HP-9b Mission statement and core values** → built | `mission_statements` + resource + Home card | new `app/Domain/Engagement/Models/MissionStatement.php` (or `Recognition`), resource, `Experience/Services/HomeComposer.php`, home view; Setup links | — | S |
| 10 | **HP-9c Recognition: badges and custom badges** → built (uploaded badge images not offered) | `badges` (9 system seeded), `recognitions` ledger, monthly quota `engage.badges.per_month`, custom badge rules (appearance, givers/receivers, visibility, frequency, reason/attachment, certificate via Letters), `engage.badges.custom_allowed`, LinkedIn share + certificate format settings, Wall-of-fame card, "Badges ledger" dataset, permissions `recognition.*` | new `app/Domain/Recognition/{Models,Services,Policies}`; `Resources/Badges/*`, Me action; `Analytics` dataset + `ReportLibrary`; `config/peopleos.php` (permissions, roles, settings, letter type `badge_certificate`), `config/peopleos_setup.php` (engage); `DataInventory.php` | Letters (exists) | L |
| 11 | **HP-9d Reward points** → built (vendor vouchers open, EXT) | `engage.points.value_per_point/currency` (one-time lock via `SetupCatalogue` `locked_after`), policy type `reward_points` with eligibility, `reward_point_entries` ledger, credit job, badge-linked points, budget deposit, optional payroll redemption (`RewardPointsInput`) | `app/Domain/Recognition/*`; console command + schedule; `config/peopleos.php` (`policies.types.reward_points`, settings), `config/peopleos_setup.php`; `Domain/Configuration/Services/SetupCatalogue.php`; `Payroll/Contracts/PayrollInputSource` implementation (optional) | 10 | L |
| 12 | **HP-9e Social wall** → built (festive theme as a greeting; no skill endorsement posts) | `wall_posts`, `wall_reactions`, `wall_comments`, moderation; `engage.wall.scope`, `comments_disabled_for`, `festive_theme`, `show_achievements`, `attachments`; posts from recognitions, celebrations, announcements, new joiners, goal completions | `app/Domain/Recognition` (or `Wall`); Home feed (`HomeComposer`, views, `.pos-home` CSS); listeners on recognition/celebration/announcement/goal events; People directory scope | 3, 10 (11 for reward posts) | L |
| 13 | **HP-9f Happiness index (moodbot)** → built | `engage.mood.enabled/frequency/weekday/privacy`, `mood_reasons` (≤ 20 active, 14 seeded), `mood_checkins` through the Engagement privacy layer, Home prompt, dashboard + dataset | `app/Domain/Engagement/{Models,Services}` (MoodCheckIns), `Pages/MoodbotSettings.php`, Home card; `config/peopleos.php` (settings, permissions), `config/peopleos_setup.php`; Analytics dataset | — | M |
| 14 | **HP-8h 360 feedback policy and rounds** → built (to SV-12; workflow approval of nominations open) | Policy type `feedback_360`; `feedback_rounds`, `feedback_nominations` (approval source, workflow subject), responses, extend window; peer rating blend option | `config/peopleos.php` (`policies.types.feedback_360`, workflows), new models/services in `app/Domain/Performance`, resources, `Services/Appraisals.php` (blend), `ParityServiceProvider` | 2 (profile 360, publish), Forms engine | L |
| 15 | **HP-8i Performance policy (periodic goal/review cycles)** → built (to SV-11; periodic goal sheets and the "(recorded)" answers open) | Policy type `performance` (goal_cycle, review_cycle, review_levels, appraisal_basis, weights); periodic review windows (rated check-ins or child cycles) and roll-up into `Appraisals::score()` | `config/peopleos.php`; `Models/{PerformanceCycle,PerformanceCheckIn}.php`; `Services/{Appraisals,PerformanceCheckIns}.php`; new `Services/PerformanceCycles.php`; resources | 5, 4 | L |
| 16 | **HP-8j Initiatives** → built | `initiatives` + feedback using initiative types; Me/Team screens; notification "Initiative" | new models/services/resources; `config/peopleos.php` (events, notifications) | 1 | M |
| 17 | **HP-8k Performance reset ("Delete transaction")** | `PerformanceResets` + cycle action, permission `performance.reset`, audited; never touches finalized/locked data — **needs an owner decision first** (immutability principle) | `app/Domain/Performance/Services/PerformanceResets.php`; `PerformanceCycleResource`; `config/peopleos.php` (permission) | owner decision | S |
