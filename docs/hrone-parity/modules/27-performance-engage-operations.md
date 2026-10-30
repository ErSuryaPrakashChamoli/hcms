# HROne vs PeopleOS — 27 Performance and Engage operations (HR Desk → Performance / Engage), field by field

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Recruitment is out of scope (separate RMS):
"Candidate engagement" rows are N-A. Package prefix: **GO-**.

**Progress.** Complete for the scope in the brief: both panels (15 Performance items incl. "Performance letters", 9
Engage items), their dialogs from components, the 20 global actions, Search / Inbox Insights / Action / Imports /
Analytics / Reports nodes of the HR Desk branch, the Team branch (reports, imports, analytics, people actions, team
profile), Smart search → Performance and Home → Enterprise wall. Every register in both panels was **empty** for the crawl
account except Announcement and Reward points, so card contents, filters and dialogs come from `components.json` (marked
where inferred). Not possible: the "expand_more" menus next to each split button were never opened (their entries are
inferred from `globalAction` and the page's `actionIds`); the per-register "Reports" tab views (only "CREATE VIEW" was
captured); event, poll and fundraiser forms were never opened live (fields from components only). HP-8h (360 rounds and
nominations) and HP-8i (performance policy, periodic cycles) are being built in a separate clone: their rows are rated
against today's tree and carry the build note "HP-8h/HP-8i in progress".

**Built (2026-10-30).** GO-1 (Performance desk and Engage desk in HROne's panel order; Goal sheets, Goal requests and
Performance review requests registers; "Remind reviewers" and — behind `performance.review.hr_reopen`, off until owner
decision PER-1 / D-GO1 — "Reopen review"; the appraisal created-date filter; PIP request / approval / end-date filters,
requested / approved columns, approval trail, evaluation status and the "PIP evaluations" view; the "Goals and
initiatives" drawer; performance letters as Letters views; the "Performance actions" header menu): the rows it closes are
marked "→ built" with the package (rows only partly closed say which part and which package finishes them); see
`docs/architecture/hrone-parity.md` "GO-1 Performance desk and register depth". The counts below are the analysis-time
counts.

**Inputs.**

- `hrone-research/menu-tree.json` → panels **"Performance"** (top items Employee goals, Company goal, Goal requests, Reports,
  Performance Setup; groups Goals, Reviews (Performance reviews, Performance review requests), Continuous feedback (360
  Feedbacks, 360 Nominations, Initiatives, 1-on-1 Meetings), Performance improvement (PIP, PIP evaluations), Performance
  letters (PIP letters, PIP evaluation letter)) and **"Engage"** (HR handbook, Announcement, Events, Polls, Survey, Badges,
  Reward points, Candidate engagement, Reports, Engage Setup) with the URL of each item.
- `hrone-research/pages.jsonl` (latest line per id): the 14 `menu_Performance__*` and 9 `menu_Engage__*` pages and their
  `__tab_01_Reports` captures; `rail_Performance`, `rail_Engage` (+ `__tab_01_Reports`); `desk_Employee_goals`,
  `desk_HR_handbook`; `route_app_socialwall_search` (+ tabs), `route_app_processes_engage_announcement`,
  `route_app_request_initiate_change_goal`, `route_app_core_performance_policy360`,
  `route_app_processes_search_performance_policy360`, `route_app_core_engagesetting_{badgesetting,rewardpointspolicy}`,
  `route_app_analytics_{performance,survey}`, `rail_Analytics__Performance_9_box`,
  `menu_HR_Desk__HR_Analytics__tab_02_Goals`, `menu_Team__Team_Analytics__tab_02_Goals`.
- `hrone-research/menu-permission-tree.md` and `menu-catalogue.json` → `MenuAccessPermission/0` (with each node's grantable
  actions): HR Desk → Search → Performance [14] / Engage [8], Inbox Insights → Performance [18] / Engage [8], Action →
  Performance (14 rights) / Engage [6], Imports → Performance [9] / Engage [3], Analytics → Performance [2], Reports →
  Performance [23] / Engage [12]; Team → Reports → Performance [21] / Engage [8], Team → Imports → Performance [6], Team →
  Analytics → Performance [2], Team → People → Action → Performance (7 rights) / Engage (1 right), Team profile →
  Performance (11 rights) / Engage (1 right); Smart search → Performance (2 rights); Home → Enterprise wall [3]
  (Engage 8 rights, Knowledge base 1, Performance 3); Analytics → Dashboards (Engage, Happiness index, Performance,
  Performance 9 box, Pulse, Survey). `AvailableSearch` (each page's `navigationCode`, `importIds`, `actionIds`) and
  `globalAction` (Performance: Add PIP, Change goal, Create company goal, Delete goals, Delete reviews, Extend 360, Extend
  goal, Extend review, Initiate 360 feedback, Initiate goal cycle, Initiate review cycle, Normalize performance rating,
  Normalize review rating, Publish performance rating; Engage: Add announcement, Add event, Add HR handbook, Add survey,
  Candidate engagement, Initiate fundraise).
- `hrone-research/components.json`: `app-goal-search` → `app-goal-cards`, `app-goal-approval-view-form`,
  `app-goal-creation-detail`, `app-copy-goal-from-team`, `app-copy-goal-from-chatGpt`; `app-initiate-goal-cycle`,
  `app-extend-goal-action(-flyar)`, `app-delete-goal`; `app-createcompanygoal` → `app-add-company-goal`, `app-add-kpi`,
  `app-company-goal-card`; `app-performance-review-search` → `app-review-cards`, `app-performance-view`,
  `app-review-approval`, `app-kra-detail`, `app-rating-calculation`, `app-review-formula-flyer`;
  `app-initiate-review-cycle`, `app-extend-review-action(-flyer)`, `app-delete-review`; `app-normalize-rating(-flyer,
  -bell-curve)`, `app-publish-performance-rating-flyer`; `app-policy360-search` → `app-policy360-feedback-card`,
  `app-policy360-nomination-card`, `app-initiate360-feedback`, `app-policy360-feedback-details`,
  `app-policy360-nomination-(details|view-form)`, `app-policy360-send-nomination-detail`, `app-extend-threesity-action`;
  `app-goals-initiatives-search` → `app-initiative-cards`, `app-my-goal-and-initiatives-flyer`, `app-initiative-details`,
  `app-assign-initiative`, `app-new-initiative`, `app-feedback-initiative`, `app-initiative-comments-flyer`,
  `app-initiative-activity-log`; `app-one-on-one-search` → `app-one-on-one-detail`, `app-schedule-one`,
  `app-recurrence-dialog`, `app-one-on-one-acknowledge`; `app-pip-search` → `app-pip-cards`, `app-pip-request(-flyer)`,
  `app-pip-approval-details`; `app-pip-evaluation-search` → `app-pip-eval-card`, `app-pip-eval-details`;
  `app-employee-letter-search` → `app-employee-letter-grid`, `app-employee-letter-process-card`,
  `app-other-letter-generation`; `app-team-goal-dashboard`, `app-hr-goal-dashboard-sidebar`, `app-kpi-details-flyer`,
  `app-kpi-details-activity-log-flyer`, `app-goal-checkin-dialog`; `app-policy-search` → `app-policy-card`,
  `app-create-policy`, `app-create-policy-step1`, `app-create-policy-review`, `app-hr-handbook-policy`;
  `app-hr-announcement` → `app-announcement-card`, `app-announcement`, `app-announce-step1`, `-step2`, `-step-teams`,
  `-step-review`, `app-announce-preview`; `app-event-search` → `app-events-card`, `app-events-preview`,
  `app-events-management`, `app-event-details`, `app-event-frequency`, `app-event-reward`, `app-events-applicability`,
  `app-events`, `app-events-detail`, `app-events-feedback-template`; `app-initiate-poll-search` →
  `app-initiate-polls-card`, `app-polls-management`, `app-polls-detail`, `app-polls-applicability`, `app-polls-review`,
  `app-polls-detail-approval`, `app-polls-card-approval`, `app-polls-result-card`; `app-pulse-survey-search` →
  `app-pulse-survey-card`, `app-pulse-survey`, `app-ps-details`, `app-ps-frequency`, `app-ps-preview`,
  `app-survey-approval`; `app-badges-points-search` → `app-badges`, `app-custom-badges`, `app-points`,
  `app-rewards-credit-ledger`; `app-transfer-reward-points`; `app-give-badge`, `app-give-points`;
  `app-candidate-engagement` → `app-candidate-engagement-card`, `app-candidate-engage-flyer`;
  `app-fundraiser-campaign-search` → `app-create-fundraiser-campaign`, `app-fcc-fundraiser`; `app-ou-applicability`,
  `app-ou-advance-applicability`, `app-bulk-employee-search`, `app-organization-filter`, `app-location-filter`;
  `app-common-settings` (Delete Transaction).
- Lookup lists named by the captured APIs (configuration, not personal data): `pms/initiative/Report/Goals/PeriodDetails`
  (goal periods, monthly), `pms/setting/general/PerformanceCyclePeriod` (performance years), `pms/extend/Review/CyclePeriod`
  ("Performance Policy [month]" review cycles), `pms/okr/Action/Status` (Not started, On track, Behind, At risk, Close),
  `pms/setting/uom/Organizationwise`, `core/performance/setting/LabelName`, `pms/setting/threesixty/policy` (empty),
  `workforce/LetterList/4/29`, `workforce/OtherLetter/trigger/event/type`, `Engage/Announcement/TriggerType` (One time,
  Event based, Reoccurrence), `Engage/Event/Policy/Setting/(Sub)EventTypeList` (empty), `Engage/Badge/Setting/1`,
  `Engage/Candidate/EventType`, register searches (`pms/goal/requestFlow/Search/Admin|History`,
  `pms/okr/company/goal/Search`, `pms/review/Request/Search/Admin`, `pms/review/Request/History/Admin`,
  `pms/threesixty/feedback/Admin/Search`, `pms/threesixty/nomination/Admin`, `pms/initiative/Goals/Search/Admin`,
  `pms/oneonone/request/Search`, `pms/pip/request/Admin/Search`, `pms/pip/evaluation/Admin/Search`,
  `Engage/CompanyPolicy/HRhandbook/Search`, `Engage/Announcement/HRDesk/Search`, `engage/event/policy/Search`,
  `engage/initiated/poll/request/search`, `engage/pulseSurvey/policy/Search`, `engage/globalSearch/Badges|RewardPoints`,
  `Engage/Candidate/Search`).
- Page text and screenshots under the session scratchpad `hrone/deep/<id>/` for the ids above (structure only; the two
  non-empty registers are described by field, never by value).
- Earlier matrices this one points to instead of repeating: `20-requests-inbox-team.md` (Change goal request §20.1.14, inbox
  task views §20.2.2, team pages §20.3, home / social wall §20.4.2), `21-analytics-reports-projects.md` (dashboards
  §21.1.4, §21.1.7–§21.1.10, happiness index; report catalogue §21.2.2 Performance (24) / Engage (12); report centre
  §21.2.1; Inbox Insights page §21.3), `22-my-space-self-profile.md` (goals §22.8, scorecard §22.9, handbook §22.10,
  1 on 1 §22.14.1, badges §22.14.4, PIP §22.14.14, polls §22.14.15, team announcement §22.14.18), `23-workforce-operations.md`
  (shared register chrome §23.1 WO-1 / WO-2, letter registers §23.7.1 WO-13, import wizard §23.10.0 WO-17),
  `setup/12-performance-engage.md` (§12.0–§12.17, §13.0–§13.8) and `setup/13-setup-reverification.md` (PF-1 … PF-11,
  SV-11, SV-12).
- PeopleOS, checked in code: domains `Performance` (models `PerformanceCycle`, `Appraisal`, `AppraisalReview`,
  `AppraisalRating`, `CalibrationSession`, `CalibrationAdjustment`, `Goal`, `KeyResult`, `GoalPlan`, `GoalCheckIn`,
  `GoalTemplate(Item)`, `Kra`, `KpiRatingFormula`, `PerformanceUnit`, `PerformanceLookup`, `PerformanceCheckIn`,
  `OneOnOne`, `OneOnOneFeedback`, `FeedbackEntry`, `ImprovementPlan`, `ImprovementPlanCheckpoint`, `Initiative`,
  `InitiativeFeedback`, `PerformanceDistributionTarget`, `PerformanceNineBoxBand`; services `Appraisals` (eligible,
  schedule, launch, advance, close, archive, addPeerReview, submitReview, calibrate, finalize, publish, acknowledge),
  `Goals`, `GoalPlans`, `GoalTemplates`, `GoalSheetRules`, `Calibrations`, `PerformanceDistribution`, `NineBox`,
  `ImprovementPlans`, `OneOnOnes`, `Initiatives`, `Feedback`, `PerformanceCheckIns`, `PerformanceReminders`,
  `PerformanceAnalytics`; support `AppraisalVisibility`, `OneOnOneFeedbackTaskSource`, `InitiativeFeedbackTaskSource`),
  `Feedback` (`FeedbackRequest`, `FeedbackTemplate`, `QuestionBank`), `Talent`, `Engagement` (`Survey`, `SurveyVersion`,
  `SurveyQuestion`, `SurveyResponse`, `Audience`, `EngagementCampaign`, `MissionStatement`, `MoodCheckIn`, `MoodReason`,
  `EmployeeFeedback`), `Recognition` (`Badge`, `Recognition`, `RewardPointEntry`, `RewardPointBudget`, `WallPost`,
  `WallComment`, `WallReaction`; services `Recognitions`, `RewardPoints`, `SocialWall`), `Communication` (`Announcement`,
  `AnnouncementRead`, `CommunicationRecipient`; `Communications`, `CommunicationDelivery`, `CommunicationTaskSource`),
  `Knowledge` (`Article`, `ArticleVersion`, `ArticleRead`; `KnowledgeBase`, `PolicyAcknowledgementTaskSource`),
  `Letters` (types `pip`, `pip_outcome`, `performance`, `badge_certificate`); Filament resources `PerformanceCycles`,
  `Appraisals` (+ Reviews relation), `CalibrationSessions`, `Goals`, `GoalTemplates`, `Kras`, `KpiRatingFormulas`,
  `PerformanceUnits`, `PerformanceLookups`, `PerformanceCheckIns`, `OneOnOnes`, `FeedbackEntries`, `FeedbackRequests`,
  `FeedbackTemplates`, `ImprovementPlans`, `Initiatives`, `Announcements`, `Audiences`, `Articles`, `Surveys`,
  `EngagementCampaigns`, `EmployeeFeedback`, `Badges`, `Recognitions`, `RewardPoints`, `MissionStatements`,
  `MoodReasons`, `Letters`, `Teams`; pages `CalibrationBoard`, `NineBoxGrid`, `PerformanceAnalyticsPage`, `SocialWall`,
  `SurveyResults`, `HappinessIndex`, `AnnouncementsFeed`, `Home`, `Approvals`, `TaskInbox`, `MyTeam`, `RecentUploads`,
  `ReportLibraryPage`; support classes `PerformanceActions`, `RecognitionActions`, `RewardPointActions`,
  `EngagementActions`; Approval Center source `PerformanceApprovalSource` (kinds `goal_plan`, `pip`, `pip_evaluation`);
  experience task sources (`config/peopleos.php` → `experience.task_sources`); upload types (`ImportTypes`,
  `RowImportTypes`: none for performance or engage); analytics datasets `performance`, `goals`, `improvement_plans`,
  `initiatives`, `badges_ledger`, `reward_points_ledger`, `happiness_index`, `letters`; `config/peopleos.php`
  (permissions `performance.*`, `engagement.*`, `communication.*`, `recognition.*`, `wall.*`; `performance.stages`,
  `goal_levels`, `goal_statuses`, `appraisal_statuses`, `pip_statuses`, `initiative_statuses`, `review_types`;
  `communication.types`; `engagement.survey_types`, `question_types`, `anonymity_modes`, `response_rules`); docs
  `docs/architecture/hrone-parity.md` (HP-8a … HP-8g, HP-8j, HP-9a … HP-9f), `docs/hrone-parity/setup/00-build-status.md`,
  `docs/hrone-parity/HROne-Gap-Register.md` (PF-01 … PF-14, EN-01 … EN-12).

---

## 0. PeopleOS building blocks this matrix relies on

| Building block | Where | HROne counterpart |
|---|---|---|
| Performance cycle: type, period, template version pinned at launch, ordered stages with windows (goal setting, self / manager / approver / peer review, calibration, final), eligibility rules, options; schedule → launch (creates one appraisal per eligible employee) → advance → close → archive; configuration frozen once scheduled | `PerformanceCycleResource`, `Appraisals::{eligible,schedule,launch,advance,close,archive}`, `PerformanceCycle::booted` | Goal / review / performance cycles of the performance policy; "Initiate goal / review cycle" |
| Appraisal: reviews (self, manager, approver, peer, upward, skip level, external), goal / competency scores, computed → calibrated → final rating, publication (`performance.rating_publish` auto / manual), acknowledgement, promotion / PIP recommendation | `AppraisalResource` (+ Reviews relation), `PerformanceActions::forAppraisal`, `AppraisalVisibility` | Performance review cards, review form, normalize and publish |
| Calibration: board (target vs actual per level, department / grade filter, "Publish ratings"), sessions (reasoned adjustments), distribution check off / warn / block; 9-box grid | `CalibrationBoard`, `CalibrationSessionResource`, `PerformanceDistribution`, `NineBoxGrid`, `NineBox` (HP-8g) | Normalize performance rating, bell curve, 9 box |
| Goals: five levels (company … employee), KRA / KPI with key results, weights, units, perspectives, reverse KPIs and KPI rating formulas, alignment (`parent_id`, roll-up of progress), check-ins; goal sheet policy, goal plans with approval, goal templates and system mapping (manager owns a mapped sheet) | `GoalResource`, `Goals`, `GoalPlans`, `GoalSheetRules`, `GoalTemplates`, `PerformanceApprovalSource` (`goal_plan`) (HP-8a … HP-8e) | Employee goals, company goal, goal requests, change goal |
| One-on-ones (host, participant, scheduled / held, agenda, notes, private notes, action items, status; scope team / organisation; feedback card = acknowledgement) | `OneOnOneResource`, `OneOnOnes`, `OneOnOneFeedbackTaskSource` (HP-8b) | 1-on-1 meetings, 1-on-1 acknowledgement |
| Initiatives (type, linked goal, dates, completion, owner, cancel; feedback asked of colleagues / given by managers; My / team / HR tabs; dataset) | `InitiativeResource`, `Initiatives`, `InitiativeFeedbackTaskSource` (HP-8j) | Initiatives, initiative inbox, "Create an initiative" |
| Improvement plans (reasons, objectives, checkpoints, approval of plan and outcome, extension, close / close-out, PIP policy, PIP / PIP-outcome letters) | `ImprovementPlanResource`, `ImprovementPlans`, `PerformanceApprovalSource` (`pip`, `pip_evaluation`) (HP-8f) | PIP, PIP evaluations, PIP letters |
| Continuous feedback (praise / constructive, visibility, anonymous, requests, reveal) and feedback templates / requests for processes | `FeedbackEntryResource`, `FeedbackRequestResource`, `FeedbackTemplates` (P13) | Continuous feedback, feedback templates |
| Letters (templates, generation, approval, issue to documents; types `performance`, `pip`, `pip_outcome`) | `LetterResource`, `Letters`, `PolicyLetters` | Performance letters, PIP letters, PIP evaluation letter |
| Communication: announcements (type, body, article, priority, campaign, publish / expiry, attachment, pin, acknowledgement, audience, approval, versions, stats sent / failed / read / ack) | `AnnouncementResource`, `Communications`, `CommunicationDelivery`, `CommunicationTaskSource` | Announcement, team announcement |
| Knowledge base articles = HR handbook (categories, versions, review, publication, audience rules, acknowledgement with due days, "Who acknowledged", download) | `ArticleResource`, `KnowledgeBase`, `PolicyAcknowledgementTaskSource` (HP-9a) | HR handbook |
| Surveys (types incl. pulse and event feedback, versions = runs, question types, anonymity anonymous / confidential / identified, response rules, audience, opens / closes, reminders, approval, results with small-group suppression) | `SurveyResource` (+ Versions), `Surveys`, `SurveyResults`, `SurveyTasks` | Survey / pulse, survey analytics |
| Recognition: badges (9 system + custom with rules), recognitions ledger, monthly quota, certificate, withdrawal, wall of fame; reward points (budget, giving / earned wallets, policy credit run, awards, badge points, redemption through payroll) | `BadgeResource`, `RecognitionResource`, `RewardPointEntryResource`, `Recognitions`, `RewardPoints` (HP-9c, HP-9d) | Badges, Reward points, give badge / points |
| Social wall (HR posts, badges, rewards, celebrations, announcements, new joiners, goal achievements, certificates; reactions, comments, moderation, report) | `SocialWall` page, Home card, `SocialWall` service (HP-9e) | Enterprise wall |
| Report library and datasets (`performance`, `goals`, `improvement_plans`, `initiatives`, `badges_ledger`, `reward_points_ledger`, `happiness_index`, `letters`) | `ReportLibraryPage`, `DatasetRegistry`, `ReportLibrary` | Reports tabs and report centre |
| Approval Center and My tasks (task sources) | `Approvals`, `TaskInbox`, `ApprovalCenter`, `ExperienceTasks` | Inbox |

---

## 27.0 Index — HR Desk → Search → Performance [14] / Engage [8] and the two panels

| HROne node (Search) | Panel item / navigation code | Section |
|---|---|---|
| Goal (View, Edit) | Employee goals, `goals_680` | §27.2.1, §27.2.2 |
| Company goal (View, Edit) | Company goal, `createcompanygoal_998` | §27.2.3 |
| Goal history | Goal requests, `goalhistory_640` | §27.2.4 |
| Performance | Performance reviews, `performancereview_432` | §27.3.1, §27.3.3, §27.3.4 |
| Performance history | Performance review requests, `performancehistory_464` | §27.3.2 |
| 360 feedback | 360 Feedbacks, `360feedback_606` | §27.4.1 |
| 360 nomination | 360 Nominations, `nomination_606` | §27.4.2 |
| Goals and initiatives | Initiatives, `goalsandinitiatives_2207` | §27.4.3 |
| 1-on-1 | 1-on-1 Meetings, `oneonon_635` | §27.4.4 |
| PIP | PIP, `pip_955` | §27.5.1 |
| PIP evaluation | PIP evaluations, `pip_975` | §27.5.2 |
| Performance letter (View letter, Generate letter, Unpublish) | Performance letters, `performanceletter_696` (in `AvailableSearch`, not in the panel tree) | §27.6 |
| Pip letter (View letter, Generate letter, Unpublish) | PIP letters, `pipletter_955` | §27.6 |
| PIP evaluation letter (View letter, Generate letter, Unpublish pip letter) | PIP evaluation letter, `pipletter_803` | §27.6 |
| HR Handbook (Update, View, Unpublish) | HR handbook, `policy_189` | §27.8.1 |
| Announcement (Update, View, Unpublish) | Announcement, `announcement_188` | §27.8.2 |
| Events (View, Unpublish, Update) | Events, `events_2277` | §27.8.3 |
| Polls approval (View) | Polls, `initiatepoll_2376` | §27.8.4 |
| Survey (View, Update, Unpublish) | Survey, `pulsesurvey_583` | §27.8.5 |
| Badges (View) | Badges, `badges_434` | §27.8.6 |
| Reward points (View) | Reward points, `rewardpoints_435` | §27.8.7 |
| Candidate engagement (View) | Candidate engagement, `candidateengagement_729` | §27.8.8 (N-A) |
| — | Reports (both panels) | §27.13 |
| — | Performance Setup / Engage Setup | Setup matrices 12 and 13 (not repeated) |

---

## 27.1 Performance and Engage panels and the shared register chrome (`rail_Performance`, `rail_Engage`, every `/app/processes/search/*` page in both panels)

Every page is the HR Desk register shell rated in matrix 23 §23.1 (title, Records / Reports tabs, employee search,
"Filters" flyer, IMPORTS, split action button or ACTIONS, cards with SHOW MORE). The rows below place what is specific to
the two panels; the shared rows (pasted employee codes, organisation filters, saved filters, bulk framework) are not
repeated and close with WO-1 / WO-2.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Rail "Performance" panel: Goals (Employee goals, Company goal, Goal requests), Reviews, Continuous feedback, Performance improvement, Performance letters, Reports, Performance Setup; collapse arrows | One home for every performance register | Navigation group "Performance": Cycles, Appraisals, Calibration board, 9-box, Calibration sessions, Goals, Goal templates, KRA library, One-on-ones, Feedback, Check-ins, Improvement plans, Initiatives, Analytics, masters; no goal-sheet, review-request, 360 or performance-letter entry | PARTIAL → built (GO-1: Performance desk (own rail area) in HROne's order — Goals · Reviews · Continuous feedback · Performance improvement · Performance letters · Reports · Setup — with Goal sheets, Goal requests, Review requests, PIP evaluations and the three letter registers as views) | GO-1: navigation cluster in HROne's order with preset entries (goal sheets, goal requests, review requests, PIP / evaluation / performance letters as filtered Letters) |
| 2 | Rail "Engage" panel: HR handbook, Announcement, Events, Polls, Survey, Badges, Reward points, Candidate engagement, Reports, Engage Setup | One home for engagement registers | Groups "Communication" (Announcements, Audiences), "Engagement" (Surveys, Campaigns, Badges, Recognition, Reward points, Social wall, Mission & values, Mood reasons, Happiness index, Employee feedback) and knowledge-base articles; no events, polls | PARTIAL → built (GO-1: Engage desk (own rail area) over the existing handbook, announcement, survey, recognition, wall and listening registers; events (GO-15) and polls (GO-16) add their entries when built) | GO-1 cluster; events GO-15, polls GO-16 |
| 3 | Tabs "Records" / "Reports" on each page; Reports tab = report picker + "CREATE VIEW" | Register and its default report | Lists plus report library templates per dataset | PARTIAL | Matrix 23 §23.1 #2 (WO-1 "Report" header link to the matching template) |
| 4 | Search "Search employee by name, code, designation, email" | Find an employee's records | Appraisals search employee code and name; Goals, Initiatives, Articles and Surveys search titles; Recognition searches the badge; Improvement plans and One-on-ones have no search | PARTIAL | WO-2 shared employee search on every register |
| 5 | "Filters" flyer with chips, CLEAR ALL, Apply | Filter panel | Filament filters | HAVE | — |
| 6 | Register filters built from `app-organization-filter` / `app-location-filter` / `app-bulk-employee-search` inside performance actions ("for OU", "Organization", "Location", "Add bulk employees") | Pick a population by organisation, location or pasted codes | Cycle eligibility rules (rule conditions) at cycle level only; actions on one record | PARTIAL | WO-2 filter group reused by GO-2 / GO-3 population pickers |
| 7 | "IMPORTS" / "Imports ▾" button (performance and engage import types per page: `importIds`) | Upload register data | No performance or engage upload type | MISSING | GO-10 (performance), GO-11 (engage) |
| 8 | Split action button with "expand_more" (Employee goals: "Initiate goal cycle ▾"; Company goal: "Create company goal ▾"; Goal requests: "Extend goal ▾"; Reviews and Review requests: "Initiate review cycle ▾"; 360 Feedbacks: "Initiate 360 feedback ▾"; PIP: "Add PIP ▾"; HR handbook, Announcement, Events, Survey, Candidate engagement: "Add … ▾") — menu holds the panel's other global actions *(inferred)* | Start HR actions from the register | Header actions per resource (Create, Launch, Publish ratings …) | PARTIAL → built (GO-1: "Performance actions" header menu — Initiate goal or review cycle, Create company goal, Normalise and publish ratings, Initiate 360 feedback, Add PIP, Remind reviewers — each a link to where it is done, shown only when it opens; extend / withdraw stay GO-2) | §27.9 maps each action; GO-1 adds the missing header entry points |
| 9 | "ACTIONS" button on pages without a primary action (360 Nominations, Initiatives, 1-on-1 Meetings, PIP evaluations, PIP letters, PIP evaluation letter, Polls, Badges, Reward points) | Action menu | Record actions only | PARTIAL → built (GO-1: the same menu on Initiatives, 1-on-1 meetings, PIP / PIP evaluations, Goals, Appraisals and the new goal-sheet / goal-request / review-request registers; engage registers keep their own header actions (polls GO-16)) | GO-1 (header menu per register) |
| 10 | Card list with avatar and SHOW MORE | Card view | Tables | PARTIAL | Matrix 23 §23.1 #10 (WO-1) |
| 11 | Note "In order to do bulk performance review, apply review cycle filter." (Review requests) and "In order to download letter in bulk, apply a status filter as published." (letter registers) | Bulk actions need a narrowing filter | Bulk publish on Appraisals (no filter needed); no bulk letter download | PARTIAL | WO-1 bulk framework; letters WO-13 |
| 12 | "Performance Setup" / "Engage Setup" panel links | Jump to setup | Setup centre modules Performance / Engage | HAVE | Setup matrix 12 |
| 13 | "Looking for something else" | Jump to another page | Command palette | HAVE | — |
| 14 | Banners (contact verification, subscription overdue, low bandwidth) | Vendor chrome | — | N-A (vendor chrome) | — |

---

## 27.2 Goals

### 27.2.1 Employee goals (`/app/processes/search/goal/search`, `goals_680`; `app-goal-search` → `app-goal-cards`; detail `app-goal-approval-view-form`, `app-goal-creation-detail`; Search right "Goal" View / Edit; imports 65, 124)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register: one card per employee goal sheet and goal period | HR sees every sheet in one list | Goals list = one row per goal (level, title, owner, type, perspective, cycle, weight, progress, key results, due date, status); goal plans (sheet submissions) appear only as pending items in Approvals | PARTIAL → built (GO-1: Goal sheets register — one row per employee and cycle with sheet status, goals, weightage, version, latest request; "View sheet"; "Goals" opens the list on that sheet) | GO-1: "Goal sheets" view — one row per employee and cycle (sheet status from `Goals::planStatus`, goal count, total weight, latest plan), opening the goals filtered to that sheet |
| 2 | Filter "Goal period" | Sheets of one period | Cycle filter | HAVE | Monthly goal periods inside a year: HP-8h/HP-8i in progress (HP-8i periodic goal cycles) |
| 3 | Filter "Status" | Sheet status (not created, pending, approved, sent back *(inferred)*) | Goal status filter (draft, pending approval, active, completed, cancelled); no sheet-level status | PARTIAL → built (GO-1: sheet status filter — not created, draft, waiting for approval, sent back, active) | GO-1 sheet status filter incl. "not created" (employees of the cycle without goals) |
| 4 | Card "Goal period :" | Period of the sheet | Cycle column | HAVE | — |
| 5 | Card "Version :" | Revision number of the approved sheet *(inferred)* | Every submission is a new `goal_plans` row with a goal snapshot (`GoalPlans::submit`); no version number or history view | PARTIAL | GO-5: "Versions" on a sheet (plans in order, decided by / at, snapshot diff) |
| 6 | Card "EDIT" | HR edits the sheet | Edit goal (allowed on locked goals for `performance.manage`) | HAVE | — |
| 7 | "View workflow" (`app-request-workflow-flyer`: levels, responded by / on, TAT, Send Reminder, add approver) | Approval trail of the sheet | Goal plan decided in the Approval Center or by a workflow instance (Workflow instances list); no link from the goal | PARTIAL → built (GO-1: "Approval" on the sheet and on each goal request — the workflow run (linked to its tracker) or the Approval Center; reminders / add approver stay RQ-2) → RQ-2 part built ("Send reminder" and the trail on the requester's request page for a goal change request; add approver stays RQ-9) | GO-1: "Approval" link on the sheet row (workflow instance or Approval Center item); reminders / add approver are matrix 20 RQ-2 |
| 8 | Sheet KRA rows: KRA name `keyResultAreaName`, % weightage `keyResultWeightage`, group `groupId` | KRAs with weights and perspective | Goals of type KRA with weight and perspective (HP-8a, HP-8d) | HAVE | — |
| 9 | Sheet KPI rows: KPI name, % weightage, target `keyPerformanceTarget`, unit `unitOfMeasurementId`, target due date `targetDueDate` | KPIs under a KRA | Key results with weight, target, unit, due date (`performance.goals.kpi_due_date`) | HAVE | — |
| 10 | Competency rows: competency name, % weightage | Competencies on the sheet | Competencies with weights on the cycle / template version (weighted scoring, HP-8d); shown in the review form, not with the goals | PARTIAL → built (GO-1: the sheet view lists the competencies rated with the goals, their weightage (as scoring uses it) and the competency share of the rating) | GO-1 sheet view lists the cycle's competencies and weights under the goals |
| 11 | "Attachment" / "View attachment" on the sheet | Supporting file per KRA *(inferred)* | Goals carry no files (evidence uploads exist only in reviews) | MISSING | GO-5: goal attachments (≤ 5, `ParityFileController` type `goal-attachment`, visible to whoever may see the goal) |
| 12 | "Check-In" per KPI (`app-check-in`: Progress, Comment, Status, Start date, End date) | Record progress for a period | Goal "Check in" (key result, value, how measured, note); no period and no status | PARTIAL | GO-4: check-in period and health status |
| 13 | Feedback template mapping on the sheet (`feedbackId`, "Please select the feedback template you want to map") | Feedback form answered at review | The goal template's feedback form (HP-8e, process `goal_review`); no per-sheet choice | PARTIAL | GO-5: optional feedback template on a sheet (manager / HR), overriding the template's |
| 14 | "View calculation" / rating formula flyer (`app-review-formula-flyer`: Achievement b/w, Customize target, rating formula, USE THIS, Add review rating formula) | KPI rating bands for the goal | KPI rating formulas per goal or unit (HP-8c) | HAVE | Per-goal custom bands not offered (formulas are shared masters) |
| 15 | Goal creation: "Create new goal" | Add goals | Create goal | HAVE | — |
| 16 | "Assign last approved" | Copy last cycle's approved sheet | — | MISSING | GO-5: copy the employee's approved goals of an earlier cycle into draft goals of the current one |
| 17 | "Assign recommended" | Recommended sheet for the role *(inferred)* | Goal templates with applicability, "Use template", system mapping (HP-8e) | HAVE | — |
| 18 | "Assign from Bank or Create New" (`app-kra-flyer`) | Pick from the goal library | KRA library with suggested KPIs | HAVE | — |
| 19 | "Generate with One AI" / "Copy goals from AI" (`app-copy-goal-from-chatGpt`: focus area, prompt, suggested KRAs and competencies, Select / Unselect all, Generate response, Add goals) | AI-drafted goals | AI assistant exists (`ai.use`); not connected to goals | MISSING | GO-5 (optional): draft goals through the existing AI controls; owner decision D-GO6 |
| 20 | "Copy goals of team member" (`app-copy-goal-from-team`: search, pick a member, Copy Goals, "Any changes made to the goal sheet will be lost") | Reuse a colleague's sheet | — | MISSING | GO-5: manager / HR copies a colleague's open goals into another person's draft sheet (never over an approved sheet) |
| 21 | Due badges on the goal-setting task (MISSED TO RESPOND, OVERDUE, DUE TODAY / TOMORROW) | Time pressure | Goal-setting stage window and reminders | PARTIAL → labels built (RQ-8: the unified inbox's due labels apply to the task once GO-9 creates it) | GO-9 task kind "Create goal"; labels with matrix 20 RQ-8 |
| 22 | Rights "Goal": View, Edit | Access | `performance.view`, `performance.manage` | HAVE | — |
| 23 | IMPORTS (ids 65, 124: Goals, Goal bank *(inferred)*) | Upload goals | — | MISSING | GO-10 |

### 27.2.2 Goal actions — Initiate goal cycle (`initiategoalcycle_987`, `app-initiate-goal-cycle`), Extend goal (`extendgoal_790`, `app-extend-goal-action`), Delete goals (`deletegoal_2129`, `app-delete-goal`), Change goal (`goaledit_852`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Initiate: "Goal policy" | Which performance policy | A cycle is its own policy (template version pinned at launch) | PARTIAL | HP-8h/HP-8i in progress (HP-8i performance policy) |
| 2 | Initiate: "Goal cycle" `goalCycle` / `goalPeriod` | Which goal period of the year | One goal-setting stage per cycle; no periodic goal cycles | PARTIAL | HP-8h/HP-8i in progress (HP-8i goal cycles) |
| 3 | Initiate: "Initiate goal cycle for selected employees" (`specificEmp`, `searched_employees`, "Add bulk employees") | Start goal setting for named people, e.g. joiners after launch | Launch covers everyone eligible at that moment; a later joiner gets no appraisal and no goal sheet | MISSING | GO-2: "Add employees to cycle" (named, OU, pasted codes; creates the appraisal, reviews of the current stage and the mapped sheet) |
| 4 | Initiate: "Initiate goal cycle for OU" (Organization, Location) | Start for an organisation unit | Cycle eligibility rules, set before launch | PARTIAL | GO-2 (same picker) |
| 5 | Initiate: confirmation dialog | Confirm | Launch confirmation | HAVE | — |
| 6 | Extend: "Goal cycle" | Which window | Stage dates are frozen once scheduled (`PerformanceCycle::booted`) | MISSING | GO-2: window extensions (`performance_window_extensions`: cycle, stage, employee or all, new end date, reason) read by `Goals::assertGoalPeriodOpen`, reminders and due labels |
| 7 | Extend: "for specific employees" / "organisation wise" / "Add bulk employees" | Who gets more time | — | MISSING | GO-2 |
| 8 | Extend: "Set new goal date" | New end date | — | MISSING | GO-2 |
| 9 | Delete goals: goal policy, goal cycle, selected employees / OU / bulk, "Delete Goal", Ok, confirmation | Remove the goal cycle of chosen employees | Goals are closed as cancelled one by one with a reason; nothing is deleted; HP-8k (delete transaction) blocked | MISSING | Open owner decision HP-8k (reused, not re-raised). Safe alternative in GO-2: "Withdraw from cycle" (cancels the person's open goals and pending plan with a reason, audited, nothing deleted) |
| 10 | Change goal (HR changes an approved sheet) | Revise goals for an employee | Edit goals as `performance.manage`; plan approval when the goal sheet policy asks; the employee side is matrix 20 §20.1.14 | HAVE | — |

### 27.2.3 Company goal (`/app/processes/search/createcompanygoal/search`, `createcompanygoal_998`; `app-createcompanygoal` → `app-company-goal-card`, `app-add-company-goal`, `app-add-kpi`; Search right View / Edit; imports 81, 82)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of company goals (objectives) | Organisation OKRs | Goals list, level filter company / business / department / team | HAVE | — |
| 2 | Filter "Performance cycle" | Cycle | Cycle filter | HAVE | — |
| 3 | Filter "Owner" | Who owns the objective | Organisation goals belong to an organisation unit; no owner person (only `created_by`) | PARTIAL | GO-4: `goals.owner_employee_id` for organisation-level goals, filter and column |
| 4 | Filter "Status" (Not started, On track, Behind, At risk, Close — `okr/Action/Status`) | Health of the objective | Lifecycle status only (draft … completed / cancelled); progress colour | MISSING | GO-4: `goals.health` (not_started / on_track / behind / at_risk; closed = status) set at check-in, filter |
| 5 | Filters "System calculated achievement" / "Actual achievement" | Computed vs entered achievement | Computed progress only | PARTIAL | GO-4: achievement override with reason (D-GO7) |
| 6 | Filters for the linked list: Search employees, Organization, Location, Status, Type, "Add bulk employees" | Narrow the linked people | — | MISSING | GO-4 with WO-2 filters |
| 7 | Linked-people table: S.no, Employee name, Linked, KPI details, Details | Who linked a KPI to this goal | Aligned child goals (`parent_id`); no table on the parent | PARTIAL | GO-4 (= MS-14 "Linked goals"): children with owner, KPI, progress |
| 8 | Columns "Task Period", "Status", "Comment", "Activity"; row actions Update / Edit | Period check-ins of each linked KPI | Check-ins per goal; audit history | PARTIAL | GO-4 / GO-6 KPI drawer |
| 9 | Note "Employee unlinked cannot be linked again." | Unlink is final | Alignment is an editable field (history in the audit trail) | PARTIAL | GO-4 keeps "unlinked on" in the table; re-linking stays allowed (the audit trail shows both) |
| 10 | Form: Performance cycle `performanceCycleId` | Cycle | Cycle | HAVE | — |
| 11 | Form: objective name `companyObjectiveName` | Title | Title | HAVE | — |
| 12 | Form: achievement calculated based on `achievementCalculateBasedOnOrganizationName` (options) *(inferred: own key results or linked KPIs)* | How progress is computed | From key results, else rolled up from aligned goals (`Goals::recompute`) | HAVE | — |
| 13 | Form: Visibility Public / Private `isVisibleToPublic` + "Visible to" (multiple) | Hide an objective from most people | Organisation-level goals are visible to everyone | MISSING | GO-4: `goals.visibility` (public default = today; private + visible-to rule conditions) |
| 14 | Form: Owner `ownerObj` | Accountable person | Organisation unit only | PARTIAL | GO-4 (row 3) |
| 15 | Form: Tags `groupObj` | Group objectives | — | MISSING | GO-4: tags (filterable) |
| 16 | Form: Key results — KPI name `keyPerformanceIndexName`, "How will you measure progress?" (unit / goal template search), Target value `keyPerformanceTargetValue`; Add, SAVE, SAVE AND NEW | Measurable results | Key results repeater (title, target, current, unit, due date, reverse) | HAVE | — |
| 17 | "Create company goal" confirmation | Save | Create | HAVE | — |
| 18 | IMPORTS (ids 81, 82: OKR total / final achievement *(inferred)*) | Upload achievements | — | MISSING | GO-10 |
| 19 | Rights "Company goal": View, Edit | Access | `performance.view`, `performance.manage` | HAVE | — |

### 27.2.4 Goal requests (`/app/processes/search/goal/history`, `goalhistory_640`; Search right "Goal history"; API `pms/goal/requestFlow/Search/History`; action 790 Extend goal)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of goal sheet requests (creation and change) with decisions | Audit of who submitted and who decided | `goal_plans` hold every submission and decision; pending ones in Approvals, decided ones on no screen | MISSING → built (GO-1: Goal requests register over `goal_plans` (employee, goal period, version, requested by / on, route, status, goals, weightage, decided by / on, note, approval trail); read-only) | GO-1: "Goal requests" list over `goal_plans` (employee, cycle, submitted by / at, approval route, status, decided by / at, note, snapshot) |
| 2 | Filters goal period, status | Narrow | — | MISSING → built (GO-1: goal period, status, route, requested and decided date filters, WO-2 employee filters, preset views) | GO-1 |
| 3 | Card: Goal period, Version, view workflow | Request summary | — | MISSING → built (GO-1: version number (n-th request of the sheet) and the approval trail; the snapshot diff between versions stays GO-5) | GO-1 / GO-5 |
| 4 | Header "Extend goal" | Extend the window | → §27.2.2 rows 6–8 | MISSING | GO-2 |

---

## 27.3 Reviews

### 27.3.1 Performance reviews (`/app/processes/search/review/search`, `performancereview_432`; `app-performance-review-search` → `app-review-cards`; detail `app-performance-view`, `app-review-approval`, `app-kra-detail`; Search right "Performance"; imports 109, 110)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register: one card per employee, performance cycle and review period | Every review in the organisation | Appraisals (cycle, code, name, manager, status, reviews done, self / manager / computed / calibrated / final rating); one per cycle, no review periods | PARTIAL | HP-8h/HP-8i in progress (HP-8i periodic reviews) |
| 2 | Filter "Performance cycle" | Cycle | Cycle filter | HAVE | — |
| 3 | Filter "Review cycle" (review period) | One review of the year | — | MISSING | HP-8h/HP-8i in progress (HP-8i) |
| 4 | Filter "Status" | Review status | Appraisal status filter | HAVE | — |
| 5 | Filter "Created date" / start date | When the review was raised | — | MISSING → built (GO-1: "Created" date range on Appraisals (launch date of the appraisal), with the WO-2 employee filters, for performance HR) | GO-1 date filter (launch date of the appraisal) |
| 6 | Card: Performance cycle, Review period, Created date | Summary | Cycle and status columns | PARTIAL | Review period with HP-8i (in progress) |
| 7 | "Employees selected" + "Review Reinitiate" / "Review Reinitiate all" | Send submitted reviews back to start again | Reviewers may resubmit while the cycle option "Allow reviewers to resubmit" is on; HR cannot reopen a review | PARTIAL → built (GO-1: "Reopen" (Reviews tab, Review requests, bulk) behind `performance.review.hr_reopen` — off by default, owner decision PER-1 / D-GO1 open) | GO-1: "Reopen review" (HR, reason, only before finalization; earlier submission kept in the audit) — owner decision D-GO1 |
| 8 | Detail (`app-performance-view`): Review tab with overall rating, average rating, stars, "View calculation"; KRA / competency tabs; initiatives; 1 on 1; 360; continuous feedback; trainings; variable / increment / promotion details | One employee's review in full | Appraisal page (scores, ratings, label, recommendations, summary, comments, goal-sheet feedback) + Reviews tab | PARTIAL | Same component as the scorecard: matrix 22 §22.9 (MS-13 panel reused on the HR view) |
| 9 | Reviewer recommendations (`app-kra-detail`): training (name, due days, remarks, "send training initiatives to employee"), promotion (designation, remarks), salary increment (amount), variable (pay month, pay component, amount), remarks | Outcomes proposed in the review | `promotion_recommended`, `pip_recommended` at finalize; development needs separate | PARTIAL | Setup PF-9 → SV-11 = HP-8h/HP-8i in progress (HP-8i); increments and variable only as proposals into compensation cycles |
| 10 | Reviewer form extras: KPI completion date, Check in history, Initiative Details, Save & next, Skip to last step, UPLOAD DOC | Context while rating | Review form: per goal / key result rating and comment, competencies, overall, strengths, areas to develop, evidence upload, formula hints, copy previous level | PARTIAL | GO-6 KPI drawer reused in the review form (check-ins, initiatives of the goal) |
| 11 | "Generate using One AI" / "Rephrase using One AI" in review comments | AI text help | — | MISSING | GO-5 optional AI (D-GO6) |
| 12 | Workflow flyer on the card | Who has reviewed | Reviews tab: each review with reviewer, type, status, submitted at | HAVE | — |
| 13 | IMPORTS (ids 109, 110: Review ratings, Normalize review ratings *(inferred)*) | Upload ratings | — | MISSING | GO-10 after HP-8i (in progress) |
| 14 | Right "Performance: View" | Access | `performance.view` / `performance.calibrate`, `AppraisalVisibility` | HAVE | — |

### 27.3.2 Performance review requests (`/app/processes/search/review/history`, `performancehistory_464`; Search right "Performance history"; API `pms/review/Request/History/Admin`; import 109)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of review requests (each reviewer level's task and its state) | Track who still has to review | Reviews per appraisal on its Reviews tab; Home "Reviews to complete" for each reviewer; no organisation-wide list | PARTIAL → built (GO-1: Review requests register over `appraisal_reviews` (employee, cycle, level, reviewer, status, due, created, submitted), within the appraisal access scope and AppraisalVisibility) | GO-1: "Review requests" list over `appraisal_reviews` (employee, reviewer, type / level, status, submitted at, due = stage end), within `AppraisalVisibility` |
| 2 | Filters performance cycle, review cycle, status, created date | Narrow | — | MISSING → built (GO-1: performance cycle, review level, status, appraisal status, created and submitted date filters; a periodic-review ("review cycle") filter belongs to HP-8i's periodic reviews) | GO-1 (review cycle with HP-8i, in progress) |
| 3 | Note "In order to do bulk performance review, apply review cycle filter." + selection | Bulk work on reviews | — | MISSING → built (GO-1: bulk "Remind reviewers" and (setting on) "Reopen reviews" on selected rows or all matching (a filter or search first); HR never submits a review) | GO-1: bulk "Remind reviewers" and bulk "Reopen" (D-GO1); HR does not submit reviews on behalf (reviews are the reviewer's own judgement) |
| 4 | Header "Initiate review cycle ▾" | Start / extend / delete reviews | → §27.3.3 | PARTIAL | — |

### 27.3.3 Review actions — Initiate review cycle (`initiatereviewcycle_988`, `app-initiate-review-cycle`), Extend review (`extendreview_791`, `app-extend-review-action`), Delete reviews (`deletereview_2130`, `app-delete-review`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Initiate: "Review policy" `reviewPolicy` | Which policy | Cycle / template version | PARTIAL | HP-8h/HP-8i in progress (HP-8i) |
| 2 | Initiate: "Review cycle" `reviewCycle` (e.g. "Performance Policy [Sep 2026 - Sep 2026]") | Which review period | "Move to self review / manager review" advances the whole cycle | PARTIAL | HP-8h/HP-8i in progress (HP-8i review windows; manual initiation PF-7) |
| 3 | Initiate: selected employees / OU / Location / "Add bulk employees" | Start reviews for some people | Stage advance applies to every appraisal | MISSING | GO-2 (start the current stage's reviews for named appraisals, add joiners) |
| 4 | Extend review: review cycle, specific employees / organisation-wise, bulk, "Set new review date" | More time to review | Stage dates frozen; reminders read them | MISSING | GO-2 window extensions |
| 5 | Delete reviews: review policy, review cycle, selected employees / OU / bulk, "Delete Review", confirmation | Remove reviews | Reviews and appraisals are never deleted | MISSING | Open owner decision HP-8k; GO-2 "Withdraw from cycle" alternative (non-finalized appraisals only, reason, audited) |

### 27.3.4 Normalize and publish — Normalize review rating (`normalizereviewrating_1021`), Normalize performance rating (`normalizeperformancerating_1022`), Publish performance rating (`publishperformancerating_1023`); `app-normalize-rating`, `app-normalize-rating-flyer`, `app-normalize-rating-bell-curve`, `app-publish-performance-rating-flyer`

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Performance cycle" `performanceCycleId` | Cycle | Calibration board cycle picker | HAVE | — |
| 2 | "Review cycle" `reviewPeriodId` (normalize review rating) | Normalise one periodic review | — | MISSING | HP-8h/HP-8i in progress (HP-8i), then GO-3 per review period |
| 3 | Population: specific employees / Organization / Location / "Add bulk employees" | Whom to normalise | Board filter by department / grade; calibration sessions pick appraisals | PARTIAL | GO-3 (WO-2 filter group on the board) |
| 4 | Grid: Employee name, Employee code, System calculated rating, Normalized rating, Rating input; "Search team member"; note "Select the employee and add normalize rating"; Previous / Done | Set many normalised ratings at once | Board table (appraisal columns) with "Calibrate" per row (rating + reason); sessions adjust with reasons | PARTIAL | GO-3: inline normalise grid (one reason for the batch; each row through `Appraisals::calibrate`, audited per appraisal) |
| 5 | "Performance distribution bell curve" flyer | See the distribution while normalising | Target vs actual chart, gaps, tolerance (HP-8g) | HAVE | — |
| 6 | Publish flyer: Select employees, Employee name, code, system calculated rating, normalized rating; "Select the employee and publish performance rating" | Release chosen ratings | "Publish ratings" for the cycle (board, cycle) or the selected appraisals (bulk action), manual publishing (HP-8b) | HAVE | — |
| 7 | Rights (Action → Performance): Normalize review rating, Normalize performance rating, Publish performance rating | Access | `performance.calibrate` (performance rating); review rating waits | PARTIAL | HP-8h/HP-8i in progress (review rating) |

---

## 27.4 Continuous feedback

### 27.4.1 360 Feedbacks (`/app/processes/search/feedback360/search`, `360feedback_606`; `app-policy360-search` → `app-policy360-feedback-card`, `app-policy360-feedback-details`; `app-initiate360-feedback`; Extend 360 `app-extend-threesity-action`; APIs `threesixty/feedback/Admin/Search`, `setting/threesixty/policy`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of 360 feedback per employee and period | Multi-rater rounds | Peer, upward, skip-level and external reviews inside an appraisal (Reviews tab); no standalone rounds or list | MISSING → built (HP-8h rounds register; GO-1 desk entries "360 feedbacks" and "360 nominations") | HP-8h/HP-8i in progress (HP-8h feedback rounds); list in GO-1 once rounds exist |
| 2 | Filters "Period", "Feedback type" | Narrow | — | MISSING | HP-8h/HP-8i in progress (HP-8h) |
| 3 | Card: "Type :", "Period :" | Summary | — | MISSING | HP-8h/HP-8i in progress (HP-8h) |
| 4 | Detail: groups Peers, Subordinate, Client, Reporting manager, Functional manager; Average rating; "Chose not to participate."; IGNORED; answers (rating, subjective answer, attachment) | Results of a round | Review content per reviewer (overall rating, strengths, improvements, comments, anonymous); no decline, no question form | PARTIAL | HP-8h/HP-8i in progress (HP-8h responses on feedback templates, decline); who sees them = open owner decision "peer visibility" (reused) |
| 5 | Initiate 360 feedback: "360 feedback policy" `policy360`, "360 Policy cycle" `policyCycle`, selected employees / OU / bulk | Start a round | "Add reviewer" per appraisal (HR / manager) | PARTIAL | HP-8h/HP-8i in progress (HP-8h) |
| 6 | Extend 360: "360 cycle" `threesixtyCycle`, specific employees / organisation-wise, bulk, "Set new 360 date" | More time | — | MISSING | HP-8h/HP-8i in progress (HP-8h window; GO-2 shares the extension service) |
| 7 | Right "360 feedback: View" | Access | Peer reviews visible to `performance.view` on the appraisal | PARTIAL | HP-8h/HP-8i in progress (HP-8h) |

### 27.4.2 360 Nominations (`/app/processes/search/nomination360/search`, `nomination_606`; `app-policy360-nomination-card`, `app-policy360-nomination-view-form`, `app-policy360-nomination-details`, `app-policy360-send-nomination-detail`; API `threesixty/nomination/Admin`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of nominations per employee and period | Who was nominated to give 360 | — | MISSING | HP-8h/HP-8i in progress (HP-8h `feedback_nominations`) |
| 2 | Filters "Period", "Creation (completion) status", "Approval status" | Narrow | — | MISSING | HP-8h/HP-8i in progress (HP-8h) |
| 3 | Card: "On :", "Period :", "Creation status :", "Approval status :", workflow | Summary | — | MISSING | HP-8h/HP-8i in progress (HP-8h) |
| 4 | Nomination form tabs Peers (n), Subordinates (n), Clients (n): colleague search; Client name, Designation, Email, "Duplicate email", Add Client; Comments; SAVE / UPDATE | Nominate reviewers incl. external clients | Reviewers added by hand on the appraisal; review type `external` without an invitation flow | MISSING | HP-8h/HP-8i in progress (HP-8h; external reviewers by e-mail = PF-4) |
| 5 | Send nominations for approval / approve nominations | Approval of the list | — | MISSING | HP-8h/HP-8i in progress (HP-8h Approval Center source) |

### 27.4.3 Initiatives (`/app/processes/search/initiative/search`, `goalsandinitiatives_2207`; `app-goals-initiatives-search` → `app-initiative-cards` → `app-my-goal-and-initiatives-flyer`, `app-initiative-details`, `app-assign-initiative`, `app-new-initiative`, `app-feedback-initiative`, `app-initiative-comments-flyer`, `app-initiative-activity-log`; API `initiative/Goals/Search/Admin`; import 115)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of employees' initiatives | HR overview | Initiatives "All" tab (HR scope): employee, title, goal, due, completion, status, assigned by, feedback given (HP-8j) | HAVE | — |
| 2 | Card flyer "Goals and initiative" with "Period" select (the employee's goals and initiatives together) | One person's goals and actions | Initiatives and goals are separate lists | PARTIAL → built (GO-1: "Goals and initiatives" drawer on Initiatives — the employee's goals and initiatives of a chosen cycle, as the goal and initiative rules let the viewer see them) | GO-1 employee drawer (goals + initiatives of a cycle), shared with MS-14 |
| 3 | Detail: Initiative, Initiative type, Due date, Created on, Assigned to, Created by, "Planned" | Initiative facts | View: title, employee, assigned by, type, goal, start / due, status, progress, completed at, outcome | HAVE | — |
| 4 | Completion slider 0–100 and "Are you sure, you want to complete?", Completed on | Progress and completion | Update completion, Complete (confirmation, outcome) | HAVE | — |
| 5 | "Do you want to request feedback for this initiative?" choose employee, SUBMIT; VIEW FEEDBACK | Ask a colleague | Request feedback; feedback section | HAVE | — |
| 6 | Comments flyer ("Type your comment here", no comments) | Discussion on an initiative | No comment thread (audit trail only) | MISSING | GO-8: initiative comments (append-only, visible to who may view the initiative) |
| 7 | "View activity log" (`app-initiative-activity-log`) | History | Audit history | HAVE | — |
| 8 | Create (`app-assign-initiative`): Enter an initiative `name`, Due date `dueDate`, Assign initiative to `employee`, KPI `kpi`, type `initiativeTypeId`; Save / Save and new | Assign an initiative | Create: employee, type, title, description, goal (the employee's), start, due; "Create another" | HAVE | — |
| 9 | Feedback task (`app-feedback-initiative`: initiative, type, due, complete date, Enter feedback, IGNORED) | Answer a request | Answer / Ignore in My tasks (`InitiativeFeedbackTaskSource`) | HAVE | — |
| 10 | "Buddy of" (initiative type BUDDY) | Name the colleague the initiative is about *(inferred)* | Type BUDDY exists (masters); no related person | PARTIAL | GO-8: optional `related_employee_id` for buddy / induction types |
| 11 | IMPORTS (id 115, Initiatives) | Upload | — | MISSING | GO-10 |
| 12 | Right "Goals and initiatives: View" | Access | `performance.view`, `performance.initiatives_manage` | HAVE | — |

### 27.4.4 1-on-1 Meetings (`/app/processes/search/oneonone/search`, `oneonon_635`; `app-one-on-one-search`, `app-one-on-one-detail`, `app-schedule-one`, `app-recurrence-dialog`; API `oneonone/request/Search`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of all one-on-ones | HR overview | One-on-ones list (HR in scope, hosts and participants their own) | HAVE | — |
| 2 | Filters "Initiated by", "1-on-1 with", "Request to", status; employee search | Narrow | No filters | MISSING | GO-7 (initiated by, host, participant, status, date range) |
| 3 | Card: "1-on-1 meeting", initiator "&" participant, "On :" | Summary | Columns scheduled at, employee, host, agenda, action items, feedback count, status | HAVE | — |
| 4 | Detail: Agenda, Select date, Start time / End time | When and what | Agenda, scheduled at (no end time) | PARTIAL | GO-7: `ends_at` |
| 5 | Time zone `timeZoneId`, Meeting type `meetingType`, Meeting room / URL `meetingAddress` | Where and how | — | MISSING | GO-7 (= MS-13 fields) |
| 6 | Minutes: Progress `requestProgress`, Plans `requestPlan`, Challenges `requestProblems`, Any other feedback `otherMinutesOfMeeting` | Structured minutes | Notes (free text), private notes (host only), action items | PARTIAL | GO-7: four optional minute fields beside notes |
| 7 | Comments, Attachments, Upload | Files and remarks | — | MISSING | GO-7: attachments (≤ 5) |
| 8 | "Show last 1-on-1" | Previous meeting of the pair | — | MISSING | GO-7 link to the previous meeting |
| 9 | "Reschedule 1-on-1" | Move it | Edit scheduled at | HAVE | — |
| 10 | "Are you sure you want to ignore?" NO / YES, IGNORE; "UNPUBLISHED" | Decline / withdrawn meeting | Status cancelled (record kept) | HAVE | — |
| 11 | Frequency `intervalId` + recurrence dialog | Recurring series | One meeting per record | MISSING | GO-7 (= MS-13 series) |
| 12 | 1-on-1 acknowledgement (`app-one-on-one-acknowledge`) | Participant confirms | Feedback card = acknowledgement (HP-8b, `performance.one_on_one.feedback_from`) | HAVE | — |

---

## 27.5 Performance improvement

### 27.5.1 PIP (`/app/processes/search/pip/search`, `pip_955`; `app-pip-search` → `app-pip-cards`, `app-pip-request-flyer` → `app-pip-request`; approval `app-pip-approval-details`; global action "Add PIP")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of PIPs | HR overview | Improvement plans: employee, manager, start, end, reasons, objectives, status, open checkpoints, outcome | HAVE | — |
| 2 | Filter "Status" | Narrow | Status filter | HAVE | — |
| 3 | Filters "Approval date", "Request date", "Pip end date" | Date ranges | — | MISSING → built (GO-1: requested, approved and PIP end date ranges (performance HR)) | GO-1 date filters |
| 4 | Search by name, code, designation, email | Find | No search on the plan list | MISSING | WO-2 |
| 5 | Card: PIP period, Pip start date, Pip end date, Pip request date, Pip approval date, Approved by | Summary | Start / end and status shown; `approved_by` / `approved_at`, created at stored but not listed | PARTIAL → built (GO-1: Requested (on, by) and Approved (on, by) columns (performance HR)) | GO-1 columns |
| 6 | View workflow | Approval trail | Approval Center item or workflow instance; no link | PARTIAL → built (GO-1: "Approval" column — workflow run (tracker link) or Approval Center while waiting) | GO-1 |
| 7 | Add PIP: Start date, End date | Period | Start / end (policy duration and extensions, HP-8f) | HAVE | — |
| 8 | Add PIP: "Reason of PIP" `pipReasonIds` | Reasons | PIP reasons (`reason_codes`) | HAVE | — |
| 9 | Add PIP: Expected standards `performanceExpectedStandard`, Description `performanceImprovementReason`, Outcomes | What must change | Reason details, objectives (objective, measure), checkpoints | HAVE | — |
| 10 | "+ Add Initiative" inside the PIP | Actions of the plan | Initiatives exist (HP-8j) but are not linked to a PIP | MISSING | GO-8: `initiatives.improvement_plan_id`, "Add initiative" on the plan |
| 11 | "Schedule Meeting" / "+ Add 1 On 1" inside the PIP | Review meetings of the plan | One-on-ones exist, not linked | MISSING | GO-8: `one_on_ones.improvement_plan_id`, "Schedule 1-on-1" on the plan |
| 12 | Attachment / Upload attachment / View | Supporting documents | — | MISSING | GO-8: plan attachments (served to who may see the plan) |
| 13 | Approver remarks | Decision note | Decision note | HAVE | — |
| 14 | PIP approval (inbox `app-pip`) | Approve the plan | Approval Center `pip` | HAVE | — |

### 27.5.2 PIP evaluations (`/app/processes/search/pipevaluation/search`, `pip_975`; `app-pip-evaluation-search` → `app-pip-eval-card`, `app-pip-eval-details`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of PIPs at or after evaluation | Outcomes to decide / decided | Improvement plans with status filter (outcome waiting, completed, unsuccessful, extended) | PARTIAL → built (GO-1: "PIP evaluations" view of the plan register (and "Evaluation due")) | GO-1 preset "PIP evaluations" |
| 2 | Filters Status, "Evaluation Status", Approval / Request / Pip end date | Narrow | Status only | PARTIAL → built (GO-1: "Evaluation status" filter (due, waiting for approval, improved, no improvement, extended, closed) with the request / approval / end date ranges) | GO-1 |
| 3 | Card fields (as PIP) + "PIP EVALUATION" | Summary | As §27.5.1 row 5 | PARTIAL → built (GO-1: the PIP register's requested / approved columns and approval trail) | GO-1 |
| 4 | Outcome: "Improved Performance" / "No Scope of Improvement" / "Extend PIP" + "Pip Extension date" | Decide the outcome | Close as successfully completed / unsuccessful with outcome text; Extend (new end, reason, policy limits) | HAVE | — |
| 5 | Approver remarks, Attachment, Upload | Evidence with the decision | Outcome / decision note; no file | PARTIAL | GO-8 attachments |
| 6 | Detail: PIP details, PIP attachment, "View all details", Activity log, workflow | Context | Plan view, checkpoints, audit history | PARTIAL | GO-8 (attachment) |
| 7 | Last 1-on-1 (agenda, date, time, progress, plans, challenges, other feedback), "Show last 1-on-1", initiatives of the plan | Evidence from meetings and actions | — | MISSING | GO-8 after GO-7 |
| 8 | "Are you sure you want to ignore?" YES, IGNORE | Skip the evaluation | Close out (reason) | HAVE | — |
| 9 | PIP evaluation approval (inbox `app-pip-evaluation`) | Approve the outcome | Approval Center `pip_evaluation` | HAVE | — |

---

## 27.6 Performance letters — Performance letters (`performanceletter_696`), PIP letters (`/app/processes/search/pipletter/search`, `pipletter_955`), PIP evaluation letter (`/app/processes/search/pipevaluationletter/search`, `pipletter_803`); `app-employee-letter-search` → `app-employee-letter-grid`, `app-employee-letter-process-card`, `app-other-letter-generation`; APIs `workforce/LetterList/4/29`, `OtherLetter/trigger/event/type`

The three registers are HROne's generic letter register (rated row by row in matrix 23 §23.7.1 / §23.7.2, WO-13) filtered
to a performance letter type. Only what is specific to performance is rated here.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Three registers, one per letter type | A queue per performance letter | One Letters list with a type filter; types `performance`, `pip`, `pip_outcome` exist | PARTIAL → built (GO-1: Letters views "Performance letters", "PIP letters", "PIP evaluation letters" and their desk entries; the rest of the letter register stays WO-13) | WO-13 per-type presets (navigation entries with the type filter) |
| 2 | PIP letters and PIP evaluation letters assigned by the process | Letter waiting after PIP start / outcome | PIP policy drafts the PIP letter on activation and the outcome letter on close (HP-8f) | HAVE | — |
| 3 | Performance letters assigned when ratings are released ("Generate performance letter" + template on the performance policy, PF-11) | Appraisal letter per employee | Generated by hand only | MISSING | HP-8h/HP-8i in progress (HP-8i / SV-11 `letter_template_id`; letter drafted at publication through `Letters::generate`) |
| 4 | Filters Status, "Choose letter template", "Signature Type", Employee type, Generated by, Generated date, User type; search | Narrow | Type filter; employee search | PARTIAL | WO-13 |
| 5 | Card "Generated by :", "Generated on :", "Type :", document viewer | Letter facts | Letter view | HAVE | — |
| 6 | Generate letter (`app-other-letter-generation`: template, specific employee / organisation-wise, publish date and time, e-mail sharing, up to four signatories, download) | Generate on demand | Generate letter (one employee, template, one signatory, approval, issue) | PARTIAL | Matrix 23 §23.7.1 rows 5–14 (WO-13) |
| 7 | "Schedule letters" / "View schedules" | Scheduled generation | — | MISSING | WO-13 |
| 8 | Bulk download ("apply a status filter as published") | Download many | One at a time | MISSING | WO-13 |
| 9 | Rights View letter, Generate letter, Unpublish (pip letter) | Access | `letter.view`, `letter.issue`; no unpublish | PARTIAL | WO-13 "Revoke" (D-WO5) |

---

## 27.7 Goal dashboards — HR Desk → HR Analytics → Goals, Team → Team Analytics → Goals, Analytics → Business Intelligence / Custom Analytics → Goals (`menu_HR_Desk__HR_Analytics__tab_02_Goals`, `menu_Team__Team_Analytics__tab_02_Goals`; `app-team-goal-dashboard`, `app-hr-goal-dashboard-sidebar`, `app-goal-dashboard-sidebar`, `app-kpi-details-flyer`, `app-kpi-details-activity-log-flyer`, `app-goal-checkin-dialog`)

Matrix 21 has no row for this tab (its §21.1 covers `/app/analytics/*` only) and matrix 20 §20.3.3 rows 6–8 sent the team
version here.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Select performance cycle" | Cycle | Goals list cycle filter; no goal dashboard | PARTIAL | GO-6: "Goal dashboard" page (HR scope; managers their team) |
| 2 | "Processes (n)" filter, Select all / Clear all, APPLY | Which goal processes (company / team / individual *(inferred)*) | Level filter on the list | PARTIAL | GO-6 |
| 3 | "Progress": ≤ 50 % / ≥ 50 % | Behind / ahead split | Progress column colour (≥ 70 / ≥ 40) | PARTIAL | GO-6 progress band filter |
| 4 | "Achievement status": On track, At risk, Behind, Closed | Health | — | MISSING | GO-4 health + GO-6 filter |
| 5 | "Goal status": Started, Not started | Any progress yet | Progress 0 = not started (no filter) | PARTIAL | GO-6 |
| 6 | "People filter" (HR: organisation; team: direct / indirect reports, organisation-unit dialog) | Whose goals | Access scope; managers see their reports' goals | PARTIAL | GO-6 with WO-2 (HR) and the RQ-11 team scope (managers) |
| 7 | Goal cards with status; SHOW MORE; "Currently no goals available." | Goal tiles | Goal rows | PARTIAL | GO-6 |
| 8 | KPI details flyer: System calculated status, Total Achieved, Total no of KPI's mapped, KPI S.no, Goal Cycle, Achieved, Linked | KPI drill-down | Goal edit page (key results, progress); no read-only drawer, no linked count | PARTIAL | GO-6 KPI drawer (with GO-4 linked goals) |
| 9 | Activity log flyer: Task period, Status, Comment | Check-in history per KPI | Check-ins stored (`goal_check_ins`); audit history | PARTIAL | GO-6 (check-in list in the drawer) |
| 10 | Goal check-in dialog: From Date, To Date, Update your progress, Status, Comments, Update Progress, success dialog | Check in for a period | "Check in" (value, how measured, note) | PARTIAL | GO-4 (period, health status) |
| 11 | "Share goal on enterprise wall": image (change / remove), message ("Incredible Achievement Unlocked!", editable), Publish, Cancel | Celebrate a goal | Automatic wall post when a goal completes (`engage.wall.show_achievements`, HP-9e); no manual share, no image | PARTIAL | GO-6: "Share on the wall" for a completed goal (own message, optional image; wall rules apply) |
| 12 | Team → Team Analytics → Goals (same page for a manager's team) | Team goal progress | Managers see their reports' goals in the list | PARTIAL | GO-6 team mode (matrix 20 §20.3.3 rows 6–8) |

---

## 27.8 Engage

### 27.8.1 HR handbook (`/app/processes/search/policy/search`, `policy_189`; `app-policy-search` → `app-policy-card`, `app-create-policy` (Details · Applicability · Advance applicability · Review), `app-create-policy-step1`, `app-create-policy-review`, `app-hr-handbook-policy`; Search rights Update / View / Unpublish; action "Add HR handbook")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of handbooks (cards) | HR's handbook list | Articles (title, category, published version, acknowledgement, mandatory reading, status, reads / acknowledged of audience, published at) | HAVE | — |
| 2 | "Search keyword" | Find | Title search | HAVE | — |
| 3 | Filters Publish status, Created On, Published On | Narrow | Status, category, created and published date ranges (HP-9a) | HAVE | — |
| 4 | Card: Created on, Last updated on, Published on; ⋮ → View, Clone | Summary and copy | View; no clone | PARTIAL | GO-13: "Clone" (new draft from the published version) |
| 5 | "Reorder policies for employee view" (drag and drop, Done) | Order shown to employees | Employees see articles by category and title | MISSING | GO-13: `articles.sort_order` (reorderable list; default = today's order) |
| 6 | Wizard Details · Applicability · Advance applicability · Review; Next / Previous | Guided authoring | Article form (Article; Audience & acknowledgement), review before publication | HAVE | — |
| 7 | Subject `policySubject` | Title | Title | HAVE | — |
| 8 | Body `policyBodyWithHtml` (rich text) | Content | Markdown body (versions) | HAVE | — |
| 9 | Handbook sections: "Add Your First Section", section name (suggestions), section content `sectionContentWithHtml`, edit / delete section | Structured handbook | One body (headings inside the Markdown) | PARTIAL | GO-13: sections repeater (name + content) rendered with a table of contents; versions as today |
| 10 | "Add HR handbook link to inbox / request" Yes / No + "Select the inbox / request" `inboxRequestTypeId` | Show the policy on a request form | Announcements may link an article; request forms show no policy | MISSING | GO-13: `articles.request_types` (leave, regularisation, expense, travel, loan …) → "Read the policy" link on those forms |
| 11 | URL `mediaLink` | External link | — | MISSING | GO-13 (https link) |
| 12 | Attachment (≤ 5 MB: pdf, doc(x), xls(x), images, zip, rar) | Policy files | No files on articles; download of the published text (HP-9a) | MISSING | GO-13: attachments served by `ParityFileController` to the audience |
| 13 | Applicability (organisation units, Select all, "Add bulk employees") | Who receives it | Audience rule conditions (empty = everyone) | HAVE | — |
| 14 | Advance applicability (From days / To days on an attribute *(inferred: tenure)*) | Joiners only, etc. | Rule conditions on attributes; no tenure-days attribute | PARTIAL | GO-12 adds "days since joining" to the rule context (shared with announcements and surveys) |
| 15 | Note "Applicability and advance applicability cannot be changed while editing the handbook." | Audience fixed after publication | Each publication is a version; the audience is part of the article | HAVE | — |
| 16 | Unpublish, Edit | Withdraw / change | Archive; revise (new version, review) | HAVE | — |
| 17 | Acknowledgement and "HR handbook acknowledgement report" | Who accepted | Acknowledge with due days, "Who acknowledged" + CSV (HP-9a) | HAVE | — |
| 18 | Settings gear (`app-common-settings`, incl. Delete Transaction) | Module settings | Setup centre | N-A (setup matrix 12 §12.0) | — |

### 27.8.2 Announcement (`/app/processes/search/announcement/search`, `announcement_188`; route `/app/processes/engage/announcement` → `app-hr-announcement`; `app-announcement-card`, `app-announcement` (Details · Frequency · Applicability · Advance Applicability · Review), `app-announce-step1`, `-step2`, `-step-teams`, `-step-review`, `app-announce-preview`; Search rights Update / View / Unpublish; action "Add announcement")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of announcements (cards; "NO ANNOUNCEMENT") | HR's announcements | Announcements: title, type, priority, audience, acknowledgement, status, sent / failed / read / ack of audience, publish / expiry | HAVE | — |
| 2 | Filters Status, Frequency, Created on, Published on | Narrow | Status and type filters | PARTIAL | GO-12: created / published date ranges; frequency once recurrence exists |
| 3 | Card: author, "Frequency :", "On :", "Subject :", Published / Unpublished; ⋮ → View, Clone | Summary and copy | View; no clone | PARTIAL | GO-12 "Clone" (draft copy) |
| 4 | Subject `announcementSubject` | Title | Title | HAVE | — |
| 5 | Message `announcementBodyWithHtml` + "employee_name" tag | Personalised text | Markdown body; no personalisation | PARTIAL | GO-12: `{name}` placeholder rendered per recipient |
| 6 | "Announcement templates" (search, PREVIEW, USE THIS) | Start from a template | — | MISSING | GO-12: saved announcement templates ("Copy from template") |
| 7 | Delivery: HROne Inbox `isInbox`, E-mail `isEmail`, WhatsApp `isWhatsapp` | Channels per announcement | Channels from each person's communication preferences (in-app, e-mail; mandatory types cannot be muted) | PARTIAL | GO-12: per-announcement channel choice (in-app always, e-mail optional); WhatsApp → row 8 |
| 8 | WhatsApp message `whatsappMessage`, "WhatsApp Bot license not available", "Explore Marketplace" | WhatsApp delivery | — | N-A (external provider, IP-07) | — |
| 9 | CC Recipients (e-mail addresses) | Copy people outside the audience | — | MISSING | GO-12: CC to internal users only (no free external addresses) |
| 10 | "Add Video URL" + YouTube preview `announcementVideoPath` | Embedded video | Attachment upload only | MISSING | GO-12: https video link, embedded from an allow-list of hosts |
| 11 | "Add attachment" | File | Attachment (one file, checksum) | HAVE | — |
| 12 | Frequency "One time": Publish date `publishDate` | Scheduled publication | `publish_at`, `expires_at` | HAVE | — |
| 13 | Frequency "Event based": event `eventTypeId`, Before / After `beforeAfterType`, days `beforeAfterDays` | Send N days around an employee event (joining date etc.) | — | MISSING | GO-12: event-based announcements (joining date, confirmation, birthday, work anniversary) delivered per person by a daily run |
| 14 | Frequency "Reoccurrence": `recurringTypeId`, month of year, day of week / month; "Due day" `dueDay` | Repeating announcement with a response window | One publication per announcement | MISSING | GO-12 (= MS-15 `announcements.repeat`) |
| 15 | Applicability (organisation units, Select all, "Add bulk employees") | Audience | Audience (organisation units, specific employees, managers' reports) | HAVE | — |
| 16 | Team step "Select reportees": Direct / Indirect / Functional / Specific employees, bulk | Manager's team audience | — (HR authoring) | PARTIAL | Matrix 22 §22.14.18 (MS-15 team announcements) |
| 17 | Advance applicability (From days / To days) | Tenure window | — | PARTIAL | GO-12 "days since joining" rule attribute |
| 18 | Review step, Preview, "Announcement will be published once the service is executed." | Check before sending | Submit → approval (never one's own) → publish; queued delivery | HAVE | — |
| 19 | Unpublish, Inactivate, Edit | Withdraw / change | Cancel (reason), archive, new version | HAVE | — |
| 20 | "No of people shared with", "No of people responded" | Reach | Audience, sent, read, acknowledged counts | HAVE | — |
| 21 | Per-person read status ("Announcement read status" report) | Who read | Reads recorded (`AnnouncementRead`); counts only | PARTIAL | GO-12 "Who read" list + CSV (like articles); dataset AN-22 (matrix 21) |

### 27.8.3 Events (`/app/processes/search/events/search`, `events_2277`; `app-event-search` → `app-events-card`, `app-events-preview`, `app-events-management` (Details · Frequency · Rewards & feedback · Applicability · Review), `app-event-details`, `app-event-frequency`, `app-event-reward`, `app-events-applicability`; inbox `app-events`, `app-events-feedback-template`; Search rights View / Unpublish / Update; import 139; action "Add event")

PeopleOS has no engage events (Gap Register EN-06). Learning's training sessions (sessions, attendees, attendance) are the
closest pattern but serve courses only.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of events | HR's events | — | MISSING | GO-15 |
| 2 | Filters Status, Event timeline, Type of event, Type of sub event | Narrow | — | MISSING | GO-15 |
| 3 | Card: Created by, Date, Time, Seats, Nomination, Attendance | Summary | — | MISSING | GO-15 |
| 4 | Preview / Attendance tabs: Employee Name, Nomination, Attendance, Action, "Select date", "Download excel" | Nominations and attendance per day | — | MISSING | GO-15 (attendance per session date, CSV) |
| 5 | Edit; Unpublish with Reason ("the event will be canceled, and attendees will be notified"; back-dated warning) | Change / cancel | — | MISSING | GO-15 (cancel with reason; attendees told; data kept) |
| 6 | Details: Event name `eventName`, Description `eventDescription`, Type of event `eventTypeId`, Type of sub event `subEventTypeId` | What the event is | — | MISSING | GO-15 (option lists `event_types`, `event_sub_types`) |
| 7 | Agenda `eventAgenda` (rich text, "Generate using One AI") | Programme | — | MISSING | GO-15 (AI: D-GO6) |
| 8 | "Is the event mandatory to attend?" `isEventMandatoryToAttend` | Mandatory or optional | — | MISSING | GO-15 |
| 9 | Event host (one host) | Organiser | — | MISSING | GO-15 |
| 10 | Limited seats `isLimitedSeat` + Number of seats `numberOfSeat` | Capacity | — | MISSING | GO-15 (nominations stop at capacity, waitlist not built) |
| 11 | Frequency: Start date, Start time, Duration (HH:MM), Time zone | When | — | MISSING | GO-15 |
| 12 | Meeting location: Online / Offline / Hybrid `meetingType`, Location `locationId`, Meeting URL `meetingUrl` | Where | — | MISSING | GO-15 (location from `locations`) |
| 13 | Recurrence: End date, Repeat every, weekdays, "On day" / "On the …" | Repeating sessions | — | MISSING | GO-15 (`event_sessions` generated up to the end date) |
| 14 | Rewards: "Do you want to give badge to participants for this event?" + badge, badge message, minimum attendance days | Recognise attendees | Badges exist (HP-9c) | MISSING | GO-15 through `Recognitions::give` after attendance (system giver, D-GO4) |
| 15 | "Do you want the employees to receive skill endorsement for this event?" + skills, proficiency | Skills from attendance | Skills domain exists; no endorsements (MS-6) | MISSING | GO-15 after MS-6 endorsements |
| 16 | Pre-event feedback template, Post-event feedback template | Feedback before / after | Feedback templates and requests exist (P13); survey type "Event feedback" exists | MISSING | GO-15: new feedback processes `event_pre`, `event_post` (FeedbackRequests to attendees) |
| 17 | Attachments, "Add Video URL" | Material | — | MISSING | GO-15 |
| 18 | Applicability (organisation unit, bulk employees) | Invitees | Audiences exist | MISSING | GO-15 (reuse `Audiences`) |
| 19 | Review step | Check before publishing | — | MISSING | GO-15 |
| 20 | Invitation task (`app-events-detail`: name, type, sub-type, date, time, time zone, seats, mode, host, agenda, attachment, video URL, link, location, disclaimer; Acknowledge / "Read it"; "Would you like to nominate yourself for …?") | Employee responds | — | MISSING | GO-15 My tasks source `EventInvitationTaskSource` |
| 21 | Event feedback task (`app-events-feedback-details`: event name, host, location, date, rating, comments, thank-you note) | Feedback after the event | — | MISSING | GO-15 (feedback requests) |

### 27.8.4 Polls (`/app/processes/search/initiate-polls/search`, `initiatepoll_2376`; `app-initiate-poll-search` → `app-initiate-polls-card`, `app-polls-management` (Details · Applicability · Review), `app-polls-detail`, `app-polls-applicability`, `app-polls-review`; approval `app-polls-detail-approval`, `app-polls-card-approval`, `app-polls`; Search right "Polls approval: View")

The employee side (vote, results, export, unpublish) is matrix 22 §22.14.15 (MS-15: polls as survey kind `poll`). Rows
here are the HR register and the authoring and approval of polls.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of polls (raised by employees or HR) with workflow flyer | HR overview and approvals | — | MISSING | GO-16 (HR list of polls over the survey kind `poll`) |
| 2 | Filters "Poll Status", "Poll Type" | Narrow | — | MISSING | GO-16 |
| 3 | Poll details: Name of the poll `pollName`, Description, Type of poll `pollTypeId`; note "The poll will be displayed in the inbox." | Define the poll | A one-question survey can be authored by HR | PARTIAL | GO-16 = MS-15 poll kind |
| 4 | Question `pollQuestion` + options `optionText` (Add Option) | Choices | Survey question type single / multiple choice with options | PARTIAL | GO-16 |
| 5 | "Allow comments/suggestions" `isCommentAllow` | Free text | Survey free-text question / comments | PARTIAL | GO-16 |
| 6 | "Do not display responder details in the result" `isResponseAnonymousAllow` | Anonymous result | Survey anonymity modes | PARTIAL | GO-16 (same anonymity rules) |
| 7 | "Notify employees about poll" `isEmployeeNotificationAllow` | Tell the audience | Survey invitations | PARTIAL | GO-16 |
| 8 | "Poll expiry (days)" `pollExpiryDays` | Closes after N days | Survey `closes_at` | PARTIAL | GO-16 |
| 9 | "Allow users to downvote the poll" `isDownVoteAllow` (Upvote / Downvote) | Up / down votes | — | MISSING | GO-16 (optional vote kind) |
| 10 | Applicability: For specific employees / organisation-wise, bulk | Audience | Survey audience | PARTIAL | GO-16 |
| 11 | Review / Preview | Check | Survey questions view | PARTIAL | GO-16 |
| 12 | Polls approval (`app-polls-detail-approval`: poll preview, comments, View poll; `app-polls-card-approval`: Poll from, Created by, UNPUBLISHED) | Approve employee polls | Survey approval (`engagement.approve`, never one's own) for HR-made surveys; employees cannot raise polls | PARTIAL | GO-16: employee-raised polls (setting `engage.polls.employee_create`, default off) with approval (Approval Center source or workflow trigger `engagement.poll_submitted`) |
| 13 | Results (`app-polls-result-card`: Total attendees, Total Responses, Question, "Voted Successfully.", voting closed) | Outcome | Survey results with suppression | PARTIAL | Matrix 22 §22.14.15 (MS-15) |

### 27.8.5 Survey (`/app/processes/search/pulsesurvey/search`, `pulsesurvey_583`; `app-pulse-survey-search` → `app-pulse-survey-card`, `app-pulse-survey` (Details · Applicability · Advance applicability · Frequency · Review), `app-ps-details`, `app-ps-frequency`, `app-ps-preview`; inbox `app-survey-approval`, `app-survey-detail`, `app-pulse-rating-popup`; Search rights View / Update / Unpublish; action "Add survey")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of surveys and pulses | HR overview | Surveys (code, name, type, latest version, anonymity, closes) + versions | HAVE | — |
| 2 | Search "by name or code or designation or email" (creator) | Find by author | Search on survey code and name | PARTIAL | GO-14 author filter |
| 3 | Filters "Published status", "Frequency", "Survey type" | Narrow | Survey type filter | PARTIAL | GO-14 status and frequency filters |
| 4 | Card: "On :", "By :", Created by / on, Published on | Summary | Latest version, closes | PARTIAL | GO-14 columns (prepared by, published at) |
| 5 | Type: Pulse / Survey `surveyType` | Kind | Survey types incl. pulse | HAVE | — |
| 6 | Name `pulseSurveyPolicyName` | Title | Name | HAVE | — |
| 7 | Pulse: one question `question`; "Pulse templates", "Culture drivers" | Question library by culture driver | Questions typed per version; `analysis_tags` on questions; a question bank exists for feedback templates but surveys do not use it | PARTIAL | GO-14: "Add from question bank" + seeded culture-driver tags |
| 8 | Response scale 1–10 with end labels `fromWeightageRatingName`, `toWeightageRatingName` ("Ex. extremely unhappy / happy") | Labelled scale | Rating question with scale min / max; no end labels | PARTIAL | GO-14 end labels |
| 9 | "Benchmark score (1-10)" `benchmarkScore` | Target to compare with | — | MISSING | GO-14 tenant-entered benchmark per pulse question (the "tenant pulse benchmark" decision already open with matrix 21 AN-10) |
| 10 | Survey: "Add Question" / "Select template" `feedbackId` | Questions from a template | Questions repeater per version | PARTIAL | GO-14 "Copy questions from a feedback template or earlier survey" |
| 11 | Frequency "One time": Publish Date, "Due day" | Open and close | `opens_at` / `closes_at` | HAVE | — |
| 12 | Frequency "Event based": event, before / after, days | Survey N days around an employee event | — | MISSING | GO-14 (same trigger service as GO-12) |
| 13 | Frequency "Reoccurrence": recurring type, month, day of week / month | Repeating pulse | One response per period inside an open version (`response_rule = per_period`, week / month / quarter) | PARTIAL | GO-14 optional automatic new version per period |
| 14 | "Confidentiality message / Description" | Intro text | Version intro | HAVE | — |
| 15 | "Rating should be anonymously done" `isAnonymous` | Anonymity | Anonymous / confidential / identified with small-group suppression | HAVE | — |
| 16 | Applicability + Advance applicability | Audience and tenure window | Audience; no tenure-days attribute | PARTIAL | GO-12 tenure attribute |
| 17 | Preview (questions with stars, "You don't need to select any options below") | See what employees see | Questions view of a version | PARTIAL | GO-14 preview as the employee sees it |
| 18 | "Save as draft" / Save | Draft | Draft versions | HAVE | — |
| 19 | Unpublish, Edit | Withdraw / change | Close (reason), new version | HAVE | — |
| 20 | Survey approval (inbox `app-survey-approval`) | Approve before publishing | Submit → approve / return (`engagement.approve`) | HAVE | — |
| 21 | Survey response (`app-survey-detail`, `app-pulse-rating-popup`: "This survey is anonymous", comments, SUBMIT) | Answer | My tasks "Take survey" | HAVE | — |

### 27.8.6 Badges (`/app/processes/search/badges/search`, `badges_434`; `app-badges-points-search` → `app-badges`, `app-custom-badges`; APIs `engage/globalSearch/Badges`, `Engage/Badge/Setting/1`; import 100; Search right View)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | HR register of badges given | Ledger of recognition | Recognition list (HR in scope, private and withdrawn included) | HAVE | — |
| 2 | Search employee | Find | Search on the badge name only; receiver shown with code | PARTIAL | WO-2 |
| 3 | Filters "Period", "Date" | Time window | Period filter (from / until) | HAVE | — |
| 4 | "Filter by": Received by / Given by (a chosen person) | Badges of one receiver or giver | "Received by me" / "Given by me" (own only) | PARTIAL | GO-17: receiver and giver filters for HR |
| 5 | "Badges type" | System / custom | Badge filter | HAVE | — |
| 6 | "Status" | Active / withdrawn *(inferred)* | Withdrawn column, no filter | PARTIAL | GO-17 filter |
| 7 | Tabs Received / Given / Rewards ledger | Three views | Recognition and Reward points are separate lists | PARTIAL | GO-17 (HR version of the MS-15 page) |
| 8 | Badge card: Download Attachment, Download Certificate, Reward (points) | Details | Attachments, certificate (Letters), badge points | HAVE | — |
| 9 | "Internal Note" | HR-only remark on a badge | — | MISSING | GO-17: internal note visible to `recognition.manage` and the giver, never the receiver |
| 10 | Give a badge: badge, Give to, Message, Attachment, monthly quota ("No badges left this month") | Recognise | Give a badge (quota, rules, attachment) | HAVE | — |
| 11 | Give a badge: "Suggested messages" (message library) | Quick text | — | MISSING | GO-17: per-badge suggested messages (setup list) |
| 12 | Give a badge: "Do not post on social wall" | Keep it off the wall | Badge-level visibility (public / private); no per-award choice | PARTIAL | GO-17 per-award wall opt-out |
| 13 | IMPORTS (id 100, Badge allocation) | Bulk badges | — | MISSING | GO-11 |

### 27.8.7 Reward points (`/app/processes/search/points/search`, `rewardpoints_435`; `app-points`, `app-rewards-credit-ledger`, `app-transfer-reward-points`; API `engage/globalSearch/RewardPoints`; import 169; Search right View)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register: one card per employee with Received, Given, Last reward date (and the employee's organisation line) | Points per person | Ledger list per entry (date, employee, wallet, kind, points, counterpart, note, amount); HR sees the budget balance in the header | PARTIAL | GO-17: per-employee summary (received, given, redeemed, balance, last award date) |
| 2 | Search / filters (period, received by / given by) | Narrow | Wallet, kind, unpaid-redemption filters | PARTIAL | GO-17 employee and period filters |
| 3 | Rewards ledger: Received / Redeemed, Date, Reward points, Remarks, "Current balance :" | Movements | Ledger entries of the earned wallet with balances | HAVE | — |
| 4 | Wallet card: "HROne wallet points are the reward points received that still have not been redeemed or en-cashed", Claim, Comments, "Not transferred", On | Unredeemed points | Points received, redeem through payroll (`engage.points.redeem_modes`) | HAVE | — |
| 5 | Transfer reward points (HR): eligible employees (search, Organization, Location, Status, Type, bulk codes), available points, conversion rate, total amount requested, pending amount / employees, NEW TRANSFER, "Credits Being Transferred", "Insufficient available credits" | Pay many employees' points out at once | "Redeem for an employee" one at a time through payroll; vendor credits not offered | PARTIAL | GO-17: bulk "Redeem through payroll" for selected employees (each through `RewardPoints::redeem`); vendor wallet transfer stays external (D-MS6) |
| 6 | Give reward points: quota this month, Give to, Reward points, Message, Suggested messages, "Do not post on social wall" | Award points | Give points (balance, limits); no suggested messages, no per-award wall choice | PARTIAL | GO-17 |
| 7 | IMPORTS (id 169, Rewards) | Bulk awards | — | MISSING | GO-11 |
| 8 | Reward store (vouchers) | Marketplace | — | N-A (external voucher vendor, matrix 22 §22.14.4 row 7) | — |

### 27.8.8 Candidate engagement (`/app/processes/search/candidateengagement/search`, `candidateengagement_729`; `app-candidate-engagement` → `app-candidate-engagement-card`, `app-candidate-engage-flyer`; API `Engage/Candidate/EventType`: "Upcoming join created date", "Upcoming join expected date")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register (card Type, Subject, On; filters Status, Frequency, Created on, Published on; ⋮ View) | Candidate mailers | — | N-A (recruitment, separate RMS) | Pre-joining messages to upcoming joiners are matrix 23 §23.3.1 (WO-4) |
| 2 | Wizard Details · Frequency · Applicability · Advance Applicability · Review; Edit, Inactivate, Preview | Author a candidate mailer | — | N-A (recruitment, separate RMS) | — |
| 3 | Action "Candidate engagement" (Create) | Start one | — | N-A (recruitment, separate RMS) | — |

### 27.8.9 Fundraiser campaign (action "Initiate fundraise", `fundraisercampaign_767`; `app-fundraiser-campaign-search` → `app-create-fundraiser-campaign`; wall card `app-fcc-fundraiser`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Campaign name `campaignName`, Purpose `campaignPurpose` | Describe the cause | — (Gap Register EN-11) | MISSING | GO-18 |
| 2 | Start date `fromDate`, End date `toDate` | Window | — | MISSING | GO-18 |
| 3 | Target amount `targetAmount` | Goal | — | MISSING | GO-18 |
| 4 | Upload document | Supporting file | — | MISSING | GO-18 |
| 5 | "Do you want to map component?" `isMappedComponent` + pay component `payCode` + "Salary deduction month" | Collect pledges through payroll | Payroll deductions come only from structures, loans, adjustments and tagged payroll inputs | MISSING | GO-18: pledges paid by payroll deduction through a tagged `PayrollInputSource` (off by default, employee consent per pledge; D-GO3) |
| 6 | Social wall card: Collected, Target Amount, See more, "Donate now" | Promote and pledge | — | MISSING | GO-18 |

---

## 27.9 HR Desk actions — Action → Performance (14 rights) and Action → Engage [6] (`globalAction`)

Index only (not counted): each action is rated in the section that holds its dialog.

| HROne action (navigation code) | Rated in | Status there |
|---|---|---|
| Initiate goal cycle (`initiategoalcycle_987`) | §27.2.2 rows 1–5 | PARTIAL / MISSING (GO-2; HP-8i in progress) |
| Extend goal (`extendgoal_790`) | §27.2.2 rows 6–8 | MISSING (GO-2) |
| Change goal (`goaledit_852`) | §27.2.2 row 10 | HAVE |
| Delete goals (`deletegoal_2129`) | §27.2.2 row 9 | MISSING (HP-8k decision; GO-2 alternative) |
| Create company goal (`createcompanygoal_998`) | §27.2.3 rows 10–17 | HAVE / MISSING (GO-4) |
| Initiate review cycle (`initiatereviewcycle_988`) | §27.3.3 rows 1–3 | PARTIAL / MISSING (GO-2; HP-8i in progress) |
| Extend review (`extendreview_791`) | §27.3.3 row 4 | MISSING (GO-2) |
| Delete reviews (`deletereview_2130`) | §27.3.3 row 5 | MISSING (HP-8k decision) |
| Normalize review rating (`normalizereviewrating_1021`) | §27.3.4 row 2 | MISSING (HP-8i in progress) |
| Normalize performance rating (`normalizeperformancerating_1022`) | §27.3.4 rows 1, 3–5 | PARTIAL (GO-3) |
| Publish performance rating (`publishperformancerating_1023`) | §27.3.4 row 6 | HAVE |
| Initiate 360 feedback (`threesixtyfeedback_1057`) | §27.4.1 row 5 | PARTIAL (HP-8h in progress) |
| Extend 360 (`extendthreesixty_819`) | §27.4.1 row 6 | MISSING (HP-8h in progress) |
| Add PIP / "Pip" (`pip_955`) | §27.5.1 rows 7–13 | HAVE / MISSING (GO-8) |
| Add announcement (`announcement_188`) | §27.8.2 rows 4–18 | HAVE / PARTIAL / MISSING (GO-12) |
| Add HR handbook (`policy_189`) | §27.8.1 rows 6–14 | HAVE / PARTIAL / MISSING (GO-13) |
| Add survey (`pulsesurvey_583`) | §27.8.5 rows 5–17 | HAVE / PARTIAL / MISSING (GO-14) |
| Add event (`event_1343`) | §27.8.3 rows 6–19 | MISSING (GO-15) |
| Candidate engagement (`candidateengagement_729`) | §27.8.8 row 3 | N-A (recruitment) |
| Initiate fundraise (`fundraisercampaign_767`) | §27.8.9 | MISSING (GO-18) |

---

## 27.10 Imports — HR Desk → Imports → Performance [9] / Engage [3] and Team → Imports → Performance [6]

The import wizard, logs and approval are matrix 23 §23.10.0 (WO-17). PeopleOS has no performance or engage upload type
(`ImportTypes`, `RowImportTypes` cover workforce, time office and payroll only).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | OKR total achievement | Achievement values for many KPIs (check-ins) | Check-ins one goal at a time | MISSING | GO-10: row upload → `Goals::checkIn` per row (employee code, cycle, goal / key result, value, date, note) |
| 2 | OKR final achievement | Year-end achievement per goal (PF-10) | — | MISSING | GO-10 with GO-4's final achievement (override with reason) |
| 3 | Goals | Goal sheets in bulk | — | MISSING | GO-10: goals + key results through `Goals::create` (goal sheet policy rules apply; rows refused with reasons) |
| 4 | Normalize review ratings | Normalised periodic review ratings | — | MISSING | GO-10 after HP-8h/HP-8i in progress (HP-8i periodic reviews) |
| 5 | Normalize performance ratings | Normalised final ratings | Calibrate one appraisal at a time | MISSING | GO-10: `Appraisals::calibrate` per row with the upload's reason (`performance.calibrate`) |
| 6 | Initiatives | Initiatives in bulk | — | MISSING | GO-10 through `Initiatives::create` |
| 7 | Goal bank | Goal library | KRA library entered by hand | MISSING | GO-10: KRAs with suggested KPIs, perspective, unit, formula |
| 8 | Performance improvement plan | PIPs in bulk | — | MISSING | GO-10: plans opened as drafts through `ImprovementPlans::open` (approval as the PIP policy says) |
| 9 | Review ratings | Ratings for periodic reviews | — | MISSING | GO-10 after HP-8h/HP-8i in progress (HP-8i) |
| 10 | Engage: Badge allocation | Badges in bulk | — | MISSING | GO-11 through `Recognitions::give` (giver = uploader; quota rule per D-GO4) |
| 11 | Engage: Event attendance | Attendance for events | — | MISSING | GO-11 after GO-15 |
| 12 | Engage: Rewards | Reward points in bulk | Adjust / give one at a time | MISSING | GO-11 through `RewardPoints::adjust` (from the budget, reason) |
| 13 | Team → Imports → Performance [6] (OKR total / final achievement, Goals, Normalize review / performance ratings, Initiatives) | Managers upload for their team | Uploads are HR screens (matrix 20 §20.3.1 row 17: HR keeps uploads) | MISSING | GO-10 types usable by managers only if a `team.uploads` setting is switched on (default off, as matrix 24 D-TX9); rows outside the uploader's scope refused |

---

## 27.11 Inbox Insights → Performance [18] and Engage [8]

The Inbox Insights page itself is matrix 21 §21.3 (AN-30). Each row rates whether PeopleOS has the **task kind** AN-30
would count (as matrices 23 and 24): HAVE = the kind exists and reaches its decision maker; PARTIAL = only as a
notification, a Home card or on its own screen; MISSING = no such task.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Send 360 nomination | Employee / manager nominates reviewers | — | MISSING | HP-8h/HP-8i in progress (HP-8h) |
| 2 | Initiate review | Role starts a review period (manual initiation) | — | MISSING | HP-8h/HP-8i in progress (HP-8i, PF-7) |
| 3 | Nomination | Approve nominations | — | MISSING | HP-8h/HP-8i in progress (HP-8h) |
| 4 | Initiate goal | Role starts a goal cycle | — | MISSING | HP-8h/HP-8i in progress (HP-8i, PF-6) |
| 5 | Goal checkin | Employee check-in due | Check-in prompt notification (HP-8b) | PARTIAL | GO-9: prompts also as My tasks items |
| 6 | Goal checkin : company | Company goal check-in due | Prompt notification to goal creators | PARTIAL | GO-9 |
| 7 | Goal checkin : team | Team goal check-in due | Prompt notification | PARTIAL | GO-9 |
| 8 | Create goal | Employee must create goals | Notification `performance.goal_sheet.assigned`; goal-setting reminders | PARTIAL | GO-9 task kind "Create goal" (open goal-setting stage, sheet not submitted) |
| 9 | Goal approval | Approve a goal sheet | Approval Center `goal_plan` | HAVE | — |
| 10 | Performance review | Review to complete | Home "Reviews to complete" card, reminders `performance.reminder.review_due`; not a task kind | PARTIAL | GO-9 `PerformanceReviewTaskSource` (pending self / manager / approver / peer reviews of the current stage) |
| 11 | Performance letter | Letter to approve / acknowledge | Approval Center kind `letter` | HAVE | — |
| 12 | PIP | Approve a PIP | Approval Center `pip` | HAVE | — |
| 13 | PIP evaluation (menu 727) | Approve the outcome | Approval Center `pip_evaluation` | HAVE | — |
| 14 | PIP evaluation (menu 804) — evaluation due to the manager *(inferred)* | Evaluate at the end of the plan | Checkpoint reminders; no "evaluate now" task at the end date | PARTIAL | GO-9 task when an active plan reaches its end date without an outcome |
| 15 | Pip letter | Letter to approve | Approval Center `letter` | HAVE | — |
| 16 | Ack overall rating | Employee acknowledges the final rating | "Acknowledge" on the published appraisal; no task | PARTIAL | GO-9 task kind (published, not acknowledged) |
| 17 | Ack review rating | Employee acknowledges a periodic review rating | — | MISSING | HP-8h/HP-8i in progress (HP-8i `ack_review_rating`) |
| 18 | 1-on-1 acknowledgement | Participant confirms | My tasks `OneOnOneFeedbackTaskSource` (when switched on) | HAVE | — |
| 19 | Engage: Survey | Answer a survey | My tasks `SurveyTaskProvider` | HAVE | — |
| 20 | Engage: Events | Event invitation | — | MISSING | GO-15 |
| 21 | Engage: New announcement | Read / acknowledge | My tasks `CommunicationTaskSource` | HAVE | — |
| 22 | Engage: System notification | Informational inbox cards | Notification centre | HAVE | — |
| 23 | Engage: Pre-event feedback | Feedback before an event | — | MISSING | GO-15 |
| 24 | Engage: Post-event feedback | Feedback after an event | — | MISSING | GO-15 |
| 25 | Engage: Polls approval | Approve a poll | — | MISSING | GO-16 |
| 26 | Engage: Polls | Vote | — | MISSING | GO-16 (= MS-15) |

---

## 27.12 Analytics — HR Desk → Analytics → Performance [2], Team → Analytics → Performance [2] and Analytics → Dashboards (Performance, Performance 9 box, Engage, Survey, Happiness index, Pulse)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Review bell curve | Distribution of periodic review ratings | — | MISSING | HP-8h/HP-8i in progress (HP-8i), then the board per review period |
| 2 | Performance bell curve | Distribution of final ratings against targets | Calibration board: target vs actual per level, gaps, tolerance (HP-8g); template `rating_distribution` | HAVE | — |
| 3 | Team → Analytics → Performance (both bell curves for a manager's team) | Team distribution | Board needs `performance.calibrate`; analytics page `performance.analytics`; managers have the 9-box team view only | PARTIAL | GO-3: read-only team distribution (manager's reports, `performance.analytics_min_group` suppression) |
| 4 | Dashboards → Performance (`/app/analytics/performance`) | Performance dashboard | Matrix 21 §21.1.8 (AN-9; tiles for initiatives and one-on-ones now have data) | PARTIAL | AN-9 |
| 5 | Dashboards → Performance 9 box (`/app/analytics/ninebox`) | 9-box | `NineBoxGrid` (HP-8g): cycle picker, bands per axis, names or counts by access, "Not placed", "Initiative" from a person; matrix 21 §21.1.7 predates it | PARTIAL | Open: box rename / colour per tenant (names in config), download (AN-2), review-cycle filter (HP-8h/HP-8i in progress) |
| 6 | Dashboards → Engage | Badges and points dashboard | Matrix 21 §21.1.4 (AN-5) | PARTIAL | AN-5 |
| 7 | Dashboards → Survey | Survey results | Matrix 21 §21.1.10 (`SurveyResults`) | HAVE | — |
| 8 | Dashboards → Happiness index | Mood dashboard | Matrix 21 "Happiness index" (HP-9f page; AN-13) | PARTIAL | AN-13 |
| 9 | Dashboards → Pulse | Pulse overview | Matrix 21 §21.1.9 (AN-10) | PARTIAL | AN-10; benchmark = GO-14 row §27.8.5 #9 |

---

## 27.13 Reports — HR Desk → Reports → Performance [23] / Engage [12], the panels' "Reports" pages and Team → Reports → Performance [21] / Engage [8]

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | HR Desk → Reports → Performance [23] (1 on 1 details … Continuous feedback report) | Performance report catalogue | Rated report by report in matrix 21 §21.2.2 "Performance (24)": today 6 HAVE (goal details, PIP request, generated letters, review rating, summary, and Initiative details since HP-8j), 16 PARTIAL, 2 MISSING (360 nomination status — HP-8h in progress; digital signature status — SV-17) | PARTIAL | AN-27 datasets (matrix 21); no report is missing from matrix 21 |
| 2 | HR Desk → Reports → Engage [12] | Engage report catalogue | Matrix 21 §21.2.2 "Engage (12)": 3 HAVE, 6 PARTIAL, 3 MISSING (event attendance, event details, poll detail) | PARTIAL | AN-22; events GO-15; polls GO-16 |
| 3 | Performance panel "Reports" page (`menu_Performance__Reports`): tabs Favourite, All, Goal, Review, 360 feedback, PIP, History; per report name, ".xlsx", description, Download, Open | Report centre filtered to performance | Report library (category filter, run, CSV, schedules) | PARTIAL | Matrix 21 §21.2.1 (favourites, history, xlsx) |
| 4 | Engage panel "Reports" page (`menu_Engage__Reports`): tabs Favourite, All, Announcement, Rewards & Recognition, Survey, HR Handbook, Event, Poll detail, History | Report centre filtered to engage | Report library | PARTIAL | Matrix 21 §21.2.1 |
| 5 | Team → Reports → Performance [21] | Same reports for a manager's team | Managers hold no report permission | PARTIAL | Matrix 20 RQ-11 (`team_safe` templates) |
| 6 | Team → Reports → Engage [8] (badges ledger, reward points ledger, happiness index details, survey details, event details, event attendance, pre- / post-event feedback) | Same for a team | As row 5 | PARTIAL | RQ-11; event reports after GO-15; happiness and survey rows keep the small-group rules |

---

## 27.14 Team → People → Action → Performance (7 rights) / Engage (1 right) and Team profile → Performance (11 rights) / Engage (1 right)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Team action "Goal edit" | Manager changes a member's goals | Managers create and change their reports' goals; a mapped sheet is owned by the manager (owner decision 2026-10-29) | HAVE | — |
| 2 | Team action "Pip" | Manager opens a PIP | Managers open plans for their reports (`performance.team`), approval per PIP policy | HAVE | — |
| 3 | Team actions "Create virtual team" / "View virtual team" | Manager-made group across reporting lines (for shared goals / views) *(inferred)* | Teams are HR-maintained organisation teams | PARTIAL | GO-19: manager-defined virtual teams (members within the manager's scope) usable as a goal owner and a filter; setting `performance.team.virtual_teams` (default off) |
| 4 | Team action "Normalize review rating" | Manager normalises periodic review ratings | — | MISSING | HP-8h/HP-8i in progress (HP-8i), then GO-19 |
| 5 | Team action "Normalize performance rating" | Manager normalises final ratings | Calibration needs `performance.calibrate` (HR) | PARTIAL | GO-19: `performance.team.calibrate` (default off; reason required; the HR board shows manager changes) — D-GO2 |
| 6 | Team action "Publish performance rating" | Manager releases ratings | Needs `performance.calibrate` | PARTIAL | GO-19 (same setting) |
| 7 | Team action (Engage) "Team announcement" | Manager announces to the team | Matrix 22 §22.14.18 (MS-15) | PARTIAL | MS-15 |
| 8 | Team profile → Performance: View goals and initiatives, View performance, Create initiatives, Complete initiatives, Initiative, 1 on 1, 360 feedback, Training, Pip evaluation, Pip, Goal history | A member's performance tabs | Matrix 20 §20.3.2 rows 1, 2, 16, 17, 20, 31–33: goals, appraisals, PIPs, one-on-ones HAVE; initiatives now HAVE (HP-8j "My team" tab, managers assign and complete); 360 MISSING (HP-8h in progress); training = development needs / learning team view | PARTIAL | HP-8h/HP-8i in progress (HP-8h, 360 tab) |
| 9 | Team profile → Engage: View badges and rewards | A member's recognition | Recognition and reward points within the manager's scope (matrix 20 §20.3.2 row 7) | HAVE | — |

---

## 27.15 Smart search → Performance (2 rights)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "View top performers (last performance review)" | Ranked list from search | `performance` dataset (final rating); principle: no rankings | MISSING | Owner decision "ranked performer lists" already open with matrix 21 §21.1.8 rows 9–10 (reused, not re-raised); if accepted, a search answer over the AN-9 table |
| 2 | "View bottom performers (last performance review)" | Ranked list from search | As row 1; PIP candidates flow through the PIP policy | MISSING | As row 1 |

---

## 27.16 Home → Enterprise wall [3] (Engage: 8 rights; Knowledge base: 1; Performance: 3) and the wall cards of the engage features

The wall itself (feed, wall of fame, celebrations, mood, mission) is rated in matrix 20 §20.4.2; these rows rate the
grantable wall rights and the engage cards matrix 20 sent here.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Engage → "Badge" (give a badge from the wall) | Recognise from the wall | Give a badge on Recognition (Me); badges post to the wall | PARTIAL | GO-20: "Give a badge" / "Give points" buttons on the wall page and Home wall card |
| 2 | Engage → "Points" | Give points from the wall | Give points on Reward points (Me); posts to the wall | PARTIAL | GO-20 |
| 3 | Engage → "Hr post" | HR posts | Wall post (`wall.post`, attachments, pin) | HAVE | — |
| 4 | Engage → "Hide post/delete comment" | Moderate | Hide / restore posts and comments with a reason (`wall.moderate`); nothing deleted | HAVE | — |
| 5 | Engage → "Event" | Event posts and invitations on the wall | — | MISSING | GO-15 wall post type `event` |
| 6 | Engage → "Polls" | Polls on the wall | — | MISSING | GO-16 wall post type `poll` |
| 7 | Engage → "Survey" | Survey card on the wall | Surveys reach people in My tasks; no wall post | PARTIAL | GO-20: optional wall post when a survey opens (audience only) |
| 8 | Engage → "Announcement" | Announcements on the wall | Announcement wall posts to their recipients (HP-9e) | HAVE | — |
| 9 | Knowledge base → "Employee knowledge base" | Knowledge base from the wall | Knowledge base articles (`kb.view`, My HR → Policies) | HAVE | — |
| 10 | Performance → "1-on-1" | Schedule from the wall | Quick action "Schedule a one-on-one" | HAVE | — |
| 11 | Performance → "Create an initiative" | Create from the wall | "My initiatives" create; no wall / Home entry | PARTIAL | GO-20 quick action |
| 12 | Performance → "Continuous feedback" (`app-continuous-feedback`: employee, Appreciation / Critical, message, KPI) | Give feedback from the wall | Quick action "Give feedback" (praise / constructive, goal link) | HAVE | — |
| 13 | Wall card "Upcoming Events" (`app-sw-upcoming-events`) | Next events | — | MISSING | GO-15 Home card |

---

## 27.17 Status corrections for earlier matrices (not counted)

HP-8g (9-box, bell curve) and HP-8j (initiatives) landed after matrices 20–22 were written. Statuses there that are now
out of date (to update when those matrices are next touched):

| Matrix and row | Was | Now | Why |
|---|---|---|---|
| 20 §20.2.1 #25 "Create an initiative" from the inbox card | MISSING | PARTIAL → built (RQ-8) | Initiatives exist; launched from the unified inbox's "More" (RQ-8) |
| 20 §20.2.2 #61 `app-initiative-inbox` | MISSING | HAVE | Initiative feedback requests in My tasks; assignment notifications |
| 20 §20.3.2 #17 Goals and initiatives / #20 Initiative | PARTIAL / MISSING | HAVE / HAVE | Initiatives "My team" tab |
| 20 §20.4.2 #19 Initiatives on Home | MISSING | PARTIAL | My initiatives page; no Home card (GO-20) |
| 21 §21.1.7 9-box rows 1, 2, 4, 6, 8 | PARTIAL / MISSING | HAVE | `NineBoxGrid` (cycle, axes, counts and names by access, not placed) |
| 21 §21.1.7 row 5 (box name / colour) | MISSING | PARTIAL | Names from config, not editable per tenant |
| 21 §21.1.8 #8 Initiatives tile / #15 initiatives tab | MISSING | PARTIAL | Data and dataset exist; no tile or drill tab (AN-9) |
| 21 §21.2.2 Performance #11 Initiative details | MISSING | HAVE | Dataset `initiatives` + template "Initiative details" |
| 22 §22.8 #11 initiatives list / #12 initiative details | MISSING | HAVE / PARTIAL | My initiatives; comments missing (GO-8) |
| 22 §22.9 #5 Completed initiatives tab | MISSING | PARTIAL | Data exists; scorecard page is MS-13 |
| Gap Register PF-05 Initiatives / PF-08 9-box | MISSING / PARTIAL | built (HP-8j) / built (HP-8g) | — |

---

## Counts

Rows of the tables in §27.1–§27.8 and §27.10–§27.16 (the §0 building blocks, the §27.0 index, the §27.9 action index and
the §27.17 corrections are not counted), counted by script.

| Area | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---:|---:|---:|---:|---:|
| 27.1 Panels and shared register chrome | 14 | 3 | 9 | 1 | 1 |
| 27.2.1 Employee goals | 23 | 10 | 8 | 5 | 0 |
| 27.2.2 Goal actions (initiate, extend, delete, change) | 10 | 2 | 3 | 5 | 0 |
| 27.2.3 Company goal | 19 | 8 | 6 | 5 | 0 |
| 27.2.4 Goal requests | 4 | 0 | 0 | 4 | 0 |
| 27.3.1 Performance reviews | 14 | 4 | 6 | 4 | 0 |
| 27.3.2 Performance review requests | 4 | 0 | 2 | 2 | 0 |
| 27.3.3 Review actions (initiate, extend, delete) | 5 | 0 | 2 | 3 | 0 |
| 27.3.4 Normalize and publish | 7 | 3 | 3 | 1 | 0 |
| 27.4.1 360 Feedbacks | 7 | 0 | 3 | 4 | 0 |
| 27.4.2 360 Nominations | 5 | 0 | 0 | 5 | 0 |
| 27.4.3 Initiatives | 12 | 8 | 2 | 2 | 0 |
| 27.4.4 1-on-1 Meetings | 12 | 5 | 2 | 5 | 0 |
| 27.5.1 PIP | 14 | 7 | 2 | 5 | 0 |
| 27.5.2 PIP evaluations | 9 | 3 | 5 | 1 | 0 |
| 27.6 Performance / PIP / PIP evaluation letters | 9 | 2 | 4 | 3 | 0 |
| 27.7 Goal dashboards (HR, team) | 12 | 0 | 11 | 1 | 0 |
| 27.8.1 HR handbook | 18 | 10 | 3 | 4 | 1 |
| 27.8.2 Announcement | 21 | 8 | 7 | 5 | 1 |
| 27.8.3 Events | 21 | 0 | 0 | 21 | 0 |
| 27.8.4 Polls | 13 | 0 | 10 | 3 | 0 |
| 27.8.5 Survey | 21 | 10 | 9 | 2 | 0 |
| 27.8.6 Badges | 13 | 5 | 5 | 3 | 0 |
| 27.8.7 Reward points | 8 | 2 | 4 | 1 | 1 |
| 27.8.8 Candidate engagement | 3 | 0 | 0 | 0 | 3 |
| 27.8.9 Fundraiser campaign | 6 | 0 | 0 | 6 | 0 |
| 27.10 Imports (Performance [9], Engage [3], Team [6]) | 13 | 0 | 0 | 13 | 0 |
| 27.11 Inbox Insights (Performance [18], Engage [8]) | 26 | 9 | 7 | 10 | 0 |
| 27.12 Analytics and dashboards | 9 | 2 | 6 | 1 | 0 |
| 27.13 Reports (pointers) | 6 | 0 | 6 | 0 | 0 |
| 27.14 Team actions and team profile | 9 | 3 | 5 | 1 | 0 |
| 27.15 Smart search → Performance | 2 | 0 | 0 | 2 | 0 |
| 27.16 Enterprise wall rights and engage cards | 13 | 6 | 4 | 3 | 0 |
| **Total** | **382** | **110** | **134** | **131** | **7** |

By group: panel chrome (§27.1) 14 rows (3 / 9 / 1 / 1) · Goals (§27.2) 56 (20 / 17 / 19 / 0) · Reviews (§27.3) 30
(7 / 13 / 10 / 0) · Continuous feedback (§27.4) 36 (13 / 7 / 16 / 0) · Performance improvement (§27.5) 23 (10 / 7 / 6 / 0)
· Performance letters (§27.6) 9 (2 / 4 / 3 / 0) · Goal dashboards (§27.7) 12 (0 / 11 / 1 / 0) · Engage (§27.8) 124
(35 / 38 / 45 / 6) · HR Desk and Team nodes outside the panels (§27.10–§27.16) 78 (20 / 28 / 30 / 0). Performance as a
whole (§27.2–§27.7): 166 rows (52 / 59 / 55 / 0).

34 rows carry "HP-8h/HP-8i in progress" (1 HAVE, 12 PARTIAL, 21 MISSING): they are rated against today's tree and close
when the separate clone's 360 rounds / nominations (HP-8h) and performance policy with periodic goal and review cycles
(HP-8i) land.

---

## Biggest gaps

1. **No engage events** (§27.8.3 all 21 rows; §27.11 #20, #23, #24; §27.16 #5, #13; §27.10 #11; event reports in §27.13):
   no event record, host, seats, nomination, attendance, recurrence, online / offline location, pre- / post-event
   feedback, attendance badges, invitations or "Upcoming events" card.
2. **No standalone 360 rounds or nominations** (§27.4.1, §27.4.2, §27.11 #1, #3, §27.14 #8): 360 is extra reviewers inside
   one appraisal; no rounds per policy and period, nomination of peers / subordinates / clients, nomination approval,
   decline, extension or register — **HP-8h in progress**.
3. **One appraisal per cycle, no periodic goal / review cycles** (§27.2.2 #1–2, §27.3.1 #1, #3, §27.3.3 #1–2, §27.3.4 #2,
   §27.12 #1, §27.11 #2, #4, #17, review recommendations §27.3.1 #9, performance letters §27.6 #3): no review periods,
   review-rating normalisation, review bell curve, manual initiation tasks, review-rating acknowledgement —
   **HP-8i in progress**.
4. **A running cycle cannot be changed for some people** (§27.2.2 #3–9, §27.3.3 #3–5, §27.4.1 #6): no adding joiners or
   chosen employees after launch, no extension of the goal / review / 360 windows (stage dates are frozen), no withdrawal
   (HROne deletes; PeopleOS's answer waits on the HP-8k decision or GO-2's "withdraw").
5. **No performance or engage uploads** (§27.10, 13 rows; import buttons in §27.1 #7): goals, goal bank, achievements, final
   achievement, initiatives, PIPs, normalised ratings, badges, rewards, event attendance.
6. **Thin performance registers** (§27.2.1 #1, #3, #5, #7; §27.2.4; §27.3.1 #5, #7; §27.3.2; §27.5.1 #3, #5–6; §27.5.2
   #1–3; §27.4.4 #2): no goal-sheet register or goal-request history, no review-request list or HR reopen, no PIP date
   filters / approval columns, no one-on-one filters.
7. **OKR depth on company goals and no goal dashboard** (§27.2.3 #3–9, #13–15; §27.7): no owner person, health status
   (on track / behind / at risk), private visibility, tags, linked-people table or actual vs system achievement; no
   HR / team goal dashboard with KPI drawer and "share on the wall".
8. **Announcements and surveys are one-off** (§27.8.2 #5–7, #9–10, #13–14, #21; §27.8.5 #7–10, #12–13): no event-based or
   recurring sending, templates, clone, per-announcement channels, personalisation, CC, video link, per-person read
   list, question library, labelled scale or benchmark.
9. **No polls** (§27.8.4, §27.11 #25–26, §27.16 #6): only a one-question survey can stand in; no HR poll register,
   employee-raised polls with approval, up / down votes or wall posts (with MS-15).
10. **Normalisation is one appraisal at a time and HR-only** (§27.3.4 #3–4, §27.12 #3, §27.14 #4–6): no grid of system vs
    normalised ratings for a chosen population, no team distribution or manager calibration / publishing, no virtual
    teams.

Also notable: performance task kinds for HR's inbox overview (GO-9: reviews, goal creation, check-ins, rating
acknowledgement, PIP evaluation due), one-on-one meeting details and minutes (GO-7), PIP links to initiatives, meetings and
attachments (GO-8), handbook sections / attachments / request-form link / ordering (GO-13), recognition desk details
(internal note, suggested messages, wall opt-out, per-employee points summary, bulk payroll redemption; GO-17),
fundraisers (GO-18). Blocked or external: WhatsApp delivery (IP-07), vendor vouchers and HROne credit transfer
(D-MS6), e-signature status (SV-17), ranked performer lists (matrix 21 decision).

---

## Owner decisions needed

Reused, not re-raised: **peer visibility** (who sees 360 / peer feedback; HP-8h, §27.4.1 #4), **one-on-one host** (GO-7),
**HP-8k delete transaction** (§27.2.2 #9, §27.3.3 #5), matrix 21's **ranked performer lists** (§27.15) and **tenant pulse
benchmark** (§27.8.5 #9), matrix 22 **D-MS6** voucher vendor (§27.8.7 #5, #8), matrix 23 **D-WO5** letter revoke (§27.6
#9), matrix 24 **D-TX9** manager uploads (§27.10 #13).

| # | Decision | Proposed default (reproduces today's behaviour until chosen) |
|---|---|---|
| D-GO1 | HROne "Review Reinitiate": may HR reopen a submitted review? | Off (`performance.review.hr_reopen` = false). When on: `performance.manage`, appraisal not finalized, reason required; the earlier submission stays in the audit trail and the reviewer is told; HR never writes a review on someone's behalf |
| D-GO2 | Managers normalising and publishing their team's ratings (Team actions) | Off (`performance.team.calibrate` = false). When on: only appraisals of their own reports, reason required, HR's board shows manager changes, the distribution check (HP-8g) still applies at close |
| D-GO3 | Fundraiser pledges deducted through payroll | Not built until asked. If built: voluntary pledges with the employee's consent per pledge, deduction through a tagged payroll input, off by default, never touching a finalized run |
| D-GO4 | Badges given by the organisation (event attendance) and bulk badge uploads: do they use the giver's monthly quota? | Allowed for `recognition.manage`; recorded with their source (event / upload); receiver limits still apply; no giver quota consumed |
| D-GO5 | Private organisation goals: who sees them | Owner, creator, the visible-to list and HR in scope; owners of aligned goals see only the parent's title; default visibility stays public (today) |
| D-GO6 | AI help (goal drafting, agenda, review comments) | Off; when on, only through the existing AI controls (`ai.use`) and only the user's own drafting text is sent — never ratings, other people's goals or personal data |
| D-GO7 | "Actual achievement" overriding the system-calculated achievement | Off (`performance.goals.achievement_override` = false). When on: the goal owner's manager or HR, with a reason; the system value is kept beside it and shown in the dashboard; ratings use the override only while the setting is on |
| D-GO8 | Employees raising polls (HROne "Polls approval") | Off (`engage.polls.employee_create` = false); when on, every employee poll needs approval before it reaches anyone |

---

## Build packages (ordered)

Prefix **GO-**. Every package is additive and keeps today's defaults; bulk actions call the existing services per record
(authorised and audited one by one, result summary); new settings in `config/peopleos.php` → `settings` with rows in
`config/peopleos_setup.php` (Performance / Engage), new permissions in `config/peopleos.php` then
`php artisan peopleos:sync-permissions`; new tables are tenant-owned (`BelongsToTenant` + `Auditable`), classified in
`DataInventory`, guarded by policies wired in `ParityServiceProvider`; each package closes with Pest coverage in
`tests/Feature/HroneParity/` and an update of `docs/architecture/hrone-parity.md` and the Gap Register. Packages that
depend on HP-8h / HP-8i build on top of the clone's work once it lands.

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| 1 | **GO-1 Performance desk and register depth** — navigation cluster in HROne's order (goal sheets, goal requests, reviews, review requests, 360 after HP-8h, initiatives, one-on-ones, PIP, PIP evaluations, performance letters as Letters presets); "Goal sheets" view (employee × cycle, sheet status incl. not created, approval link, competencies with weights); "Goal requests" list over `goal_plans`; "Review requests" list over `appraisal_reviews` within `AppraisalVisibility` with bulk "Remind reviewers"; HR "Reopen review" (D-GO1); appraisal created-date filter; PIP request / approval / end-date filters, requested / approved by-and-at columns, "PIP evaluations" preset; employee drawer with goals and initiatives of a cycle | §27.1 #1, 2, 8, 9; §27.2.1 #1, 3, 7, 10; §27.2.4 #1–3; §27.3.1 #5, 7; §27.3.2 #1–3; §27.4.3 #2; §27.5.1 #3, 5, 6; §27.5.2 #1–3 (and the register for §27.4.1 #1 after HP-8h) | navigation cluster class, `app/Filament/Pages/GoalSheets.php` (new), `app/Filament/Resources/GoalPlans/*` and `AppraisalReviews/*` (new, read-only), `AppraisalResource`, `ImprovementPlanResource`, `InitiativeResource`, `Appraisals` (`reopenReview`), `config/peopleos.php`, `config/peopleos_setup.php` | WO-1, WO-2 (matrix 23); D-GO1 for reopen | M |
| 2 | **GO-2 Cycle population, windows and withdrawal** — "Add employees to cycle" (named, organisation unit, pasted codes: appraisal, current-stage reviews, mapped goal sheet); window extensions per stage for everyone or chosen people (`performance_window_extensions`, reason, audited) read by goal-period checks, reminders and due labels (shared with HP-8h's 360 window); "Withdraw from cycle" (non-finalized appraisal, open goals and pending plan cancelled with a reason; nothing deleted) as the safe alternative to HROne's deletes | §27.1 #6; §27.2.2 #3, 4, 6–9; §27.2.4 #4; §27.3.3 #3–5; §27.4.1 #6 | migration + `app/Domain/Performance/Models/PerformanceWindowExtension.php`, `app/Domain/Performance/Services/PerformanceWindows.php` (new), `Appraisals` (`addEmployees`, `withdraw`), `Goals::assertGoalPeriodOpen`, `PerformanceReminders`, `NeedsAttention`, `PerformanceCycleResource` actions, `config/peopleos.php` (appraisal status `withdrawn`), `DataInventory` | WO-2; HP-8k decision only if real deletion is wanted | M |
| 3 | **GO-9 Performance task kinds** — My tasks sources for pending reviews of the current stage, goal sheet to create, check-in prompts (company / team / employee), published rating to acknowledge, PIP evaluation due at plan end; counted by AN-30 | §27.2.1 #21; §27.11 #5–8, 10, 14, 16 | `app/Domain/Performance/Support/{PerformanceReviewTaskSource,GoalSheetTaskSource,CheckInPromptTaskSource,AppraisalAcknowledgementTaskSource,PipEvaluationDueTaskSource}.php` (new), `config/peopleos.php` (`experience.task_sources`) | — (AN-30 reads them) | M |
| 4 | **GO-4 Company goals and OKR depth** — owner person on organisation goals, health status on goals and check-ins (not started / on track / behind / at risk), check-in period, visibility public / private + visible-to (D-GO5), tags, linked-goals relation (children, owner, KPI, progress, unlinked on), actual vs system achievement and final achievement (D-GO7); filters and dataset columns | §27.2.1 #12; §27.2.3 #3–9, 13–15; §27.7 #4, 8, 10; §27.10 #2 (with GO-10) | migration on `goals` / `goal_check_ins`, `Goal`, `GoalCheckIn`, `Goals`, `GoalResource` (+ linked-goals relation manager), goal visibility scope / policy, `PerformanceActions::forGoal`, `GoalsDataset`, `config/peopleos.php`, `config/peopleos_setup.php` | D-GO5, D-GO7; MS-14 shares the linked-goals view | M |
| 5 | **GO-6 Goal dashboard** — HR and team page per cycle: process, progress band, health, started / not started and people filters; goal cards; KPI drawer (achieved, KPIs mapped, linked, check-ins, activity); "Share on the wall" for completed goals (own message, optional image) | §27.2.3 #8; §27.3.1 #10; §27.7 #1–9, 11, 12 | `app/Filament/Pages/GoalDashboard.php` + view (new), `PerformanceRelationships::teamOf`, `SocialWall` (goal post with message and image), `.pos-*` panel classes | GO-4; RQ-11 (team scope, matrix 20) | M |
| 6 | **GO-5 Goal sheet tools** — sheet versions view (goal plans in order with diffs), goal attachments, per-sheet feedback template, "Assign last approved", "Copy goals of team member", optional AI drafting (D-GO6) | §27.2.1 #5, 11, 13, 16, 19, 20; §27.2.4 #3; §27.3.1 #11 | `Goals` (`copyFromCycle`, `copyFromColleague`), migration (`goals.attachments`, `goal_plans.feedback_template_id`), `ParityFileController` (type `goal-attachment`), `GoalResource` / `ListGoals` header actions, `app/Domain/Ai` (optional) | GO-1; D-GO6 for AI | M |
| 7 | **GO-3 Bulk normalisation and team distribution** — normalise grid on the calibration board (system vs normalised rating per row, one reason per batch, each row through `Appraisals::calibrate`), population by organisation filters / pasted codes; read-only team distribution for managers (small groups suppressed); review-rating normalisation per period after HP-8i | §27.1 #6; §27.3.4 #2–4; §27.12 #3 | `CalibrationBoard` (+ view), `PerformanceDistribution::compare` (team scope), `PerformanceActions` | WO-2; HP-8h/HP-8i in progress (review ratings) | M |
| 8 | **GO-7 One-on-one depth** (with MS-13) — end time, time zone, meeting type, room / URL, structured minutes (progress, plans, challenges, other), attachments, "previous 1-on-1" link, series (weekly / monthly ≤ 3 months), register filters (initiated by, host, participant, status, dates); .ics on the invitation | §27.4.4 #2, 4–8, 11; §27.5.2 #7 (with GO-8) | migration on `one_on_ones` + `one_on_one_series`, `OneOnOne`, `OneOnOnes::schedule`, `OneOnOneResource`, notification template | Open decision "one-on-one host"; MS-13 (matrix 22) is the same work | M |
| 9 | **GO-8 PIP and initiative links** — initiatives and one-on-ones linked to a PIP ("Add initiative", "Schedule 1-on-1" on the plan), PIP attachments, evaluation view with the last 1-on-1 and the plan's initiatives, initiative comments (append-only), "related employee" for buddy / induction initiatives | §27.4.3 #6, 10; §27.5.1 #10–12; §27.5.2 #5–7 | migrations (`initiatives.improvement_plan_id`, `initiatives.related_employee_id`, `one_on_ones.improvement_plan_id`, `improvement_plans.attachments`, `initiative_comments`), `ImprovementPlans`, `Initiatives`, `ImprovementPlanResource`, `InitiativeResource`, `DataInventory` | GO-7 (last 1-on-1) | S |
| 10 | **GO-10 Performance uploads** — row uploads on the staged pipeline (module `performance`): goals with key results, goal library, KPI achievements (OKR total), final achievement (OKR final), initiatives, PIPs (drafts), normalised performance ratings; review ratings / normalised review ratings after HP-8i; optional team use behind `team.uploads` (off) | §27.1 #7; §27.2.1 #23; §27.2.3 #18; §27.3.1 #13; §27.4.3 #11; §27.10 #1–9, 13 | `app/Domain/Performance/Imports/*Imports.php` (new), `RowImportTypes`, `ImportTypes::MODULES`, format guides, `RecentUploads` | WO-17 (matrix 23); GO-4 (final achievement); HP-8h/HP-8i in progress (review ratings) | L |
| 11 | **GO-12 Announcement depth and personal-date triggers** — clone, templates, `{name}` personalisation, per-announcement channels (in-app always, e-mail optional), internal CC, video link (allow-listed hosts), repeat (= MS-15) and event-based sending (joining date, confirmation, shared birthday, work anniversary; daily run, once per person), created / published date filters, "Who read" + CSV, "days since joining" rule attribute for audiences (also used by handbook and surveys) | §27.8.1 #14; §27.8.2 #2, 3, 5–7, 9, 10, 13, 14, 17, 21; §27.8.5 #12, 16 | migration on `announcements`, `Communications`, `CommunicationDelivery`, `AnnouncementResource`, new command `peopleos:communication:triggers` (scheduled, `TenantRunner`, idempotent), rule context attribute, AN-22 dataset `announcement_reads` | MS-15 (repeat); WhatsApp stays IP-07 | L |
| 12 | **GO-13 HR handbook depth** — clone, employee order, sections (name + content, table of contents), attachments, https link, link to request types ("Read the policy" on leave / regularisation / expense / travel / loan forms) | §27.8.1 #4, 5, 9–12 | migration on `articles`, `KnowledgeBase`, `ArticleResource` (+ reorder page), request forms (read-only link), `ParityFileController` (type `article-attachment`) | — | M |
| 13 | **GO-14 Survey depth** — author / status / frequency filters and columns, question bank and culture-driver tags, end labels on rating scales, tenant-entered benchmark, copy questions from a template or earlier survey, event-based and recurring runs (new version per period), employee-view preview | §27.8.5 #2–4, 7–10, 12, 13, 17; §27.12 #9 | `Surveys`, `SurveyQuestion` (labels, benchmark), `SurveyResource` (+ Versions), `QuestionBank` reuse, trigger service from GO-12 | GO-12 (triggers); tenant pulse benchmark decision (matrix 21) | M |
| 14 | **GO-16 Polls** (with MS-15) — survey kind `poll`, HR poll register with status / type filters, employee-raised polls with approval (D-GO8), up / down votes, notify toggle, expiry, anonymous results, wall post type `poll`, My tasks voting | §27.1 #2; §27.8.4 #1–12; §27.11 #25, 26; §27.13 #2; §27.16 #6 | `Surveys` (kind `poll`), poll resource / preset, Approval Center source or workflow trigger `engagement.poll_submitted`, `WallPost::TYPES`, `config/peopleos.php` | MS-15 (do once); D-GO8 | M |
| 15 | **GO-17 Recognition desk depth** — receiver / giver / status filters and Received · Given · Ledger tabs for HR, internal note on badges, suggested messages per badge, per-award "do not post on the wall" for badges and points, per-employee points summary, bulk "Redeem through payroll" | §27.8.6 #4, 6, 7, 9, 11, 12; §27.8.7 #1, 2, 5, 6 | migration (`recognitions.internal_note`, `post_to_wall`), `Recognitions`, `RewardPoints`, `RecognitionResource`, `RewardPointEntryResource`, `app/Filament/Pages/RewardPointBalances.php` (new), `SocialWall` listeners | MS-15 (shared page) | S |
| 16 | **GO-11 Engage uploads** — badge allocation (through `Recognitions::give`, D-GO4), reward points (through `RewardPoints::adjust` from the budget), event attendance after GO-15 | §27.1 #7; §27.8.6 #13; §27.8.7 #7; §27.10 #10–12 | `app/Domain/Recognition/Imports/*` (new), `RowImportTypes`, `ImportTypes::MODULES` (`engage`) | GO-10 (module wiring), GO-15 (attendance); D-GO4 | S |
| 17 | **GO-15 Events** — events with type / sub-type option lists, host, mandatory flag, seats, start / duration / time zone, online / offline / hybrid with location or URL, recurring sessions, audience, attachments and video link, review and publish, invitations with nominate / acknowledge (My tasks), attendance per session and CSV, cancel with reason (attendees told), attendance badges (D-GO4), skill endorsements after MS-6, pre- / post-event feedback through feedback requests, wall post and Home "Upcoming events", datasets for the event reports | §27.1 #2; §27.8.3 #1–21; §27.10 #11 (with GO-11); §27.11 #20, 23, 24; §27.13 #2, 6; §27.16 #5, 13 | new `app/Domain/Engagement/Models/{EngageEvent,EventSession,EventParticipant}.php` + migrations, `EngageEvents` service, `EngageEventResource` (form wizard), `EventInvitationTaskSource`, feedback processes `event_pre` / `event_post` in `config/peopleos.php`, option lists, `WallPost::TYPES`, Home card, datasets `event_participants`, permission `engagement.events` (sync), `DataInventory` | Audiences, FeedbackTemplates (P13), Recognition (HP-9c); MS-6 for endorsements | L |
| 18 | **GO-19 Manager virtual teams and calibration rights** — manager-defined virtual teams (members in the manager's scope) usable as goal owner and filter (`performance.team.virtual_teams`, off); manager normalise / publish for their reports (`performance.team.calibrate`, off, D-GO2); review-rating normalisation after HP-8i | §27.14 #3–6 | migration `virtual_teams` + members, `Appraisals::calibrate` / `publish` authorisation, `CalibrationBoard` team mode, `config/peopleos.php`, `config/peopleos_setup.php` | GO-3; RQ-11 (matrix 20); D-GO2; HP-8h/HP-8i in progress | M |
| 19 | **GO-20 Enterprise wall actions** — "Give a badge", "Give points", "Create an initiative" on the wall page and Home wall card; optional wall post when a survey opens (`engage.wall.survey_posts`, off) | §27.16 #1, 2, 7, 11 | `SocialWall` page, Home wall card view, `RecognitionActions`, `RewardPointActions`, `InitiativeResource` create action, `config/peopleos_setup.php` | — | S |
| 20 | **GO-18 Fundraiser campaigns** — campaigns (name, purpose, dates, target, document), pledges, optional payroll deduction through a tagged payroll input (off; consent per pledge), wall card with collected vs target and "Donate now" | §27.8.9 #1–6 | new `app/Domain/Engagement/Models/{FundraiserCampaign,FundraiserPledge}.php` + migrations, `Fundraisers` service, resource, payroll input `FundraiserDeductionInput` (tag `peopleos.payroll_inputs`), wall card, `DataInventory` | D-GO3; lowest priority (Gap Register EN-11) | M |

Not packaged here (owned elsewhere): 360 rounds and nominations (**HP-8h, in progress**), performance policy with
periodic goal / review cycles, review recommendations and the performance letter at publication (**HP-8i, in progress**,
SV-11 / SV-12 designs), deletes (**HP-8k**, owner decision), register chrome and letter registers (WO-1, WO-2, WO-13),
performance / engage datasets and dashboards (AN-5, AN-9, AN-10, AN-13, AN-22, AN-27, AN-30), team pages and reports
(RQ-8, RQ-11), self-service scorecard, goals, polls and team announcements (MS-13, MS-14, MS-15), WhatsApp (IP-07),
voucher vendor (D-MS6), e-signature (SV-17).
