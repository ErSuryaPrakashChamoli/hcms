# HROne vs PeopleOS — 21 Analytics · Reports · Projects and timesheets (field by field)

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Recruitment is out of scope (separate RMS):
such rows are N-A. Build package prefixes: `AN-` (analytics and reports), `PM-` (projects and timesheets).

**Built (2026-10-30).** AN-26 (datasets `loans`, `loan_installments`, `reimbursement_ledger`, `reimbursement_claims`,
`variable_pay_plans`, `variable_pay_requests` + 11 templates) and AN-27 (datasets `one_on_ones`, `continuous_feedback`,
`feedback_360_status`, `feedback_360_results` (aggregates only), `check_ins`, `performance_check_ins`, `goal_plans`,
`pip_checkpoints`, `appraisal_reviews`, `appraisal_ratings`, `development_needs` + 17 templates): the rows they close are
marked "→ built" with the package (21.2.2 Performance #2, 360 nomination status, is closed too: HP-8h's nomination sets
exist now); see `docs/architecture/hrone-parity.md` "AN-26 / AN-27". CA-10 (CXO views, 2026-10-30) closed the §21.1.1 view
rows and the view editors of §21.1.0 #1, 21.1.3, 21.1.4, 21.1.6, the payroll and HR Ops views and the dashboard share row
(marked "→ built (CA-10 …)"; `docs/architecture/hrone-parity.md` "CA-10 Shared CXO views"). The counts below are the
analysis-time counts.

**Inputs.**

- `hrone-research/routes.json`: the 11 `/app/analytics/*` routes (return on investment, employee movement, helpdesk,
  engage, performance, nine box, pulse, survey, expense, CXO time office, recruitment), `/app/processes/reports`
  (+ `/search`), `/app/team/team-reports` (+ `/search`), `/app/processes/insights`, the 13 `/app/project-management/*`
  routes and `/app/core/projects/project-template`, `/app/core/project-management/project-template`.
- `hrone-research/pages.jsonl` (latest line per id): `route_app_processes_insights` (+ 3 tabs),
  `route_app_core_accessrights_analyticsaccess`, `route_app_processes_reports` (+ `/search`, tabs All / Recent /
  Favorite Reports), `route_app_team_team_reports` (+ `/search`), the 11 `route_app_analytics_*` pages,
  `route_app_project_management*` (+ tabs My Tasks, Invoice, Timesheet, Analytics), and the project-template routes
  (`route_app_core_projects_project_template`, `route_app_core_project_management_project_template`,
  `route_app_processes_search_project_management_project_template`), which landed on Home (tabs Vision / Mission /
  Purpose are the Home mission card), so the Setup project rows come from components.
- `hrone-research/components.json` (dialogs, flyouts and dashboards the crawl never opened): the dashboard components
  `app-cxo-timeoffice`, `app-employee-movement`, `app-engage-analytics`, `app-expense-analytics`,
  `app-helpdesk-analytics`, `app-nine-box`, `app-performance` (+ `app-performance-table`,
  `app-performance-analytics-view`, `app-performance-analytics-detail`), `app-pulse`, `app-survey`,
  `app-return-on-investment`, `app-payroll-analytics`, `app-happiness-index`, `app-employee-efficiency` (HR ops),
  `app-analytic-dashboard-home` (custom dashboards and widgets), the view editors (`app-*-view`, `app-add-*-view`,
  `app-analytics-access-setting`, `app-analytics-access-multiple`), the shared filter (`app-filters`,
  `app-ou-applicability`) and drill-down actions; the report centre (`app-reports-home`, `app-reports-summary-home(-new)`,
  `app-reports-grid`, `app-create-reports`, `app-report-details`, `app-saved-reports`, `app-recent-downloads`,
  `app-add-custom-report`, `app-report-format`, `app-password-protected-reports`, `app-global-search-*-report*`,
  filter widgets `app-form-to-date`, `app-from-to-month-year`, `app-report-periods`, `app-report-drop-downs`,
  `app-multi-select`, `app-radio-buttons`, `app-report-auto-complete`); inbox insights (`app-insights-home`,
  `app-insight-table`, `app-insight-error`, `app-view-canvas`, `app-widgets-layout`); projects (`app-my-projects`,
  `app-project-card`, `app-project-list-view`, `app-project-filter`, `app-project-health-status-flyer`,
  `app-project-management-header`, `app-project-details-header`, `app-project-details`, `app-project-detail`,
  `app-task-details`, `app-milestone*`, `app-team-grid-view`, `app-team-member-form`, `app-task-alerts`, `app-invoice*`,
  `app-generate-invoice`, `app-generated-invoice-list`, `app-invoice-summary`, `app-timesheet*`, `app-project-form` and
  its steps, `app-project-template`, `app-project-stages`, `app-project-tasks`, `app-project-fields-setting`,
  `app-task-fields-setting`, `app-project-status`, `app-project-health-dimension`, `app-task-type`,
  `app-project-milestone`, `app-invoice-dynamic-fields`, `app-timesheet-policy`, `app-reminders`, `app-customer-layout`,
  `app-customer-form`, `app-billing-details`).
- `docs/hrone-parity/hrone-feature-inventory.md` §1 (rail: HR Desk → Reports, Inbox Insights, HR Analytics; Analytics
  dashboards list) and §7 (the report catalogue: 230 reports in 11 modules).
- PeopleOS, checked in code: `app/Domain/Analytics/Services/{DatasetRegistry,ReportLibrary,ReportRunner,ReportExports,
  ReportSchedules,Dashboards,WorkforceMetrics,AnalyticsDefaults,PlatformAnalytics}.php`, the 60 dataset classes (191 library templates) in
  `app/Domain/Analytics/Datasets/`, `app/Filament/Pages/{DashboardViewer,ReportLibraryPage,WorkforceCommandCentre,
  PeopleAnalyticsPage,PerformanceAnalyticsPage,TalentAnalyticsPage,CompensationAnalyticsPage,ServiceDeskAnalyticsPage,
  WorkforceAnalyticsPage,HappinessIndex,SurveyResults,ExitInsights,ComplianceReportsPage}.php`,
  `app/Filament/Resources/{Reports,Dashboards}`, `app/Domain/{Performance,Talent,ServiceDesk}/Services/*Analytics.php`,
  `config/peopleos.php` (analytics, talent, engagement survey types, permissions, role templates),
  `docs/architecture/phase-14-analytics.md`, `docs/architecture/hrone-parity.md` (EX-G expense dashboard, HP-9 ledgers),
  `docs/hrone-parity/setup/{00-build-status,01-core-hr-access-integration,12-performance-engage}.md`,
  `docs/hrone-parity/HROne-Gap-Register.md` (HP-5, HP-11) and `docs/hrone-parity/modules/20-requests-inbox-team.md`
  (team reports and the inbox "Insight" panel are rated there, not repeated here).

**Progress.**

- 21.1 Analytics dashboards — written from components. The re-crawl (after the 15:00 reboot) then captured all 11
  `/app/analytics/*` routes: ROI rendered and is folded into §21.1.11; Engage shows "NO DATA AVAILABLE"; CXO time
  office, Employee movement, Expense, Helpdesk, Nine box, Performance, Pulse, Survey and Recruitment show "YOU DO NOT
  HAVE ACCESS" for the crawl user, so the components stay the source for those. Their only live evidence is the view
  API each loads first (`/api/timeoffice/view/Dropdown`, `/api/expense/view/Dropdown`, `/api/engage/view/Pulse/Dropdown`,
  `/api/engage/view/Survey/Dropdown`, `/api/pms/dashboard/View/List`, `/api/pms/ninebox/view/List`,
  `/api/helpdesk/dashboard/Views`, `/api/engage/dashboard/DashboardViewList`), which confirms every dashboard opens on
  its CXO view list (§21.1.0 #1, §21.1.1). Rows marked *(inferred)* are the least certain.
- The report centre pages (`route_app_processes_reports`, `/search`, `route_app_team_team_reports`, `/search`, each
  with tabs All reports / Recent Reports / Favorite Reports) were captured live and folded into §21.2.1.
- 21.2 Reports — the report centre (screens, builder, saved views, schedules) and all 230 catalogue reports mapped to
  the report library.
- 21.3 Inbox insights (`/app/processes/insights`) — from the captured page and components.
- 21.4 Projects and timesheets — every field of every project component; the `/app/project-management*` workspace
  (My Projects, My Tasks, Invoice, Timesheet, Analytics tabs) was captured live late in the run and folded into
  §21.4.5, 21.4.8 #2, 21.4.9 #2 and 21.4.10 #2. The Setup project-template routes landed on Home. PeopleOS has no
  projects module (CA-17 / HP-5 pending), so these rows are MISSING and written to be buildable from the row text.
- Not covered: Business Intelligence and Custom analytics (listed in the feature inventory rail but no component or
  route carries them; probably a paid add-on); Team analytics and Team reports (rated in `20-requests-inbox-team.md`
  §20.3.3–20.3.4); Recruitment dashboard and recruitment reports (N-A).

**PeopleOS principles that shape the ratings.** PeopleOS analytics are factual and privacy-suppressed:
`PlatformAnalytics` and the domain analytics services show no per-person scores, rankings or risk labels, and suppress
groups below `peopleos.analytics.min_group` (5). HROne's "Top 5 / Bottom 5 performers", "Unhappy employees" and "Bad
hires" widgets conflict with that; they are rated MISSING with an **owner decision** note, and the build notes offer a
factual, permission-gated equivalent (e.g. "early exits within 6 months of joining", "lowest final ratings — names
only with `performance.view`") rather than copying the label.

---

## 21.1 Analytics dashboards (`/app/analytics/*`, HR Desk → HR Analytics)

### 21.1.0 Shared dashboard chrome (every `/app/analytics/*` page)

All HROne dashboards share the same frame: a view picker (views come from Setup → Access rights → CXO access, §21.1.1),
a filter flyer (`app-filters` → `app-ou-applicability`), "Last updated time", a ⋮ menu with export, "show more" lists
that drill from a tile to the people behind it, and quick actions on those people.

| # | HROne element (tab / field / column / action) | What it does | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Select View" / "Show views" / "Views" picker | Switch between the shared CXO views of this dashboard (each view = an org-slice filter set by an admin) | `DashboardViewer` switcher across boards a user may see (`Dashboards::visibleTo`); one board = one fixed widget set, no saved org slice | PARTIAL → built (CA-10: the switcher lists the views a person may open; a view resolves its widgets inside its slice ∩ the viewer's access scope and says so under its title) | AN-1: dashboard views (`dashboard_views`: dashboard key, name, applicability filter, owners, recipients) and a picker on each module dashboard; the board resolves its widgets inside the view's slice ∩ the viewer's `AccessScope` |
| 2 | Filter (`filter_list`) flyer → "APPLICABLE ON": organisation dimension picker ("Select any one"), search, SELECT ALL, "Add bulk employees" (paste codes, e.g. Emp001,Emp002), "More" | Narrow every widget to an organisation slice or a pasted list of employees | Reports filter on any field (company, location, department … `employee_code` any-of); dashboards have no filter; `PeopleAnalyticsPage` only an as-of date; `CompensationAnalyticsPage` has a filter section | PARTIAL | AN-1: one shared `DashboardFilter` Livewire component (company, BU, location, department, designation, grade, employment type, employee codes pasted) passed into `WorkforceMetrics::metric()` and `ReportRunner::run()` as extra filters; the privacy threshold still applies after filtering |
| 3 | "Last updated time-" stamp | Dashboards read a nightly / periodic snapshot | Metrics and reports compute live on open; no snapshot | N-A (live) | Live data needs no stamp; show "as of <now>" for parity. Snapshots only if AN-17 performance work demands |
| 4 | "Refresh has been initiated. Please check back in a few moments." (Employee movement) | Manual refresh of the snapshot | Live | N-A (live) | — |
| 5 | ⋮ "menu" → EXPORT / "Export list" / "EXPORT LIST" / "Download" on a widget | Export the people behind a widget to Excel | Report run → CSV export (`ReportExports`, audited `EXPORT`); dashboard widgets backed by a shared report link to it; KPI widgets have no export | PARTIAL | AN-2: "Export" on every widget: report-backed widgets reuse `ReportExports`; metric widgets get a drill-down report definition (§ row 6) so they export the same way. `analytics.export` gate |
| 6 | "show more" on a tile / chart | Drill down from a number to the employee list behind it | `Dashboards` widgets show values only; the Command Centre KPIs do not drill | MISSING | AN-2: each metric declares a drill-down `(dataset, filters)` in `WorkforceMetrics::DRILLDOWNS`; "Show people" opens a slide-over table (names only for viewers with the dataset's permission; suppressed groups never drill) |
| 7 | Drill-down list → "Give a badge" (`app-give-badge`: badge, give to, message, suggested messages, do not post on social wall, attachment, internal notes) | Recognise someone straight from the dashboard | Recognition → Give a badge (`RecognitionResource`, HP-9c) | PARTIAL | AN-2: row action on drill-down lists calling the existing give-badge action (`EngagementActions`) |
| 8 | Drill-down list → "Give Reward Points" (`app-give-points`: quota this month, give to, points, message, do not post on social wall) | Reward from the dashboard | Reward points (HP-9d) give action | PARTIAL | AN-2: same row action wiring |
| 9 | Drill-down list → "Schedule 1-on-1" (`app-schedule-one`: 1 on 1 with, agenda, date, start / end time, meeting type, time zone, address, frequency, recurrence start / end / repeat every / on day / on the) | Book a one-on-one from the dashboard | `OneOnOneResource` (create with employee, agenda, date) | PARTIAL | AN-2: row action prefilled with the employee; recurrence is a performance gap (HP-8 one-on-one settings), not an analytics one |
| 10 | Drill-down list → "Create an initiative" (`app-assign-initiative`: initiative, due date, assign to, KPI, initiative type) | Assign an improvement initiative | Initiatives not built (HP-8j pending) | MISSING | After HP-8j: row action |
| 11 | "You do not have access" (`app-view-permission-denied`) | Per-dashboard access | Each analytics page has its own permission (`analytics.view`, `performance.analytics`, `servicedesk.analytics`, `engagement.analytics`, `talent.analytics`, `compensation.analytics`) | HAVE | — |
| 12 | "For an optimal user experience, this page is best viewed on the desktop version" | Mobile notice | Filament pages are responsive | N-A | — |

### 21.1.1 CXO access views (Setup → Access rights → CXO access, `/app/core/accessrights/analyticsaccess`; `app-analytics-access-setting`)

Rated in detail in `setup/01-core-hr-access-integration.md` §5.3 (package CA-10, built 2026-10-30). Repeated here only to tie the
dashboards to their views; the build lives in AN-1 together with CA-10.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Page "CXO access — You can create view here to be shared with CXOs" with one card per dashboard: Happiness index view, Return on investment view, Employee Movement view, HR Ops view, Payroll view, Helpdesk view, Engage view, 9 box, Performance, Survey, Pulse, Expense, Recruitment, Time Office | One list of saved views per dashboard | `DashboardResource` (one board, role audience) | PARTIAL → built (CA-10: views are dashboards with a `preset` key — movement, hr_ops, payroll, helpdesk, engage, nine_box, performance, survey, pulse, time_office, happiness, expense — added with "Add a CXO view"; ROI waits for AN-06 / AN-11) | AN-1 = CA-10: `dashboard_views` keyed by dashboard (`movement`, `hr_ops`, `payroll`, `helpdesk`, `engage`, `nine_box`, `performance`, `survey`, `pulse`, `expense`, `time_office`, `happiness`, `roi`); Recruitment N-A |
| 2 | View list: "SHOW MORE", "NO VIEWS", "Clone", "APPLICABLE ON" summary, EDIT, INACTIVATE | Manage views | Dashboards list: edit, status active / draft | PARTIAL → built (CA-10: Clone (a draft copy), Inactivate / Switch on, Send now, the "Applicable on" summary and the view's preset on the list) | AN-1: clone + inactivate on views |
| 3 | View name · `name` / `viewName` / `helpdeskViewName` | Name | Dashboard `name` | HAVE | — |
| 4 | Applicability · `filterType` + `filterValue` (organisation dimension + values) | The slice the view shows | — (dashboards are not sliced) | MISSING → built (CA-10: `dashboards.applicability`, one organisation dimension and its values, narrowing every employee-linked figure of the view for each viewer; figures it cannot narrow are not shown in a sliced view) | AN-1: `applicability` json reusing `AudienceCriteriaSchema` (company / BU / location / department / grade …) |
| 5 | Applicability fixed to "Enterprise" (ROI view, HR Ops view) | Those dashboards are organisation-wide only | — | MISSING → built for HR Ops (CA-10: presets `hr_ops` and `happiness` take no slice — the form locks it, the service refuses it, a stored slice shows nothing); ROI waits for AN-11 | AN-1: views of `roi` / `hr_ops` take no slice |
| 6 | Share view with · `ownerId` / `viewOwnerIds` (multiple employees) | Named people who may open the view | `role_ids` (roles only) | PARTIAL → built (CA-10: `viewer_user_ids`; a view with named people and no role is listed for them only; they still need analytics.view and each figure's right) | AN-1: `viewer_user_ids` in addition to roles; a named viewer still needs the dashboard's permission |
| 7 | "Do you want to share this by email?" · `mail` / `ismail` (Yes / No) + Recipients · `receiverId` / `viewReceiverIds` | Email the view periodically | Report schedules email-notify recipients in-app (`ReportSchedules`); dashboards are never sent | MISSING → built (CA-10: `email_user_ids` + daily / weekly / monthly cadence; an in-app notice and an e-mail (setting `analytics.views.email`) with the view's link, never figures; recipients who could not open it skipped; audited; run by `peopleos:reports:run-due`) | AN-1: optional schedule on a view (reuse `ReportSchedule` cadence fields) sending an in-app notice + mail with the view link; never attachments of personal data |
| 8 | Payroll view: "Payroll dashboard will be updated on [day of month] each month" · `Select day of the month` | Freeze the payroll dashboard until a set day (after payroll closes) | Payroll metrics read the last finalized run (`WorkforceMetrics::lastRun`) | PARTIAL → built (CA-10: per view `dashboards.refresh_day` (1–28, blank = live) rather than one setting) | AN-1: setting `analytics.payroll_dashboard_day` (1–28, default null = last finalized run, today's behaviour) |

### 21.1.2 Time office dashboard (`/app/analytics/cxo-timeoffice`, `app-cxo-timeoffice`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Time office dashboard", View picker, Filter | — | No time-office dashboard; attendance figures appear in `PeopleAnalyticsPage` (30-day present / absent / absenteeism) and the Command Centre (absenteeism) | MISSING | AN-3: `TimeOfficeDashboard` page (Analytics; `attendance.view` + `analytics.view`) built from datasets `attendance`, `attendance_monthly`, `time_office_requests`, `attendance_punches`, `leave`, `leave_balances` |
| 2 | "Month" selector | Month the tiles describe | Report filters `this_month` / `last_month`; no month picker on a page | MISSING | AN-3: month picker (pay cycle aware via `payroll.pay_cycle`) |
| 3 | "Attendance trend — Last 6 month data" chart with series toggles "Late coming and early leave", "Absent", "Overtime", "Comp off" | Six-month trend per measure | `WorkforceMetrics::series('absenteeism')` (12 months); late / OT / comp-off series absent; `attendance_monthly` has late_count, early_leave_count, absent_days, overtime_hours | PARTIAL | AN-3: series from `attendance_monthly` grouped by month (sum late_count + early_leave_count, absent_days, overtime_hours) and comp-off credits from `leave_ledger` (type `comp_off_credit`) |
| 4 | Tile "Late/early days" | Count of late or early days in the month | `late_summary_this_month` template; `attendance_monthly.late_count`, `early_leave_count` | PARTIAL | AN-3 tile + drill-down to `late_coming_this_month` / `early_leaving_this_month` templates |
| 5 | Tile "Absent days" | Absent days in the month | `attendance_monthly.absent_days`; template `monthly_summary_this_month`; `absentees_today` | PARTIAL | AN-3 tile + drill-down |
| 6 | Tile "Extra working hours" | Hours worked beyond shift (not approved as OT) | `attendance.worked_hours` vs scheduled; no "extra hours" field | MISSING | AN-3: field `extra_hours` = max(0, worked − scheduled) on `attendance` / `attendance_monthly` |
| 7 | Tile "Overtime hours" | Approved OT hours | `attendance_monthly.overtime_hours`; template `overtime_this_month` | PARTIAL | AN-3 tile |
| 8 | Tile "Compoff balance" | Outstanding comp-off | `leave_balances` (comp-off leave type `available`) | PARTIAL | AN-3 tile: sum `available` where leave type is comp-off (config `leave.comp_off_type`) |
| 9 | "Leave trend" chart + "Leave count" | Leave days per month | Templates `leave_availed_by_type`, `leave_availed_by_department`; no monthly series | PARTIAL | AN-3: series from `leave` (approved days by from_date month) |
| 10 | "On duty trend" + "On duty count" | On-duty days per month | `time_office_requests` (type On duty); template `on_duty_requests`; `wfh_on_duty_this_month` | PARTIAL | AN-3: series from `time_office_requests` where type = On duty, approved |
| 11 | "Manual Intervention" / "Manual intervention" + "Details" + "Reason" filter | Days HR or managers changed by hand (regularisations, HR overrides), by reason | `attendance_regularisations` dataset (reason, status); HR day overrides (HP-1 TO-13/14) not in a dataset | PARTIAL | AN-3: tile = approved regularisations + HR overrides in the month; dataset field `override_reason`; drill-down with reason filter |
| 12 | "Punch Source" (distribution) | Share of punches by source (biometric, mobile, web, …) | `attendance_punches.source`, `device`; no grouped template | PARTIAL | AN-3 donut: group `attendance_punches` by `source` this month; also add library template `punches_by_source` |
| 13 | "show more" list: Date, No. of punches, Odd Punches (`app-show-more-content`) | Per-day punch counts and odd-punch days for an employee | `missed_punches_this_month` template; `attendance.missed_punch`; punch count per day not a field | PARTIAL | AN-3: `attendance` field `punch_count`, `odd_punches` (count odd) |
| 14 | "Run" | Re-run the calculation for the filters | Live | N-A (live) | — |
| 15 | "No data found" | Empty state | — | HAVE | — |

### 21.1.3 Employee movement (`/app/analytics/employeemovement`, `app-employee-movement`; view editor `app-employee-movement-view`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Employee Movement", Select View, Filter | — | `WorkforceCommandCentre` (headcount, joiners, exits, attrition, trends) and `PeopleAnalyticsPage` "People" area | PARTIAL | AN-4: `EmployeeMovementDashboard` page (or a seeded "Employee movement" board) with the tiles below |
| 2 | "THIS MONTH" toggle | Tiles for the current month | Metrics use rolling 30 days | PARTIAL | AN-4: month basis for joiners / exits tiles (calendar or pay-cycle month) |
| 3 | "Upcoming Joining" tile | Offers accepted / pre-boarding expected to join | Template `upcoming_joiners` (next 30 days) | PARTIAL | AN-4 tile + drill-down to the template |
| 4 | "Live" / "NEW" / "TOTAL HEAD COUNT" / "EMPLOYEES" tiles | Headcount now, new joiners | Metrics `headcount`, `joiners_30d` | HAVE | Reuse in AN-4 |
| 5 | "In Notice Period" tile | Employees serving notice | Template `notice_period_tracker`; metric `exits_in_progress` | HAVE | Reuse |
| 6 | Chart "Total headcount" with toggle "Total Headcount" / "In/Out" | Monthly headcount line, or joiners vs exits per month | `WorkforceMetrics::series('headcount' / 'joiners' / 'exits')` (12 months) on the Command Centre | HAVE | Reuse in AN-4 |
| 7 | "Bad Hires (LAST SIX MONTHS)" + "Employee count" | Exits within a short period after joining | `exits` dataset has `tenure_months`; no template or tile | MISSING | AN-4 (owner decision on the label): tile "Early exits (within 6 months of joining)" = exits with `tenure_months` < 6 in the last six months; library template `early_exits`. Factual, no judgement of the person |
| 8 | "Attrition Rate" | Attrition % | Metric `attrition_rate` (12-month exits ÷ average headcount) | HAVE | Optionally monthly attrition series (AN-4) |
| 9 | "TOP REASONS FOR LEAVING" | Ranked exit reasons | `ExitInsights` (employee vs HR-inferred reasons); template `exit_reasons` | HAVE | Show on the movement board |
| 10 | EXPORT (menu) | Export the list behind a tile | §21.1.0 row 5 | PARTIAL | AN-2 |
| 11 | View editor: INACTIVATE, EDIT, View name, Share view with, applicability, email Yes / No, Recipients | — | §21.1.1 | PARTIAL → built (CA-10, preset `movement`) | AN-1 |

### 21.1.4 Engage dashboard (`/app/analytics/engage`, `app-engage-analytics`; view `app-engage-analytics-view`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Engage dashboard", Show views, Filter | — | No engage board; ledgers exist as datasets | MISSING | AN-5: seeded "Engage overview" board (`AnalyticsDefaults::seedEngage()`, like `seedExpense`) for roles with `recognition.manage` / `engagement.analytics` |
| 2 | "Badges and Reward points distribution" chart, "Yearly" toggle, series "Badges" / "Points" | Badges and points given per month across the year | Datasets `badges_ledger` (`given_on`, `badge`, `status`), `reward_points_ledger` (`date`, `kind`, `points`) | PARTIAL | AN-5: two monthly series (count badges given; sum points awarded) — library templates `badges_by_month`, `points_by_month` |
| 3 | Filter "Badge type" | Limit to one badge | `badges_ledger.badge` filter | PARTIAL | AN-5 board filter |
| 4 | Filter "Period" | Year / month range | Date operators on reports | PARTIAL | AN-5 |
| 5 | Filter "DOJ" (date of joining) | Limit to joiners of a period | `employees.joining_date`; ledger datasets carry employee fields but not joining date | PARTIAL | Add `joining_date` to `Dataset::employeeFields()` |
| 6 | "Reward points" tile | Points awarded in the period | `reward_points_ledger` sum | PARTIAL | AN-5 metric `reward_points_awarded` |
| 7 | "Top 5 points holders" (Name) + "Export list" | Leaderboard of points | Dashboard widget type `leaderboard` exists (`DashboardResource`); no points template grouped by employee | PARTIAL | AN-5: template `points_by_employee` (sum points, desc) in a `leaderboard` widget, limit 5. Recognition leaderboards are public by design in HROne's wall of fame; PeopleOS shows them only to `recognition.manage` holders |
| 8 | "Top 5 badge holders" | Leaderboard of badges | Template `badges_by_employee` (count desc) | HAVE | Use as `leaderboard` widget, limit 5 |
| 9 | "NO ACCESS POINTS" / "NO ACCESS BADGES" | Viewer lacks rights to that half | Datasets gated by permission | HAVE | — |
| 10 | View editor (Engage view: INACTIVATE, EDIT, SAVE, View name, Share view with, applicability, email, Recipients) | — | §21.1.1 | PARTIAL → built (CA-10, preset `engage`) | AN-1 |

### 21.1.5 Expense dashboard (`/app/analytics/expense`, `app-expense-analytics`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Expense dashboard", View, Filter | — | Dashboards → "Expense overview" (EX-G, `AnalyticsDefaults::seedExpense()`, added on request) | HAVE | — |
| 2 | "Month" + "Monthly data": "TOTAL expenses = reimbursable + non reimbursable" | Month total split by who paid | `expense_receipts.reimbursable` (repaid to the employee) and approved amounts; no split tile | PARTIAL | AN-6: two metrics `expense_month_reimbursable`, `expense_month_company_paid` + total |
| 3 | "Expenses — Last 12 months data" chart | Monthly expense trend | Template `expense_paid_by_month` (paid by month, this FY); by expense date not charted | PARTIAL | AN-6: template `expense_by_month` (approved amount by `expense_date` month, 12 months) as a chart widget |
| 4 | "Search expense", "Group by", "Filter by" over a table, keyboard arrows (paging), "Total" | Pivot the expense table by category / department / employee | Templates `expense_by_category_this_year`, `expense_by_department_this_year`; any report can group by any field | HAVE | — |
| 5 | "Lifetime data": "To be reimbursed − Advance Amount = to be paid" | Net owed to employees after outstanding advances | Metrics `expense_payable`, `expense_advances_outstanding` | PARTIAL | AN-6: metric `expense_net_to_pay` = payable − outstanding advances (per employee floor at 0) |
| 6 | "No data", "show more", Export | — | §21.1.0 | PARTIAL | AN-2 |

### 21.1.6 Helpdesk dashboard (`/app/analytics/helpdesk`, `app-helpdesk-analytics`; view `app-helpdesk-view`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Helpdesk", Show views, "MONTHLY" | — | Service Desk → Service analytics (`ServiceDeskAnalyticsPage`, `ServiceDeskAnalytics::summary(from, to)`) | HAVE | — |
| 2 | "New tickets" + "from last month" (change) | Tickets raised this month vs last | `received` for a period; no comparison | PARTIAL | AN-7: compute the previous period and show the delta |
| 3 | "Tickets closed on time" | Resolved within due date | `sla_compliance_percent`, `resolved` | PARTIAL | AN-7: count as well as % |
| 4 | "Tickets closed after due day" | Resolved after due date | `breached` (includes open overdue) | PARTIAL | AN-7: split breached into closed-late and open-overdue |
| 5 | "Tickets closed after TAT" | Resolved after the turnaround time (separate from the due date — HROne's two-stage SLA) | Single SLA (`due_at`); two-stage SLA is setup gap SV-10 | MISSING | After SV-10: count of tickets closed after TAT |
| 6 | "Ageing (On created date)" | Open tickets bucketed by age (0–2, 3–7 … days) | `backlog_overdue` only | MISSING | AN-7: ageing buckets of open tickets by `created_at` (buckets in config `servicedesk.ageing_buckets`) |
| 7 | "Live" | Open now | `open` | HAVE | — |
| 8 | "Agents with open tickets" | Open tickets per agent | `by_team` (assigned role); not per agent | PARTIAL | AN-7: per-agent open counts for `servicedesk.analytics` holders (agents are staff, not subjects; no suppression needed) |
| 9 | "Top 5 subcategories" | Most frequent sub-categories | `by_service` (service / category); template `tickets_by_category` | PARTIAL | AN-7: sub-category level (ticket categories are two-level since HD-A) |
| 10 | "Top agents" (Monthly) | Agents with most resolved tickets | — | MISSING | AN-7 (owner decision: agent leaderboard): resolved per agent table; no ranking label |
| 11 | Toggle "Open tickets" / "Closed tickets" | Switch the lists | Status breakdown | PARTIAL | AN-7 |
| 12 | "NO RECORDS", "Export list" | — | §21.1.0 | PARTIAL | AN-2 |
| 13 | Helpdesk view editor (`helpdeskViewName`, `viewFilterType`, `viewFilterValue`, `viewOwnerIds`, `ismail`, `viewReceiverIds`) | — | §21.1.1 | PARTIAL → built (CA-10, preset `helpdesk`) | AN-1 |

### 21.1.7 9 box dashboard (`/app/analytics/ninebox`, `app-nine-box`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "9 Box Dashboard", "View" picker | — | `NineBoxGrid` (HP-8g, 2026-10-29): cycle grid, performance × potential, counts and names by access | HAVE | HP-8g (pending, `12-performance-engage.md` §12.13): `NineBoxGrid` page; AN-8 adds the analytics view of it |
| 2 | "Performance cycle" filter | Grid for one cycle | `NineBoxGrid` per cycle; performance from the rating that stands (HP-8g) | HAVE | HP-8g: performance axis from the cycle's final ratings via `performance_nine_box_bands` |
| 3 | "Review cycle" filter | Grid for one review within the cycle (mid-year / annual) | Appraisals per cycle; no sub-review | MISSING | HP-8i performance policy (review stages) then filter |
| 4 | Axes "Potential" (High / Moderate / Low) × "Performance" | The 3 × 3 grid | `NineBoxGrid` 3 × 3 with `performance_nine_box_bands` (default thirds) (HP-8g) | HAVE | HP-8g |
| 5 | Box "Name", "Background Colour", "Edit" | Rename and recolour each of the nine boxes | Box names from config (`performance.nine_box.boxes`), not editable per tenant; no colours (HP-8g) | PARTIAL | HP-8g: `performance_nine_box_cells` (row, col, label, colour) seeded with neutral labels |
| 6 | Count per box + "show more" (employees in a box) | Drill into a box | Count per box; names for scoped viewers, counts only with small groups suppressed for analytics viewers (HP-8g) | HAVE | HP-8g: names only for `performance.calibrate` / `talent.view`; counts suppressed below `performance.analytics_min_group` |
| 7 | "Download" | Export the grid | — | MISSING | AN-2 export of the drill-down |
| 8 | "No reviews pending" | Empty state | Empty state on `NineBoxGrid` (HP-8g) | HAVE | HP-8g |

### 21.1.8 Performance dashboard (`/app/analytics/performance`, `app-performance`, `app-performance-table`, `app-performance-analytics-view`, `app-performance-analytics-detail`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Performance dashboard", "Views", Filter | — | Performance → Analytics (`PerformanceAnalyticsPage`; cycle picker; overall and per-department blocks with suppression) | HAVE | — |
| 2 | "Performance cycle" selector | Cycle | "Choose cycle" header action | HAVE | — |
| 3 | Toggle "Performance" / "Review" | Final performance rating vs review (appraisal) rating | One effective rating per appraisal | PARTIAL | HP-8i (review stages) |
| 4 | Toggle "New Hires" / "All" | Limit to joiners in the cycle | — | MISSING | AN-9: filter employees joined within the cycle |
| 5 | Toggle "Open" / "Closed" | Open vs closed reviews | `appraisal_status` template (status counts) | PARTIAL | AN-9: status split on the page |
| 6 | "Reviews" tile + Count | Reviews in the cycle | `appraisals`, `appraisal_completion` | HAVE | — |
| 7 | "1-on-1 Meetings" tile + Count | One-on-ones held in the cycle | `OneOnOnes` records; not on the page | PARTIAL | AN-9: count one-on-ones in the cycle window |
| 8 | "Initiatives" tile + Count | Initiatives assigned | Initiatives and the `initiatives` dataset exist (HP-8j); no dashboard tile | PARTIAL | After HP-8j |
| 9 | "Top performers — Top 5 performers" (Name, Score) + Export | Highest final scores | `performance` dataset (final rating); rating distribution only on the page; principle: no rankings | MISSING | Owner decision. If accepted: AN-9 table "Highest final ratings" (names only with `performance.view`, never to managers outside scope), from the dataset sorted desc, limit 5 |
| 10 | "Bottom performers — Bottom 5 performers" (Name, Score) + Export | Lowest final scores | — | MISSING | Owner decision, as row 9; PIP candidates already flow through `ImprovementPlanRules` |
| 11 | "Review rating analysis" chart | Distribution of review ratings | `rating_distribution` (final labels) | HAVE | — |
| 12 | "Performance rating analysis" chart | Distribution of performance (final) ratings | Same; template `rating_distribution` | HAVE | — |
| 13 | Employee detail (`app-performance-analytics-view`): cards "360 feedback", "Points", "Badges", "Initiatives", "1 on 1 meetings" with help texts ("Here you can view …") and "DATA" | One employee's performance signals for the cycle | Employee profile tabs show appraisals, goals, feedback, recognitions separately; no cycle-bound card set | PARTIAL | AN-9: employee drill panel listing counts for the cycle window, each linking to the record list |
| 14 | Detail → Badges and reward points flyer (Period; Badges / Reward points tabs; Download attachment / certificate; reward; internal note; wallet points, conversion rate, transfer, claim) | Recognition history of the employee | Recognition and reward points (HP-9c / HP-9d) per employee | HAVE | Link from the drill panel |
| 15 | Detail tab "Initiatives (n)": Initiative, Due date, Feedback, Comments, Completion percentage, activity log, comments flyer (Initiative type, Assigned to, By, Planned, comment box) | Initiative list | Initiatives with feedback exist (HP-8j); no drill tab in the employee detail | PARTIAL | HP-8j |
| 16 | Detail tab "1 on 1 (n)": S.no., With, On, Agenda, Date, Time, Progress, Plans, Challenges, Any other feedback, MINUTES OF MEETING, Actions | One-on-one history | `OneOnOneResource` (agenda, notes, date) | PARTIAL | One-on-one structured notes (progress / plans / challenges) are a HP-8 performance gap |
| 17 | Detail tab "360 feedback (n)": Feedback from (Peers, Subordinate, Client, Reporting manager, Functional manager), Completed on, Average rating, "Chose not to participate", IGNORED, the answers | 360 history | `Feedback` service (requests, responses); HP-8h 360 policy pending | PARTIAL | HP-8h then link |

### 21.1.9 Pulse (`/app/analytics/pulse`, `app-pulse`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Pulse", Select View, Filter | — | Surveys of type `pulse` (`peopleos.engagement.survey_types`); results on `SurveyResults` per survey | PARTIAL | AN-10: a pulse overview across all pulse surveys |
| 2 | "Cultures score" — "Lifetime data" | Average score across all pulse answers ever | Per-survey averages only | MISSING | AN-10: lifetime average over closed pulse versions (suppressed below min group) |
| 3 | "Benchmark score" | External / industry benchmark | — | N-A (needs an external benchmark source) | Owner decision; optional tenant-entered benchmark per question (`engagement.pulse_benchmark`) |
| 4 | "Last pulse score" | Score of the latest pulse | `SurveyResults` "Trend (overall, closed versions)": averages per version | HAVE | — |
| 5 | Per question: "Overall avg", "no. of times asked" | Question-level lifetime stats | Trend per version, not per question across versions | PARTIAL | AN-10: question bank key → average and count across versions |
| 6 | "Last pulse distribution" | Answer distribution of the latest pulse | `SurveyResults` answers per question | HAVE | — |
| 7 | "No. of responses" | Respondents | `respondents`, response rate | HAVE | — |
| 8 | "Comments" + "Anonymous" + Next / Previous + Export | Free-text answers, anonymous | Comments "overall only, shuffled, never attributed" (`engagement.comments`) | HAVE | Export of comments: AN-2 (never with identities) |
| 9 | "Total questions:", "Total responses:" | Counts | Survey header | HAVE | — |
| 10 | Filters "Questions", "Date" | Narrow | Survey picker; no cross-survey date range | PARTIAL | AN-10 |

### 21.1.10 Survey (`/app/analytics/survey`, `app-survey`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Survey", Select View, Filter | — | `SurveyResults` (per survey; `engagement.analytics` / `team_results`) | HAVE | — |
| 2 | Filter "Survey name" | Pick a survey | Survey record page | HAVE | — |
| 3 | Filter "Date" | Response date range | Versions (each run) | PARTIAL | — (versions cover it) |
| 4 | Tabs / panels "Questions", "Responses" with Prev / Next | Question-by-question or respondent-by-respondent | Question-level aggregates; respondent-level answers only for non-anonymous surveys with `engagement.responses` | HAVE | — |
| 5 | "Average Rating" | Per-question average | Averages | HAVE | — |
| 6 | "Latest data", "refresh", "Reset all" | Reload / clear filters | Live | N-A (live) | — |

### 21.1.11 ROI — Return on investment (`/app/analytics/returnoninvestment`, `app-return-on-investment`; view `app-roi-view`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
Captured live (`route_app_analytics_returnoninvestment`): help text "ROI (Return on Investment) dashboard gives you a
ballpark figure for saving done by HROne. This dashboard is available at Enterprise level only.", a month picker
(`hroneSelect`, e.g. "Oct, 2026"; APIs `/api/core/global/Month`, `/Year`), "Last updated time-", 43 task rows.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "ROI - Return on investment", help text, "Hours Saved" / "Savings" sections | Time and money saved by automating HR tasks | — (gap register AN-06) | MISSING | AN-11: `RoiDashboard` page (Analytics; `analytics.executive`), organisation-wide only |
| 2 | Month picker (month, year) | Month for "This month hour saved" | — | MISSING | AN-11 |
| 3 | Table columns: S.No, Task, "Average time to complete a task - manually(min)", "Average time to complete a task - HROne(min)", "Tasks completed", "Hours saved" (HH:MM) | Per task type: (manual − system minutes) × tasks completed | Completed workflow tasks / approvals / self-service submissions per type exist (`WorkflowTask`, `ApprovalCenter` sources, audit events) | MISSING | AN-11: config `analytics.roi.tasks` = task key → [manual minutes, system minutes] (tenant-editable in Setup, defaults shipped as estimates and labelled so); tasks completed counted from audit events per key |
| 4 | Task rows (43 captured): Salary structure, Ack letter, Appointment letter, Arrear unlock, Assign recruiter, Assign shift, Attendance regularization, Bank details, Change attendance status, Confirmation, Confirmation letter, Declare statutory details, Documents, Family details, HROne notification, Initiate induction, Initiate settlement, Job requisition, Leave, Leave cancel, Mark attendance, Mood bot, New announcement, New join CTC, Personal details, Position assigned, Process arrear, Process attendance, Process attendance correction arrear, Process leave carry forward, Process salary, Professional details, Publish salary, Refer a friend, Release salary, Relieving letter, Salary revision letter, Snapshot, Unprocess attendance, Unprocess salary, Unrelease salary, Verify compensatory off, Wish to celebration | The catalogue of measured tasks | Each maps to an existing PeopleOS action / audit event except recruitment ones (Assign recruiter, Job requisition, Refer a friend → N-A) and "Unprocess salary / Unrelease salary" (finalized payroll is never reopened; map to "recalculate before finalize") | MISSING | AN-11: seed `analytics.roi.tasks` with the PeopleOS equivalents of these 40 in-scope tasks |
| 5 | "Total savings" | Hours saved × HR hourly cost | — | MISSING | AN-11 |
| 6 | "Lifetime hours saved", "This month hour saved" | Totals | — | MISSING | AN-11 |
| 7 | "Average salary of HR (in INR)" input | Converts hours to money | — | MISSING | AN-11: setting `analytics.roi.hr_monthly_cost` (tenant currency; no INR hard-code; hourly = monthly ÷ configured working hours) |
| 8 | ⋮ menu | Export | — | MISSING | AN-2 |
| 9 | ROI view: View name, Applicability = Enterprise only, Share view with, email, Recipients | — | §21.1.1 | PARTIAL | AN-1 |

### 21.1.12 Other HROne dashboards on the Analytics rail (no `/app/analytics` route captured; from components and the feature inventory)

#### Payroll dashboard (`app-payroll-analytics`, view `app-payroll-analytics-view`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Payroll", Select View, "MONTHLY" | — | Compensation → Analytics (`CompensationAnalyticsPage`: fixed / variable totals, groups, range penetration); payroll cost templates | PARTIAL | AN-12: seeded "Payroll overview" board (`seedPayroll()`) |
| 2 | "Effective CTC = Net paid + Net contributions + Net deductions + Net reimbursement" | Monthly cost decomposition | `payroll` dataset: gross, net_pay, total_deductions, employer_cost; reimbursements in `payroll_adjustments` | PARTIAL | AN-12: KPI row from the last finalized run (net paid, employer contributions, deductions, reimbursements, effective CTC) |
| 3 | "Salary Distribution" (Yearly) | Employees per salary band | `ctc_register` (annual CTC); no banding | PARTIAL | AN-12: `ctc_band` field (bands from config `analytics.ctc_bands`, in tenant currency) + grouped template |
| 4 | "Contributions" (Monthly) | Employer PF / ESI / LWF … by month | Template `statutory_summary_by_month` | HAVE | — |
| 5 | "Deductions" | Deductions by component | Template `deductions_by_component` | HAVE | — |
| 6 | "Variables" | Variable pay paid | `payroll_adjustments` / variable pay (BP-9); no template | PARTIAL → built (AN-26: template `variable_paid` over dataset `variable_pay_requests`) | Template `variable_paid` (§21.2.2 Payroll #60) |
| 7 | "Liability — LIFETIME" | Outstanding liabilities (gratuity, leave encashment, bonus, LTA) | Gratuity accrual in compliance register; others missing | PARTIAL | AN-12 after PY-20 / §21.2 payroll liability reports |
| 8 | "Loans — Till Date" | Outstanding loans | Loans module (BP-9); no dataset | PARTIAL → built (AN-26: dataset `loans`, template `loans_outstanding` (outstanding per loan type)) | Dataset `loans` (§21.2.2 Payroll #32–34) |
| 9 | Toggles "Effective CTC (INR)", "Net Paid (INR)", "CTC Analysis (INR)" | Chart measure | Templates `payroll_cost_by_period`, `ctc_by_department` | HAVE | Currency from the run, not INR |
| 10 | "NO DEDUCTION", "NO CONTRIBUTION", "EXPORT LIST" | — | — | PARTIAL | AN-2 |
| 11 | Payroll view "dashboard will be updated on day N each month" | §21.1.1 row 8 | — | PARTIAL → built (CA-10: `refresh_day` on the payroll view) | AN-1 |

#### Happiness index (`app-happiness-index`, view `app-moodbot-view`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "Happiness Index", Select View, "Monthly" | — | Engagement → Happiness index (`HappinessIndex` page, HP-9f): per period and group average mood, identified check-ins | HAVE | — |
| 2 | "IMPROVEMENT AREAS" (Count) / "GOING GOOD" (Score) / "Responses" toggles | Reason tags split into improvement vs going-good | Mood check-ins carry reasons (Other, Growth, Colleagues, Facilities, Management, Appreciation …) | PARTIAL | AN-13: reason tags classified improvement / going good by the mood they came with; counts per tag |
| 3 | "Improvement areas (last 90 days)", "Going good areas (last 90 days)" | Ranked tags | — | PARTIAL | AN-13 |
| 4 | "Unhappy employees (last 3 months)" | Named list of people who checked in unhappy | "Identified check-ins" only where the organisation chose to identify, people in scope, `engagement.confidential_identity` | PARTIAL | Owner decision: keep the identified-only rule; list = identified check-ins with low mood |
| 5 | "Avg score" | Average mood | `average_mood` | HAVE | — |
| 6 | "Comments (Monthly)" + Export | Comments per month | Identified comments; dataset `happiness_index` (suppressed aggregates) | PARTIAL | AN-2 export |
| 7 | "NO RECORDS", "NO RESPONSE" | Empty states | — | HAVE | — |

#### HR Ops (`app-employee-efficiency`, view `app-employee-efficiency-view`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title "HR Ops", "Module" filter | Process efficiency per HR task type | — | MISSING | AN-14: `HrOpsDashboard` (`analytics.executive` or `workflow.view`) |
| 2 | Toggle "Completion Times" / "Process health" | Two views | — | MISSING | AN-14 |
| 3 | Table: S.No, Task, "Avg. completion time as per due days", "Process efficiency", Action | Average time to complete each task type vs its due days | Workflow tasks have `due_at` / `completed_at`; approvals decided at; no report | MISSING | AN-14: per task / approval type: average days to complete, % on time; dataset `workflow_tasks` (type, assigned, due, completed, on_time) |
| 4 | Dialog "Add avg completion time": Task Name, Avg time in days, Calculate, Rating, Cancel, Done | Set the target time per task and rate efficiency | — | MISSING | AN-14: target days per type (`analytics.hr_ops.targets`, Setup) and a rating band (on target / near / late) |
| 5 | HR Ops view: Applicability = Enterprise only | — | §21.1.1 | PARTIAL → built (CA-10, preset `hr_ops`) | AN-1 |

#### Custom dashboards and widgets (`app-analytic-dashboard-home`; also the Analytics tab of Projects)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | "DashBoards" list, "Add Dashboard", "Create New dashboard" (`app-db-create`: Enter Dashboard Name, "Dashboard already exists") | User-made dashboards | `DashboardResource` (analytics.manage; name, slug, roles, default, sort, status) | HAVE | — |
| 2 | "Add quick dashboard" — "Please select from the curated list of dashboards we have for you", "Skip for Now" | Start from curated boards | Default "HR overview" at provisioning; "Add the expense dashboard" | PARTIAL → built in part (CA-10: "Add a CXO view" offers twelve ready-made boards; the full AN-15 gallery remains) | AN-15: a gallery of seeded boards (HR overview, Expense, Engage, Payroll, Time office, Movement) with "Add" |
| 3 | "Add Widget" → "Create New Widget", "Add from existing", "Add From Widget Glossary", "Add Existing Widget" (`app-dbw-exiting-list`: Select Module, search by widget / saved view name, checklist, Import, + Create New) | Widgets come from saved report views | Widgets: kpi / trend (metric), chart / table / leaderboard (shared report), alerts | HAVE | Widget glossary = the metric list + report library templates (AN-15 adds "from library" which saves the template then adds it) |
| 4 | Widget kinds: chart (`app-dbw-chart`), counter (`app-dbw-counter`), table with Total (`app-dbw-table`) | Chart, single number, table | kpi, chart, table, leaderboard, trend | HAVE | Table "Total" row: AN-15 |
| 5 | Widget ⋮: Copy Widget, Aspect Ratio, Remove, Edit Filter, View Details | Per-widget actions | Edit via the dashboard form only | PARTIAL | AN-15: copy to another board, remove, open the backing report ("View details"), per-widget filter override |
| 6 | Widget settings (`app-dbw-setting`): Choose Widget Height `widgetSizeY`, Width `widgetSizeX`, Row `row`, Col `col`, Sequence | Grid placement | `size` (1–4) and repeater order | PARTIAL | AN-15: height option |
| 7 | "Reorder" (`app-dbw-reorder-widgets`: Save Order) | Reorder widgets | Repeater reorderable | HAVE | — |
| 8 | "Copy Widget To Dashboard" / "Add imported widget to dashboard" (Select Dashboard Name, NO THANKS / ADD TO DASHBOARD) | Reuse a widget elsewhere | — | MISSING | AN-15 |
| 9 | "Share" → "Add bulk employees", Send | Share a dashboard with named employees | Role audience only | PARTIAL → built (CA-10: "Share view with" named people on any dashboard; "Send now") | AN-1 named viewers |
| 10 | "star" | Favourite / default dashboard per user | `is_default` (tenant-wide) | PARTIAL | AN-15: per-user favourite board |
| 11 | Report flyer (`app-dbw-report-flyer`: Prev, Next, Show, table) + password dialog (Password, Confirm password, "Enter password to view report") | Open a password-protected report behind a widget | No password-protected reports | MISSING | AN-20 (password-protected reports) |
| 12 | Zoom dialog (`app-dbw-zoom-dialog`: DONE) | Enlarge a chart | — | MISSING | AN-15 (modal) |
| 13 | "Select Report" / "Select Module" (`app-db-reports-list`, search by report name) | Pick a report for a widget | Report select (shared reports) | HAVE | — |
| 14 | "No Data", "Oops, there are no widgets on your dashboard!", "The dashboards are coming up" | Empty / loading states | — | HAVE | — |

#### Not captured: Business Intelligence, Custom Analytics, Recruitment dashboard

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Business Intelligence, Custom Analytics (feature inventory rail) | Probably an embedded BI add-on *(inferred)* | Read API for reports (`GET /api/v1/reports/{report}/run`, unattended allow-list) | N-A (not captured) | Re-check when the crawl lands; PeopleOS answer is the read API + CSV |
| 2 | Recruitment dashboard (`/app/analytics/recruitment`, `app-recruitment`) | Hiring funnel | — | N-A (recruitment, separate RMS) | — |

**21.1 counts** (rows): HAVE 41 · PARTIAL 76 · MISSING 39 · N-A 8 (164 rows).

---

## 21.2 Reports (HR Desk → Reports, `/app/processes/reports`, `/app/processes/reports/search`)

### 21.2.1 Report centre, report viewer, saved views, schedules and custom reports

Components: `app-reports-summary-home(-new)`, `app-reports-home`, `app-reports-grid`, `app-report-search`,
`app-global-search-modulewise-reports`, `app-global-search-report-view`, `app-create-reports`, `app-report-details`,
the filter widgets, `app-saved-reports`, `app-saved-successfully-modal`, `app-recent-downloads`,
`app-set-password-dialog`, `app-report-format`, `app-add-custom-report`, `app-report-flyer-standalone`,
`app-customer-request`, `app-password-protected-reports`.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Report search "Enter report name" + "NO RESULT — We can't find this report. Try another keyword." + "Back to Reports" | Find a report by name | Report library search (`ReportLibrary::availableTo($user, $search)` over name, description, category); Reports list search | HAVE | — |
| 2 | Tabs "Modules" / "Processes" + "Search processes" | Browse the catalogue by module, or by process (leave, payroll run, exit …) | Library grouped by 10 categories | PARTIAL | AN-16: second grouping "process" (tag per template: leave, attendance, payroll run, joining, exit, expense, travel, tax …) |
| 3 | Module cards with report lists, "SHOW MORE" (`app-reports-grid`) | Catalogue grid | `ReportLibraryPage` category sections | HAVE | — |
| 4 | Per report: name, description, output type (Excel `E` / PDF `P`), "Scheduled" capability | What each report is and how it comes out | Templates have name + description; output CSV only | PARTIAL | AN-17: `.xlsx` export (needs a spreadsheet library decision — phase-14 known gap) and PDF for form-type reports (Compliance packs already render their own files) |
| 5 | "Scheduled Reports" list | Reports the user has scheduled | Per report → Schedules tab (`SchedulesRelationManager`); no cross-report list | PARTIAL | AN-16: "My schedules" page over `report_schedules` of reports the user owns |
| 6 | "Saved view" / "View All" | The user's saved report views | Reports list (own + shared) | HAVE | — |
| 7 | "EXPLORE CUSTOM REPORTS" / "ADD CUSTOM REPORTS" | Tenant-specific reports | Report builder (`ReportResource` create: dataset → fields → filters → grouping → calculated → visualization) | HAVE | — |
| 8 | Custom report request (`app-customer-request`: Comments*, "Upload file format", Submit) | Ask HROne to build a report from a sample format | — | N-A (vendor service) | PeopleOS answer: the builder; a request can go to the service desk |
| 9 | Report screen filters: date range "From date / To date" (`from_date`, `to_date`), month-year range (`From` / `To`: Month, Year), single "Month / Year", multi-select with search and "Select All", radio list, "Sort by", employee auto-complete with "Add bulk employees" (paste codes) | Typed parameters per report | Filters on any field with operators (equals, any of, contains, between, last N days, this / last month, this / last FY, next N days …); `employee_code` "any of" takes a pasted list | HAVE | Templates have fixed periods ("this month", "last month"); AN-16: prompt-at-run parameters (period, organisation unit) on a saved report so one template covers any month |
| 10 | "ADD FILTER" / "Custom filters" / "Search filters" / "Applied filters" / "Clear filters" / "Clear All" | Optional extra filters | Builder filters repeater | HAVE | — |
| 11 | "Employement Status *": Active employees / Inactive employees (`activeEmployee`, `inactiveEmployee`) | Include leavers or not (mandatory choice) | `employee_status` / `lifecycle_state` filters; templates mostly active only | HAVE | — |
| 12 | "COLUMNS" — Select All, "Selected columns will be downloaded in the report", Show | Choose output columns | Builder `fields` (choose and order) | HAVE | — |
| 13 | "View report" / "View Report" (paged table, Prev / Next, "No More Records", "Report preview unavailable — This type of report cannot be viewed. Please click on download") | Preview on screen | View report page (table, grouped table, chart, KPI) | HAVE | — |
| 14 | "Download report" / "Download" | Download | CSV export (`ReportExports`, audited) | PARTIAL | AN-17 (`.xlsx`) |
| 15 | "Download in background" + "Report Generation in progress … return in approximately 15 minutes" | Queue large reports | Exports run synchronously; bounded at `analytics.max_rows` 10,000 | MISSING | AN-17: queued export job (`ExportReportJob`) writing a `ReportRun` with status queued → completed; in-app notice when ready; lift the row bound for queued runs (chunked) |
| 16 | "Secure your report with encryption" + set password (`pwprotecetedReportPassword`, confirm) and "Enter password to view report" | Password-protected download | — | MISSING | AN-20: password-protected `.xlsx` / zip (needs the library decision of BP-14) |
| 17 | Recent downloads (`app-recent-downloads`): Report name, View name, Status, Schedule time, Completed time, Actions (Run now, Regenerate, Cancel, Delete), VIEW FILTERS, Refresh, "Last three months reports requested by you are visible" | Download history and re-run | Per report → Runs tab (download; `ReportRun` status, rows, timing) | PARTIAL | AN-17: "My downloads" page across reports (last 90 days), actions run now / regenerate / cancel queued / delete file; "view filters" shows the definition used |
| 18 | "Save view" / "Save as" / "Edit view" / "Clone view" / "Delete view" / "Select View" / "No views saved!" | Save a filter + column set as a named view | Save a report (own); edit; duplicate via library "Use"; delete | HAVE | Clone: AN-16 "Duplicate" action on reports |
| 19 | Save-view step "1. Basic Details": Enter report name (`reportName`) | Name | Report `name` | HAVE | — |
| 20 | Schedule: "Days" (`schedulerDay`), day of month (`schedulerMonthDay`), "Enter time" (`schedulerTime`) | When the view is emailed | Schedule frequency daily / weekly (day) / monthly (day 1–28), time, status | HAVE | — |
| 21 | "Additional recipients" (`additionalRecipient`) + "Note: Scheduled reports will not be password protected." | Mail the file to people | Recipients (tenant users) get an in-app notice to download | PARTIAL | AN-18: optional email delivery of the link (never the file) to recipient users; external addresses refused |
| 22 | "Share report view with": None / Add manually / Public (`visibleToSelf`, `reportFor`) with help texts | Private, named users, or everyone with rights | `is_shared` (everyone who can read the dataset; `analytics.manage`) | PARTIAL | AN-18: share with named users / roles (`shared_user_ids`, `shared_role_ids`); viewers still run under their own access |
| 23 | Save-view step "2. Widget": "Do you want to create a widget?" (`isWidgetRequired`), widget description, chart type (`widgetId`), group by column (`groupByColumn`) + label (`groupByLabel` "Label A"), aggregation rows (`aggregationColumns`: count label, aggregation column, "of" column, UOM "Work Items"), sort (`orderByClause`, `sortByOrder`, value), dashboard (`dashboardId`) | Turn the view into a dashboard widget in one go | Report visualization (bar / line / pie / KPI) + dashboard builder picks shared reports | PARTIAL | AN-15: "Add to dashboard" action on a report (choose board, widget type) |
| 24 | "Widget Created Successfully! — Create More Widgets / Go to Dashboard"; "View Saved Successfully! — Create More Views / Go to HR Desk" | Confirmations | Filament notifications | HAVE | — |
| 25 | Report details tabs "Report" / "Widget" | Table and its widget | Report page shows table + chart | HAVE | — |
| 26 | "Analyse by OneAI" + consent dialog ("Use One AI to analyze this report?", what it means, Accept and Continue) | AI summary of the report data | AI assistants exist (`ai.use`, `ai.hr`); no "analyse this report" | MISSING | AN-19 (optional): send the aggregated (suppressed) result, never row-level personal data, to the AI provider behind the existing AI controls; consent text from config |
| 27 | "Customise your own report": download the system report, edit columns / sequence, upload template (.xls/.xlsx, 5 MB) | Custom column layout from a spreadsheet | Column choice and order in the builder | PARTIAL | — (builder covers it; no upload) |
| 28 | Report format (`app-report-format`): Additional Columns (column name, column value), "Column Sequence — Arrange the order of selected columns", Tags, Dynamic Columns, Fixed Columns | Add constant columns and reorder | Calculated fields (`FormulaEngine`) and field order | PARTIAL | AN-16: constant-value calculated field (text) |
| 29 | Saved report editor tabs (`app-saved-reports`): Filters, Columns, Schedule, Widget, visibility, Preview; "Showing filters / widgets for" | Edit a saved view | Edit report + Schedules tab | HAVE | — |
| 30 | Delete saved view: "This widget would be removed from all the dashboards using it" / YES, INACTIVATE | Safe delete | Dashboards report per-widget errors instead of failing when a report is gone | PARTIAL | AN-15: warn which boards use a report before delete |
| 31 | Custom SQL report (`app-add-custom-report`): Report Name (`dynamicReportName`), Description, Module (`moduleId`), Visible To (`employeeVisibleTo`), Access Type (`dynamicReportAccessType`: Module access right / Team access right), Query type (`sqlOption`: SQL Query / Stored Procedure), SELECT / FROM / WHERE / ORDER BY, VALIDATE, "Download Sample Procedure", "Report Name Already Exists" | Admin-written SQL reports | — | N-A (by design) | Tenant-written SQL breaks fail-closed tenancy and field-level sensitivity; the dataset builder is the PeopleOS answer. Access type "team access right" is covered by datasets running in the viewer's `AccessScope` |
| 32 | Custom report filters: "Do you want to add OU filters?" (`isOUFilterApplied`), filter rows S.no, Display Name, Default Value, Show, Type | Parameters of the SQL report | Builder filters | N-A (with row 31) | — |
| 33 | Password protected reports (Governance; `app-password-protected-reports`): "Do you want to password protect the reports?" Yes / No; "Select reports to set lock on": search report, Module, Report name, Set lock on | Force a password on chosen reports | — | MISSING | AN-20: setting `analytics.protected_reports` (multiselect of dataset / template keys) — files of those reports are password protected (gap register CF-19) |
| 34 | Global search report results (`app-global-search-modulewise-reports`): Reports, History, Report name, Download / Open, `.pdf` / `.xlsx` | Open a report from the top search | Global search (`CommandSearch`) covers pages and records | PARTIAL | AN-16: index library templates and saved reports in `CommandSearch` |
| 35 | Report view from search (`app-global-search-report-view`): "No View Found — Create View", "No Record Found! — Edit Filter", "You do not have access for reports" | — | Report pages; permission `analytics.reports` | HAVE | — |
| 36 | Scheduling flag per catalogue report (`sched=True` only for Accommodation details) | Only some reports can be scheduled | Any saved report can be scheduled | HAVE | — |
| 37 | Captured tabs "All reports" / "Recent Reports" / "Favorite Reports" (on `/app/processes/reports`, `/search` and the Team reports pages) | Recently run and starred reports per user | Reports list sorted by update; no recents, no favourites | MISSING | AN-16: `report_favourites` (user, template key or report id) + "Recent" from the user's `ReportRun` rows; tabs on `ReportLibraryPage` |
| 38 | Module cards with counts (captured: Workforce 51, Time Office 44, Payroll 64, Performance 24, Expense 12, Helpdesk 2, Asset 3, Recruitment 17, Engage 12, Project Management 1, "Custom reports 0") | Catalogue size per module, custom reports as their own module | Library categories (no counts) | PARTIAL | AN-16: counts per category + a "My reports" category for saved custom reports |
| 39 | Output badge per report (`.xlsx` / `.pdf`) | Shows the download format | CSV only | PARTIAL | AN-17 |
| 40 | Team reports (`/app/team/team-reports`, API `/api/core/report/Count/M` vs `/Count/A` for HR) | Same catalogue limited to the manager's team | Rated in `20-requests-inbox-team.md` §20.3.4 (RQ-11 `team_safe` templates, `analytics.team`) | MISSING | RQ-11 (module 20); AN-16 adds the `team_safe` flag to every template rated HAVE above whose dataset is scoped by `AccessScope` |

### 21.2.2 Report catalogue mapped to the PeopleOS report library

Source: `hrone-feature-inventory.md` §7 (230 reports; HROne's own one-line description is condensed in column 3; `E` =
Excel, `P` = PDF output). PeopleOS: template keys are `ReportLibrary` keys, datasets are `DatasetRegistry` keys,
"Compliance" = Compliance → Compliance reports (`app/Domain/ComplianceReports/Builders/*`). Rating rule: **HAVE** when a
template, or a dataset with one filter, gives the same columns and filters; **PARTIAL** when the data is recorded but no
dataset / template exposes it (or key columns are missing); **MISSING** when the data is not recorded.

#### Asset (3)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Asset acknowledgement report (E) | Status of employees' acknowledgement of assets handed to them | `asset_assignments.acknowledged_at` and acknowledgement reminders exist (AS-A); no dataset or template yet (corrected 2026-10-29, see 26) | PARTIAL | AN-21: `asset_assignments.acknowledged_at` (+ employee action "Acknowledge" on My assets) and dataset `asset_assignments` with template `asset_acknowledgements` |
| 2 | Asset history report (E) | Full history of each asset | `AssetMovement`, `AssetAssignment`, `AssetRepair`, `AssetDisposal` recorded; dataset `assets` is current state only | PARTIAL | AN-21: dataset `asset_history` (event, date, asset, from / to custodian or location, cost) + template |
| 3 | Hardware inventory report (E) | Inventory by asset type | `assets` + template `assets_by_category`, `assets_assigned` | HAVE | — |

#### Engage (12)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Announcement read status (E) | Who read / did not read an announcement | `AnnouncementRead` recorded; no dataset | PARTIAL | AN-22: dataset `announcement_reads` (announcement, audience member, read_at, acknowledged) + template "Announcement read status" |
| 2 | Badges ledger (E) | Badges received | `badges_ledger_this_month`, `badges_by_employee` | HAVE | — |
| 3 | Event attendance report (E) | Who nominated themselves for an event and who attended | No engage events (gap register EN events) | MISSING | With the engage events build; dataset `event_participants` |
| 4 | Event details report (E) | Events attended per employee | — | MISSING | As row 3 |
| 5 | HR handbook acknowledgement report (E) | Handbook acknowledgement status | `ArticleRead` + `PolicyAcknowledgementTaskSource` (HP-9a); no dataset | PARTIAL | AN-22: dataset `handbook_acknowledgements` (article, version, employee, acknowledged_at, due) |
| 6 | Happiness index details (E) | Each employee's mood with improvement / going-good areas | `happiness_index` dataset (suppressed aggregates) + template `happiness_index_trend`; identified check-ins on the page only | PARTIAL | Owner decision (privacy): keep aggregates; add identified-only rows behind `engagement.confidential_identity` |
| 7 | Poll detail report (E) | Responses to a poll | No polls (social wall has posts, comments, reactions) | MISSING | With a poll post type on the social wall (HP-9e follow-up) |
| 8 | Post-Event Feedback report (E) | Feedback after an event | Surveys of type `event` (Event feedback) with `SurveyResults`; no export dataset | PARTIAL | AN-22: dataset `survey_answers` (non-anonymous surveys row-level; anonymous only aggregated) |
| 9 | Pre-Event Feedback report (E) | Feedback before an event | As row 8 | PARTIAL | As row 8 |
| 10 | Redemption ledger (E) | Reward points redeemed | `reward_points_redeemed` | HAVE | — |
| 11 | Reward points ledger (E) | Reward points received | `reward_points_this_month` | HAVE | — |
| 12 | Survey details (E) | All responses to a survey | `SurveyResults` page (per question, comments, trend); no download | PARTIAL | AN-22 (`survey_answers`, with anonymity rules) |

#### Expense (12)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Accommodation details (E, schedulable) | Every accommodation ticket | `accommodation_details` (dataset `travel_request_legs`) | HAVE | — |
| 2 | Expense advance (E) | All advance requests | `expense_advance_amount` | HAVE | — |
| 3 | Expense advance recovery register (E) | Advance recoveries | `expense_advance_recovery_register` | HAVE | — |
| 4 | Expense live trip (E) | Trips in progress with expenses | `live_trip` | HAVE | — |
| 5 | Expense payment register (E) | Expense payments due in a pay cycle | `expense_payment_register` | HAVE | — |
| 6 | Expense receipt by amount (E) | Amount-type receipts | `expense_receipts_by_amount` | HAVE | — |
| 7 | Expense receipt by mileage (E) | Mileage-type receipts | `expense_receipts_by_mileage` | HAVE | — |
| 8 | Expense receipt details (E) | Both receipt types together | `expense_receipt_details` | HAVE | — |
| 9 | Expense report details (E) | Reported, paid, adjusted per report | `expense_report_details` | HAVE | — |
| 10 | Outstanding expense advance (E) | Advances not yet settled | `outstanding_expense_advances` | HAVE | — |
| 11 | Travel details (E) | Every travel ticket | `travel_details` | HAVE | — |
| 12 | Travel summary (E) | Trips open / pending / closed / booked / cancelled | `travel_summary` | HAVE | — |

#### Helpdesk (2)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Ticket details by requestee (E) | Tickets with the raiser's details, within data access | `servicedesk` dataset: number, service, category, `employee_code`, priority, status, agent, raised on, resolution hours, breached, satisfaction, root cause; no requester name / department; template `sla_breaches` | PARTIAL | AN-23: add `Dataset::employeeFields()` (name, company, location, department, designation) for the requester + template `tickets_by_requestee` (gap register HD-01) |
| 2 | Ticket details by resolver (E) | Tickets with the assignee's details | `agent` only | PARTIAL | AN-23: agent code / team / first response / resolved at + template `tickets_by_resolver` |

#### Payroll (64)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Arrear register (E) | Arrears paid | `arrears_this_fy` (`payroll_adjustments` type arrear) | HAVE | — |
| 2 | Bonus Form C (P) | Statutory bonus register for a FY | Compliance `BonusRegister`; the BONUS rule awaits the owner | PARTIAL | Blocked on the BONUS rule (PY-19); no library template (statutory forms stay in Compliance) |
| 3 | Bonus Form D (P) | Annual bonus return | — | MISSING | Compliance builder `BonusAnnualReturn` after PY-19 |
| 4 | Bonus liability report (E) | Bonus accrued but not yet processed | — (bonus not processed) | MISSING | After PY-19: dataset `bonus_accruals` |
| 5 | Bonus register (E) | Statutory bonus and ex-gratia per employee | Compliance `BonusRegister` | PARTIAL | As row 2 |
| 6 | CTC Revision (E) | When each salary was revised | `salary_revisions_this_fy` | HAVE | — |
| 7 | CTC Revision (Pivoted) (E) | CTC progression across a FY, one column per revision | `salary_revisions` rows; the builder cannot pivot | PARTIAL | AN-24: pivot visualization (rows employee, columns month / effective date, value) in `ReportRunner` |
| 8 | Current CTC (salary structure) (E) | Monthly and annual breakup of CTC by component | `ctc_register` gives totals (annual, monthly, fixed, variable), not component lines | PARTIAL | AN-24: dataset `ctc_components` (employee × structure component: monthly, annual) from `CompensationOutput` |
| 9 | Current CTC summary (E) | Current CTC summary across pay groups | `ctc_by_department`; pay groups not built (BP-12) | PARTIAL | After BP-12: group by pay group |
| 10 | EPF Form 11 (P) | PF / EPS declaration of a joiner | — | MISSING | AN-25: printable Form 11 from statutory details + previous-employment answers (form content from verified rule data, not hard-coded) |
| 11 | ESI Form 1 (P) | ESI joiner declaration | — | MISSING | AN-25, as row 10 (family particulars from `family_members`) |
| 12 | ESI (employee state insurance) (E) | Employee and employer ESI with wages | `esi_register_last_month`; Compliance `EsiChallan`, `EsiContributionPeriod` | HAVE | — |
| 13 | Effective PF Wages Report (E) | PF wages after the wage-basis rule per employee | `payroll` has basic, pf_employee / employer; PF wage basis recorded (`pf_options`, HP-3 PY-15); no "PF wages" field | PARTIAL | AN-24: `payroll.pf_wages` (the wage the engine used, from the entry's basis) + template |
| 14 | Effective PF Wages for new joiner (E) | Same, joiners only | As row 13 | PARTIAL | Template filter joining date in period |
| 15 | Employee statutory details (E) | PAN, UAN, PF, ESI … per employee | `statutory_bank_register` (masked) | HAVE | — |
| 16 | Form 22 (P) | State wage-cum-attendance register (day-wise attendance, payable days, wages, deductions) | Compliance `CombinedMusterWages` | PARTIAL | State variant selection is the compliance catalogue's job; no library template |
| 17 | Gratuity Form F (P) | Gratuity nomination | Nominees in `family_members` (`nominees` template); no form | PARTIAL | AN-25: printable Form F |
| 18 | Gratuity paid (E) | Gratuity paid at exit | `final_settlements` (`gratuity` column; `fnf_register`) | HAVE | Template filter gratuity > 0 |
| 19 | Gratuity provisioning month (E) | Gratuity liability up to a month | Compliance `GratuityRegister` (accrual); no month-end liability report (PY-20) | PARTIAL | After PY-20: dataset `gratuity_provisions` |
| 20 | Hold salary release details (E) | Salaries held and later released | `SalaryHold` recorded; no dataset | PARTIAL | AN-24: dataset `salary_holds` (period, held on, reason, released on, released in run, amount) + templates "Salary on hold", "Hold salary release" |
| 21 | Investment 80C (E) | 80C declarations | `tax_investments` (section, item, declared, proof, status) filter section 80C; `section_wise_declarations` | HAVE | — |
| 22 | Investment 80D (E) | 80D declarations | As row 21 (section 80D) | HAVE | — |
| 23 | Investment HRA (E) | HRA declarations (rent, landlord) | `tax_investments` rows for HRA; landlord name / PAN / rent months not in the dataset | PARTIAL | AN-24: HRA columns (landlord, PAN present, monthly rent, period) |
| 24 | Investment declaration details (E) | 80C, other sections and other income together | `investment_declarations` + `tax_investments` | HAVE | — |
| 25 | Investment income other source (E) | Other income declared | `tax_investments` / `tax_income_entries` (other income heads, HP-3) | HAVE | — |
| 26 | Investment not submitted (E) | Employees with no declaration | `tax_declarations` lists only existing declarations | PARTIAL | AN-24: template over a dataset field `has_declaration` (current FY) on `employees` / `statutory_bank` |
| 27 | Investment other than 80C (E) | Non-80C declarations | `tax_investments` filter section ≠ 80C | HAVE | — |
| 28 | LTA liability report (E) | Unclaimed LTA per employee per FY | LTA not built (PY-04) | MISSING | After PY-04 |
| 29 | LWF (labour welfare fund) (E) | Employee and employer LWF | `lwf_register_this_fy`; Compliance `LabourWelfareFundStatement` | HAVE | — |
| 30 | Leave encashment (E) | Leave encashed across a FY (days and amount) | `leave_encashments` (days, decision); amount in payroll / F&F lines | PARTIAL | AN-24: amount and paid-in-run columns on `leave_encashments` |
| 31 | Leave encashment liability (E) | Value of encashable leave not yet processed | — | MISSING | AN-24: dataset field valuing `available` encashable days at the policy's rate (formula from the leave type's encashment rule, not hard-coded) |
| 32 | Loan ledger report (E) | Month-wise EMI transactions | `Loan`, `LoanInstallment` (BP-9); no dataset | PARTIAL → built (AN-26: dataset `loan_installments`, template `loan_ledger`) | AN-26: datasets `loans`, `loan_installments` + templates "Loan ledger", "Loan request", "Loan summary" (gap register PY-31) |
| 33 | Loan request report (E) | Loan requests | As row 32 | PARTIAL → built (AN-26: dataset `loans`, template `loan_requests`) | AN-26 |
| 34 | Loan summary (E) | Dispensed loans and status | As row 32 | PARTIAL → built (AN-26: dataset `loans`, template `loan_summary`) | AN-26 |
| 35 | Monthly TDS deduction details (E) | TDS in a month | `tds_register_this_fy` | HAVE | — |
| 36 | NPS employer contribution (E) | Employer NPS to deposit | NPS classified components (HP-3 PY-16) in `payroll_components` | PARTIAL | AN-24: template on `payroll_components` filtered to the NPS employer classification (needs `classification` on the dataset) |
| 37 | Negative salary report (E) | Employees whose net pay went negative | `payroll.net_pay`; behaviour of the engine on negative net to confirm | PARTIAL | AN-24: template `negative_net_pay` (net_pay < 0) or N-A if the engine carries deductions forward |
| 38 | New join statutory declaration report (E) | Joiners who declared their statutory details | `statutory_bank` (has PAN / UAN / ESI); no "declared by the employee" flag | PARTIAL | AN-24: `declared_on` from the onboarding task that captured it |
| 39 | Out of salary (E) | Payments made outside the system | `income_outside_payroll` | HAVE | — |
| 40 | Override audit report (E) | Changes to system-calculated statutory values | `statutory_override_audit` | HAVE | — |
| 41 | Overtime (E) | OT hours and OT amount for a month | Hours in `overtime_this_month`; amount in `payroll_components` | PARTIAL | AN-24: template joining hours and the OT component amount (OT component classification) |
| 42 | PF (Provident fund) (E) | Employee / employer PF and VPF with wages | `pf_register_last_month`, `vpf_register`; Compliance `PfChallan` | HAVE | — |
| 43 | PT (Professional tax) (E) | PT deducted | `pt_register_last_month`; Compliance `ProfessionalTaxStatement` | HAVE | — |
| 44 | Payroll digital signature status report (E) | E-signature status of payroll documents | No e-signing (SV-17) | MISSING | After SV-17 |
| 45 | Payroll generated letters (E) | Payroll letters generated | `letters` dataset (type filter: increment, salary certificate …) | HAVE | — |
| 46 | Perquisite (E) | Perquisites paid | `perquisite_register` | HAVE | — |
| 47 | Reimbursement audit (E) | Component-wise credit and balance of reimbursements across the FY | `ReimbursementLedgerEntry` (BP-9); no dataset | PARTIAL → built (AN-26: dataset `reimbursement_ledger`, template `reimbursement_audit`) | AN-26: datasets `reimbursement_ledger`, `reimbursement_claims` + templates audit / monthly ledger / request / summary |
| 48 | Reimbursement monthly ledger (E) | Per-employee component summary for a month | As row 47 | PARTIAL → built (AN-26: template `reimbursement_monthly_ledger`) | AN-26 |
| 49 | Reimbursement register (E) | Pay-on-claim reimbursements paid in a month | `reimbursements_this_fy` (`payroll_adjustments`) | HAVE | — |
| 50 | Reimbursement request (E) | Reimbursement requests | `ReimbursementClaim`; no dataset | PARTIAL → built (AN-26: dataset `reimbursement_claims`, template `reimbursement_requests`) | AN-26 |
| 51 | Reimbursement summary (E) | Component-level summary | As row 47 | PARTIAL → built (AN-26: template `reimbursement_summary`) | AN-26 |
| 52 | Release salary and reimbursement (E) | Bank details and net pay for selected employees | `bank_transfer_last_month` | HAVE | — |
| 53 | Salary YTD component wise (E) | Payments per component across the FY | `payroll_components` grouped by component (FY filter); per employee × component needs a pivot | PARTIAL | AN-24 pivot (row 7) |
| 54 | Salary on hold (E) | Employees whose salary is held | `SalaryHold`; no dataset | PARTIAL | AN-24 (row 20) |
| 55 | Salary register (E) | Every component per employee for a run | `salary_register_last_month` / `_this_month`, `component_register_last_month`; Compliance `WageRegister` | HAVE | — |
| 56 | Salary register (grouping) (E) | Salary register grouped by an org unit | `payroll_cost_by_department` / `_location` | HAVE | — |
| 57 | Salary unprocess audit (E) | Employees whose processed salary was undone | Finalized payroll is never reopened; recalculation before finalize is audited (`AuditEvent`) | PARTIAL | AN-24: dataset over payroll audit events (recalculated / excluded / cancelled before finalize) |
| 58 | Settlement payment report (E) | Settlement payments | `fnf_register`, `fnf_pending` | HAVE | — |
| 59 | TDS Computation (E) | TDS worksheet per employee for a FY | `TdsAnnualLedger` and the TDS projection; no dataset | PARTIAL | AN-24: dataset `tds_worksheets` (heads, exemptions, deductions, taxable, tax, cess, paid, balance) from the ledger |
| 60 | Variable paid (E) | Variable pay paid in a month | `VariablePayRequest` → payroll lines; no template | PARTIAL → built (AN-26: dataset `variable_pay_requests`, template `variable_paid`) | AN-26: datasets `variable_pay_plans`, `variable_pay_requests` + templates paid / plan / request |
| 61 | Variable pay plan (E) | Month-wise variable schedule per plan | `VariablePayPlan` (BP-9); no dataset | PARTIAL → built (AN-26: dataset `variable_pay_plans` (one row per plan, the month-wise schedule as month: target), template `variable_pay_plan`; target against payout per month in `variable_pay_requests.plan_target`) | AN-26 |
| 62 | Variable request (E) | Variable requested for a month | `VariablePayRequest`; no dataset | PARTIAL → built (AN-26: template `variable_requests`) | AN-26 |
| 63 | YTD salary register (E) | FY-to-date components per employee | `ytd_salary_summary` | HAVE | — |
| 64 | Yearly TDS deduction details (E) | TDS for the year | `tds_by_employee`; Compliance `TdsStatement` | HAVE | — |

#### Performance (24)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | 1 on 1 details (E) | One-on-ones requested by employees | `OneOnOne`, `OneOnOneFeedback`; no dataset | PARTIAL → built (AN-27: dataset `one_on_ones`, template `one_on_one_details`) | AN-27: dataset `one_on_ones` (employee, with, date, agenda, status, follow-ups) + template |
| 2 | 360 Nomination status (E) | Approval status of 360 nominations | `FeedbackRequest` exists; nomination approval not built (HP-8h / SV-12) | MISSING → built (AN-27: dataset `feedback_360_status` (HP-8h nomination sets), template `feedback_360_nominations`) | After SV-12: dataset `feedback_nominations` |
| 3 | 360 feedback details (E) | Feedback given to an employee in 360 | `FeedbackRequest` / `FeedbackEntry`; no dataset | PARTIAL → built (AN-27: dataset `feedback_360_results` — aggregates per employee and source only (asked, answered, declined, average score; anonymous sources from the policy minimum), never a reviewer or a single answer; template `feedback_360_details`) | AN-27: dataset `feedback_responses` (subject, relationship, giver hidden when anonymous, ratings, completed on) |
| 4 | 360 feedback status (E) | Status and type of 360 feedback | As row 3 | PARTIAL → built (AN-27: dataset `feedback_360_status`, template `feedback_360_status`) | AN-27: `feedback_requests` status template |
| 5 | Company checkin report (E) | Daily / weekly / monthly check-ins across the company | `GoalCheckIn`, `PerformanceCheckIn`; no dataset | PARTIAL → built (AN-27: dataset `check_ins` (goal check-ins at every level), template `company_check_ins`; conversations in `performance_check_ins` / `check_in_conversations`) | AN-27: dataset `check_ins` (employee, goal, date, progress, note, level company / team / employee) |
| 6 | Continuous feedback report (E) | Feedback given in the system | `FeedbackEntry`, `EmployeeFeedback`; no dataset | PARTIAL → built (AN-27: dataset `continuous_feedback` (performance.view; anonymous givers read "Anonymous"), template `continuous_feedback`) | AN-27 (with `feedback.view`) |
| 7 | Employee goal checkin (E) | Check-ins by employee | As row 5 | PARTIAL → built (AN-27: template `employee_goal_check_ins`) | AN-27 |
| 8 | Goal details (E) | Last approved goals per cycle | `goals_progress`, `goals` dataset | HAVE | — |
| 9 | Goal status (E) | Employees whose goals are not created or not approved | `GoalPlan` status; `goals` has no plan status, no rows for people without goals | PARTIAL → built (AN-27: dataset `goal_plans` (goal sheets incl. not created), template `goal_sheet_status`) | AN-27: dataset `goal_plans` (employee, cycle, status, submitted, approved) incl. employees with no plan |
| 10 | Goals not created (E) | Employees without goals | As row 9 | PARTIAL → built (AN-27: template `goals_not_created`) | AN-27 template filter status = none |
| 11 | Initiative details (E) | Initiatives per employee | Dataset `initiatives` + template "Initiative details" (HP-8j) | HAVE | After HP-8j |
| 12 | PIP evaluation (E) | PIP evaluations initiated | `ImprovementPlanCheckpoint`; `improvement_plans` has no checkpoint rows | PARTIAL → built (AN-27: dataset `pip_checkpoints` (performance.pip; the screen's plans only), template `pip_evaluations`) | AN-27: dataset `pip_checkpoints` (no reasons or objectives text, as today) |
| 13 | PIP request (E) | PIPs initiated | `active_pips` (`improvement_plans`) | HAVE | — |
| 14 | Performance digital signature status report (E) | E-sign status of performance letters | No e-signing (SV-17) | MISSING | After SV-17 |
| 15 | Performance feedback details (R) | Feedback written during the review | `AppraisalReview` comments; no dataset | PARTIAL → built (AN-27: dataset `appraisal_reviews` (text with performance.view), template `performance_feedback_details`) | AN-27: dataset `appraisal_reviews` (reviewer level, rating, comment presence; text only with `performance.view`) |
| 16 | Performance generated letters (E) | Performance letters generated | `letters` dataset filtered to promotion / increment / appreciation / PIP types | HAVE | — |
| 17 | Performance review details (E) | Final rating per KPI and competency for a cycle | `AppraisalRating`; `performance` dataset has goal / competency score totals only | PARTIAL → built (AN-27: dataset `appraisal_ratings` (`is_final_level`), template `performance_review_details`) | AN-27: dataset `appraisal_ratings` (appraisal, item type KPI / competency, item, weight, self, manager, final) |
| 18 | Performance review details by approver (E) | KPI-level ratings by every reviewer in the workflow | As row 17 (per reviewer) | PARTIAL → built (AN-27: template `performance_review_details_by_approver`) | AN-27 (reviewer column) |
| 19 | Performance review rating (E) | Review approval status and rating | `performance` dataset (status, final rating, label) | HAVE | — |
| 20 | Performance review rating by approver (E) | Ratings given by each approver | `self_rating`, `manager_rating`; multi-level reviewers not in the dataset | PARTIAL → built (AN-27: template `performance_review_rating_by_approver`) | AN-27 (`appraisal_reviews`) |
| 21 | Performance review rating by approver (Pivoted) (E) | Same, one column per approver | As row 20 + pivot | PARTIAL | AN-24 pivot |
| 22 | Performance summary (E) | Rating per employee for a cycle | `performance` dataset; `rating_distribution` | HAVE | — |
| 23 | TNI details (E) | Training recommended to employees | `DevelopmentNeed`; no dataset | PARTIAL → built (AN-27: dataset `development_needs`, template `tni_details`) | AN-27: dataset `development_needs` |
| 24 | Team checkin report (E) | Team-level check-ins | As row 5 | PARTIAL → built (AN-27: template `team_check_ins`) | AN-27 |

#### Project Management (1)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Projects detail (E) | Project details | No projects module | MISSING | PM-9: dataset `projects` (+ `project_tasks`, `timesheet_entries`, `project_invoices`) and templates |

#### Recruitment (17)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Candidate details (E) | Candidates created | — | N-A (recruitment, separate RMS) | — |
| 2 | Candidate document details (E) | Candidate document upload status | — | N-A (recruitment) | — |
| 3 | Candidate eKYC Field Status (E) | Candidate KYC fields | — | N-A (recruitment) | — |
| 4 | Candidate eKYC Summary (E) | Candidate KYC status | — | N-A (recruitment) | — |
| 5 | Internal job posting report (E) | IJP postings | — | N-A (recruitment) | — |
| 6 | Interview feedback details (E) | Interview feedback by template | — | N-A (recruitment) | — |
| 7 | Interview feedback summary (E) | Interviews per candidate | — | N-A (recruitment) | — |
| 8 | Job requisition approval by level (E) | Requisition approvals by level | — | N-A (recruitment) | — |
| 9 | Job requisition summary (E) | Requisition journey | — | N-A (recruitment) | — |
| 10 | Offer letter (E) | Offer letters generated | — | N-A (recruitment) | — |
| 11 | Offer letter approval by level (E) | Offer approvals by level | — | N-A (recruitment) | — |
| 12 | Offer letter details (E) | Offered CTC breakup | — | N-A (recruitment) | — |
| 13 | Position wise candidate details (E) | Candidates per position | — | N-A (recruitment) | — |
| 14 | Recruiter summary (E) | Recruiter work per requisition | — | N-A (recruitment) | — |
| 15 | Recruitment budget details (E) | Hiring vs planned requisitions | — | N-A (recruitment) | — |
| 16 | Recruitment digital signature status report (E) | E-sign status of recruitment documents | — | N-A (recruitment) | — |
| 17 | Referral report (E) | Referrals | — | N-A (recruitment) | — |

#### Time Office (44)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Approved geo location (E) | Approved employee geo locations | `GeoFence`, `LocationRequest` (HP-1 TO-16); no dataset | PARTIAL | AN-28: datasets `geo_locations`, `location_requests` + templates |
| 2 | Arrear attendance status days (E) | Attendance days changed after lock | `attendance_arrear_days` (status before / after) | HAVE | — |
| 3 | Arrear calculation (E) | Money effect of attendance arrears | Arrear quantities in `attendance_arrears`; payment in `payroll_adjustments` (arrear) | PARTIAL | AN-28: link arrear days to the adjustment amount (basis reference) in one template |
| 4 | Arrear days (E) | Arrear days per employee | `attendance_arrear_days` | HAVE | — |
| 5 | Attendance penalty detail (E) | Attendance penalties | `late_penalties_month`, `late_penalty_days_by_department` | HAVE | — |
| 6 | Attendance reconciliation (E) | Time-office requests raised day to day | `attendance_reconciliation` | HAVE | — |
| 7 | Attendance register form 25 (E) | Day-wise status + summary in Form 25 layout | Compliance `MusterRoll` (state forms) | PARTIAL | Form 25 variant via the compliance catalogue (gap register TO-22) |
| 8 | Attendance regularization request (E) | AR requests | `regularisations_this_month`, `regularisations_pending` | HAVE | — |
| 9 | Biometric punches details (E) | Biometric punches | `attendance_punches` (source, device); `punch_log_today` | HAVE | — |
| 10 | Bulk Attendance Slip (P) | Attendance slips of many employees in one PDF | Printable attendance slip per employee (HP-1 TO-20) | PARTIAL | AN-28: bulk slip (queued, one PDF / zip) |
| 11 | Clocking time (E) | Raw punches per day | `punch_log_today` (`attendance_punches`) | HAVE | — |
| 12 | Compensatory off request (E) | Comp-off requests | `comp_off_requests` | HAVE | — |
| 13 | Daily attendance (E) | Daily attendance of everyone | `attendance_today`, `attendance_yesterday` | HAVE | — |
| 14 | Daily check In (E) | Raw check-ins per day | `attendance_punches` | HAVE | — |
| 15 | Geo location request (E) | Geo location requests | `LocationRequest`; no dataset | PARTIAL | AN-28 |
| 16 | Inconsistent punch report (E) | Days with punches but status Absent (full or half) | `attendance` (status, first_in, last_out, missed_punch) filter status absent + first_in not empty | HAVE | Template `inconsistent_punches` for convenience |
| 17 | Late and early go (E) | Late arrivals and early departures | `late_coming_this_month`, `early_leaving_this_month`, `late_summary_this_month` | HAVE | — |
| 18 | Late deduction by duration (E) | Late duration and the deduction applied at processing | Penalties are count-based (`attendance_penalties`); no duration-based deduction | PARTIAL | With a duration-based late rule (time-office depth SV-5) |
| 19 | Leave Balance Summary (Pivoted) (E) | Month-wise balance per leave type, pivoted | `leave_ledger` movements; no month-end balance; no pivot | PARTIAL | AN-28: dataset `leave_month_balances` (month-end balance from the ledger) + AN-24 pivot |
| 20 | Leave Balance Summary (Pivoted) V2 (E) | Same, newer layout | As row 19 | PARTIAL | As row 19 |
| 21 | Leave balance (E) | Current balances | `leave_balance` | HAVE | — |
| 22 | Leave balance summary (E) | Month-wise balance summary | As row 19 | PARTIAL | As row 19 |
| 23 | Leave carry forward (E) | Balances carried forward | `leave_ledger` type `carry_forward` | HAVE | Template for convenience |
| 24 | Leave credit report (E) | Leave credits | `leave_ledger` (credits); `comp_off_credits` | HAVE | — |
| 25 | Leave donation report (E) | Leave donated | `leave_donations_register` | HAVE | — |
| 26 | Leave encashment request (E) | Encashment requests | `leave_encashments` | HAVE | — |
| 27 | Leave ledger (E) | Leave ledger | `leave_ledger` | HAVE | — |
| 28 | Leave monthly balance (E) | Monthly balances | As row 19 | PARTIAL | As row 19 |
| 29 | Leave request (E) | Leave requests | `leave` dataset (`leave_this_month`, `pending_leave_approvals`, …) | HAVE | — |
| 30 | Mobile check in request (E) | Mobile check-ins (and their approval) | `attendance_punches` source mobile; approval of out-of-fence punches recorded on the punch / location request | PARTIAL | AN-28: template over punches source = mobile with status |
| 31 | Monthly attendance (E) | Monthly attendance per employee | `monthly_summary_this_month` / `_last_month` | HAVE | — |
| 32 | Monthly attendance register (E) | Day-by-day status grid for the month | Compliance `MusterRoll`; library has no employee × day pivot | PARTIAL | AN-24 pivot over `attendance` (rows employee, columns day, value status code) |
| 33 | Monthly deductions (E) | Attendance-driven deductions in the month | `lop_days_last_month` (payroll), penalties | PARTIAL | AN-28: template joining LOP, half-day and penalty days per employee per month |
| 34 | Monthly overtime detail (E) | Monthly OT | `overtime_this_month` | HAVE | — |
| 35 | Monthly paid day (E) | Paid days per employee | `payroll` paid_days / lop_days (`lop_days_last_month`) | HAVE | — |
| 36 | Monthly shift roster (E) | Day-wise shift roster | `shift_roster` (schedule spans); day overrides (`ShiftOverride`) not in a dataset; no pivot | PARTIAL | AN-28: dataset `day_roster` (employee, date, shift, source) + AN-24 pivot |
| 37 | Monthly working hour (E) | Working hours per employee | `work_hours_this_month` | HAVE | — |
| 38 | On duty request (E) | OD requests | `on_duty_requests` | HAVE | — |
| 39 | Overtime register (E) | OT of all employees | `overtime_this_month`, `overtime_by_department` | HAVE | — |
| 40 | Overtime request (E) | OT requests | `overtime_claims` | HAVE | — |
| 41 | Paid day YTD (E) | Paid days per month, FY to date | `payroll` grouped by employee (sum paid_days, FY filter) | HAVE | — |
| 42 | Restricted holiday request (E) | RH requests | `restricted_holiday_requests` | HAVE | — |
| 43 | Shift change history (E) | Shift changes per employee | `shift_roster` (all spans when `is_current` is not filtered); `shift_change_requests` | HAVE | — |
| 44 | Short leave request (E) | Short leave requests | `short_leave_requests` | HAVE | — |

#### Workforce (51)

| # | HROne report | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Absconding report (E) | Employees marked absconding | `exits` (type absconding) | HAVE | — |
| 2 | Bank details (E) | Employee bank accounts | `statutory_bank_register` (masked) | HAVE | — |
| 3 | Business partners Report (E) | Business partners (external users) | Partner type partly modelled (gap register IP-04) | PARTIAL | With IP-04: dataset field / filter `employee_category = business_partner` |
| 4 | Clearance pdf (P) | Clearance status PDF for completed clearances | `exit_clearances`; no PDF | PARTIAL | AN-29: printable no-dues certificate per exit case |
| 5 | Clearance task (E) | Clearance tasks of initiated exits | `clearances_pending` (`exit_clearances`) | HAVE | — |
| 6 | Confirmation feedback (E) | Confirmation feedback answers | `ConfirmationReview` + feedback templates (WF-1, P13); no dataset | PARTIAL | AN-29: dataset `confirmation_reviews` (+ answers for `employee.view` holders) |
| 7 | Confirmation status (E) | Confirmation process status | `probation_due`, `probation_overdue`, `confirmations_this_month` (employee dates); review status not in a dataset | PARTIAL | AN-29 (`confirmation_reviews`) |
| 8 | Contract details (E) | Employees on contract | `employees` (employment type); contract end / renewal not recorded (BP-16) | PARTIAL | After BP-16: contract dates on the dataset |
| 9 | Do transfer (E) | Final transfer status and allowance | `TransferRequest` + transfer allowances (WF-2, WF-3); `transfers` template shows completed moves only | PARTIAL | AN-29: dataset `transfer_requests` (status, from / to, effective, allowance) |
| 10 | Education details (E) | Qualifications | `qualifications` | HAVE | — |
| 11 | Employee details (E) | All employee information in one view | `employee_master` (`employees` dataset, 50+ fields) | HAVE | — |
| 12 | Employee document details (E) | Documents uploaded / not uploaded | `employee_documents`, `documents_missing`, `documents_missing_joiners` | HAVE | — |
| 13 | Employee eKYC Summary (E) | e-KYC verification status | No e-KYC vendor (CA-15 blocked) | MISSING | After CA-15 |
| 14 | Employee login report (E) | Login history | `users` (last login, days since); `AuditEvent` LOGIN / LOGIN_FAILED | PARTIAL | AN-29: dataset `sign_ins` over audit events (user, time, result, channel; IP masked) |
| 15 | Employee other detail (E) | Dynamic (custom) fields | Custom fields not in datasets (gap register WF-09) | PARTIAL | AN-29: `Dataset::customFields(entity)` appends active custom fields of the entity (respecting field visibility) |
| 16 | Employee setting (E) | Settings / policies configured per employee | Policy assignments resolved per employee; no dataset | PARTIAL | AN-29: dataset `policy_assignments` (employee, policy type, policy, version, source rule) — also covers row 33 |
| 17 | Exit interview feedback (E) | Exit interview answers | `ExitInterview`; `ExitInsights` aggregates (`exit_reasons`) | PARTIAL | AN-29: dataset `exit_interviews` (answers for `exit.interview` holders) |
| 18 | Exit interview status (E) | Exits without a filled interview | `exits` (no interview flag) | PARTIAL | AN-29: `interview_status` on `exits` |
| 19 | Final clearance status (E) | Clearance status and recovery amount | `exit_clearances` (status, recoverable amount), `fnf_register` | HAVE | — |
| 20 | Headcount by sub branch (E) | Actual vs expected manpower per sub-branch | `WorkforceAnalyticsPage` seats / occupied / vacant by group; sub-branch level not a dimension | PARTIAL | With CA-2 sub-branch master: group by sub-branch |
| 21 | Health insurance (E) | Employees' declared health insurance | Not recorded (gap register WF-10) | MISSING | With WF-10 |
| 22 | Inbox pending request summary (E) | Pending tasks per task owner | `WorkflowTask` / approvals; no dataset | PARTIAL | AN-30: dataset `pending_tasks` (owner, type, employee, assigned, due, overdue) + grouped template |
| 23 | Inbox pending task details (E) | Every pending task | As row 22 | PARTIAL | AN-30 |
| 24 | Induction evaluation report (E) | Induction evaluations | Induction (P13 / WF-3) records; no dataset | PARTIAL | AN-29: dataset `induction_plans` (+ evaluation) |
| 25 | Induction plan (E) | Induction plans of joiners | As row 24 | PARTIAL | AN-29 |
| 26 | Joining status (E) | Joining status of upcoming and new joiners | `upcoming_joiners`, `onboarding_in_progress` | HAVE | — |
| 27 | Letter acknowledgement report (E) | Acknowledgement of published letters | `letters` (status); employee acknowledgement not recorded (gap register WF-01) | MISSING | With WF-01: `letters.acknowledged_at` |
| 28 | Login audit report (E) | Log on / log off audit | Audit trail screen (`audit.view`) lists LOGIN events | PARTIAL | AN-29 (`sign_ins`) |
| 29 | Nominee details (E) | Nominees | `nominees` | HAVE | — |
| 30 | Other dependant (E) | Dependants | `dependants` | HAVE | — |
| 31 | Personal (E) | PAN, Aadhaar, address … | `statutory_bank` (PAN masked, has Aadhaar), `employees` (personal contacts); addresses not in a dataset | PARTIAL | AN-29: address fields (sensitive) on a `personal_details` dataset behind `employee.sensitive.view` |
| 32 | Personal history (E) | History of personal details | Audit changes (`AuditEventChange`) | PARTIAL | AN-29: dataset over audited person changes (field, old → new masked, when, by) |
| 33 | Policy applicable report (E) | Applicable policies per employee | As row 16 | PARTIAL | AN-29 |
| 34 | Post transfer checklist (E) | Post-transfer task status | None: transfers have no task checklist (corrected 2026-10-29, see 23 §23.13) | MISSING | needs WO-10 transfer checklists, then AN-29 |
| 35 | Pre transfer checklist (E) | Pre-transfer task status | As row 34 | MISSING | needs WO-10, then AN-29 |
| 36 | Preboarding checklist (E) | Preboarding task status of upcoming joiners | `onboarding_tasks` (phase filter), `pending_joining_documents` | HAVE | — |
| 37 | Previous employer tax details (E) | Previous employer income and TDS | `tax_declarations` (previous_employer_income, previous_employer_tds) | HAVE | — |
| 38 | Resignation (E) | Resignations raised | `resignations_this_month`, `exits_this_fy` | HAVE | — |
| 39 | Resignation report by level (E) | Last working date as approved at each approval level | Workflow history per exit; not a dataset | PARTIAL | AN-30: dataset `approval_steps` (subject, level, approver, decision, value proposed) |
| 40 | Role wise details (E) | Which HR / admin is mapped to an employee for a role | Roles and access scopes (`users`: roles); employee-side mapping not a dataset | PARTIAL | AN-29: dataset `role_coverage` (employee, role, holder) from `AccessScope` (after SV-7 rule-based roles) |
| 41 | Shared letter/document report (E) | Status of letters / documents published in bulk | `letters`; bulk publish partial (WF-01) | PARTIAL | With WF-01 |
| 42 | Skill Report (E) | Skills mapped to employees | `EmployeeSkill`, `PersonSkill`; no dataset | PARTIAL | AN-29: dataset `employee_skills` |
| 43 | Snapshot (E) | Basic details | `employee_directory` | HAVE | — |
| 44 | Snapshot history (E) | History of basic details | `position_history` (`employee_movements`) | HAVE | — |
| 45 | Transfer request (E) | Transfer requests raised | As row 9 | PARTIAL | AN-29 |
| 46 | UUID details (E) | Registered mobile devices | `checkin_devices_register` | HAVE | — |
| 47 | Welcome note (E) | Manager's welcome-team task notes for joiners | No welcome-team task (module 20, RQ-10) | MISSING | After RQ-10 |
| 48 | Work experience (E) | Previous work experience | `PersonExperience`; no dataset | PARTIAL | AN-29: dataset `work_experience` |
| 49 | Workflow applicable report (E) | Applicable workflow per employee | Workflow start conditions; no per-employee resolution | PARTIAL | AN-30: dataset resolving each employee × trigger to the workflow that would start |
| 50 | Workforce digital signature status report (E) | E-sign status of workforce letters | No e-signing (SV-17) | MISSING | After SV-17 |
| 51 | Workforce generated letters (E) | Workforce letters generated | `letters_issued_this_month`, `letters_pending` | HAVE | — |

**21.2.2 catalogue summary** (230 reports): HAVE 93 · PARTIAL 100 · MISSING 20 · N-A 17 (recruitment). By module (H / P / M / N-A): Asset 1/1/1/0 · Engage 3/6/3/0 · Expense 12/0/0/0 · Helpdesk 0/2/0/0 · Payroll 25/32/7/0 · Performance 5/16/3/0 · Project Management 0/0/1/0 · Recruitment 0/0/0/17 · Time Office 30/14/0/0 · Workforce 17/29/5/0.

---

## 21.3 Inbox insights (HR Desk → Inbox Insights, `/app/processes/insights`; `app-insights-home`, `app-insight-table`, `app-insight-error`, `app-view-canvas`, `app-widgets-layout`)

The organisation-wide view of open inbox work for HR (the approver's own "Insight" panel inside the inbox is rated in
`20-requests-inbox-team.md` §20.2.1 #7, RQ-8). Captured page: tabs, module accordions, the task table; the rest from
components.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tab "Favourites" — "These tasks comprise a curated selection of the most frequently performed tasks categorized by module" | Open-task counts for the commonest task types | Approval Center groups pending items by source for the signed-in approver; `WorkflowInstances` resource lists instances; no organisation-wide count by task type | MISSING | AN-30: `InboxInsights` page (HR Desk; permission `workflow.view` + new `workflow.insights`), source = `ApprovalCenter` sources + `WorkflowTask` + `ExperienceTasks` across all owners in the viewer's scope |
| 2 | Tab "Overdue tasks" — "past their due date and are pending, requiring immediate attention …" | Overdue open tasks by type | Escalation engine acts on overdue tasks (`EscalationEngine`); no overview | MISSING | AN-30 tab: due_at < now |
| 3 | Tab "All pending tasks" — "These tasks have not yet been completed or resolved" | All open tasks by type | — | MISSING | AN-30 tab |
| 4 | Tab "Relieved employees tasks" — "still pending with employees who are no longer with the organization" | Tasks stuck with people who have left | Delegation (P4) and escalation exist; no list of tasks whose owner has exited | MISSING | AN-30 tab: owner's employee in exited / relieved state or user inactive; actions reassign (to manager / role) or archive |
| 5 | Tab "Tasks to be archived" (component label) | Tasks eligible for auto-archive | Auto-archive (`InboxArchives`, P4) for the approver's own queue | PARTIAL | AN-30 tab over the same rule, organisation-wide |
| 6 | "Request of" selector (`hroneSelect`) | Whose tasks: self / team / organisation *(inferred)* | — | MISSING | AN-30: scope picker (mine / my team / everyone in my scope) |
| 7 | Search "Search by name or code or designation or email id" | Find an employee's tasks | — | MISSING | AN-30 |
| 8 | Filters flyer: Organization, Location, "Clear all", "Apply" | Slice by org unit | — | MISSING | AN-30 (reuse `DashboardFilter`, AN-1) |
| 9 | Module accordions with counts: Workforce (n), Time Office, Payroll, Performance, Expense, Helpdesk, Asset, Recruitment, Engage | Group counts by module | `ApprovalCenter` sources carry a module | MISSING | AN-30; Recruitment N-A |
| 10 | Table columns: Task name, No. of open tasks, Actions | Count per task type | — | MISSING | AN-30 |
| 11 | "Last updated few second ago" | Snapshot stamp | Live | N-A (live) | — |
| 12 | Action: send reminder — Message (`remarks`), "Reminder recipients" (`filterReminderType`), Cancel, "Reminder sent successfully!", Done | Nudge the owners of open tasks of a type | Escalation step `remind` (automatic); manual "Send reminder" on a request (module 20 RQ-2) | PARTIAL | AN-30: bulk reminder to the owners of the selected task type (in-app + mail, throttled, audited) |
| 13 | Action: "Archive all" + "Why archive tasks?" + bulk list (`app-bulk-move-to-archive`: S.NO, EMPLOYEE CODE, EMPLOYEE NAME, ASSIGN DATE, DUE DATE, DETAILS, Move to archive, Hint) | Archive stale tasks in bulk | `InboxArchives` (per approver; request stays pending for others) | PARTIAL | AN-30: HR bulk archive for a task type (only system / informational tasks; decisions cannot be archived away) |
| 14 | Error list (`app-insight-error`, `app-error-exception`, `app-error-grid`): Assigned approver name, Task of, Role name, Employee list, Search, Show, Ignore, Selected | Tasks that could not be routed (no approver for a role) and their employees | Workflow instances that fail to resolve an approver are logged / escalated | PARTIAL | AN-30 tab "Routing errors": instances with an unresolved assignee (role with no holder in scope), with "assign" and "ignore" |
| 15 | "All interviews" / "Completed interviews" filter | Interview tasks | — | N-A (recruitment) | — |
| 16 | Canvas (`app-view-canvas`): "+ Create new canvas", "Canvases", "No Canvas created yet", "CREATE CANVAS", delete; create: Canvas name (`canvasName`), Select widgets (`widgets`), Done | Personal board of insight widgets | Dashboards (`DashboardResource`), managed by `analytics.manage` | PARTIAL | AN-15 per-user boards (personal dashboards without `analytics.manage`, only own-access widgets) |
| 17 | Widgets layout (`app-widgets-layout`): "Personalize widgets", Search, Customize, Analytics, SHOW MORE, Apply; widget kinds bar / count / donut / spline (`app-widget-bar`, `-count`, `-donut`, `-spline`), widget filter, widget report | Widget picker for the canvas | Widget types kpi / trend / chart (bar / line / pie) / table / leaderboard / alerts | HAVE | Donut = pie; spline = line |
| 18 | APIs `/api/inbox/insights/ModuleWise/Card`, `/Card/Name`, `/Search/CardName`, `/api/core/autoarchive`, `/api/core/common/widgets/Core/Details` | Counts per module, per card, search; auto-archive setting; widgets | — | N-A (API shape, covered by rows 1–17) | AN-30 service `InboxInsights::counts(scope, tab, filters)` |


---

## 21.4 Projects and timesheets (`/app/project-management/*`, Setup → Core HR → Project, `/app/core/projects/*`)

PeopleOS has **no projects, customers, tasks, milestones, timesheets or project invoices** (searched `app/Domain`,
migrations and `config/peopleos.php`: the only "project" values are a reporting type "Project manager", a performance
cycle type "Project", an establishment type "Project site" and a development action "Project assignment"). Every row
below is therefore MISSING unless noted; the build note names the column / setting so the package can be built from
the rows. Setup-side summary rows already exist in `setup/01-core-hr-access-integration.md` §1.29–1.35 (CA-17); this
section is the field-level expansion and adds the operational module the setup matrix did not see.

Conventions for the build (apply to every PM row): new domain `app/Domain/Projects/{Models,Services,Actions,Policies,
Enums}`; tenant-owned models use `BelongsToTenant` + `Auditable`; permissions `project.view`, `project.manage`,
`project.own` (owner rights), `timesheet.submit`, `timesheet.approve`, `timesheet.view`, `project.invoice` declared in
`config/peopleos.php` then `php artisan peopleos:sync-permissions`; Setup module `projects` in
`config/peopleos_setup.php`; policy types `project` and `timesheet` in `peopleos.policies.types` (so the policy wizard,
Applicability and Advance applicability come for free); approvals through workflow triggers `project.changed`,
`milestone.changed`, `timesheet.submitted` and Approval Center sources; amounts carry a currency, never INR by default.

### 21.4.1 Setup → Project template (`/app/core/projects/project-template`, `/app/core/project-management/project-template`; `app-project-template`, `app-template-description`, `app-project-stages`, `app-project-tasks`)

Both routes landed on Home in the crawl (module not subscribed); rows from components.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | List "Project Template": ADD TEMPLATE, cards with "Task mapped: n", Edit, Clone, SHOW MORE | Reusable project blueprints | — | MISSING | PM-2: `project_templates` (name, description, status) + `ProjectTemplateResource` (wizard per the forms-as-wizards rule) |
| 2 | Filter flyer "Project Template filter": Select Project Template (`projects`), Status Active (`active`) / Inactive (`inactive`), APPLY FILTERS | Filter the list | — | MISSING | PM-2: table filters |
| 3 | Wizard steps: Template Description · Project Stages · Project Tasks · Review | Template wizard | — | MISSING | PM-2 (`FormWizard`) |
| 4 | Step 1: Template Name (`templateName`), Template description (`description`) | Identity | — | MISSING | PM-2 columns |
| 5 | Step 2 "Stage list": stage rows with Enable (`isActive`), Rename Label (`userStageName`), "Add new stage" → Stage Name (`stageName`), Add / Cancel, "Add Stage", "Reorder" + "Save Order" (`taskStages`) | Kanban stages tasks move through (per template) | — | MISSING | PM-2: `project_template_stages` (template, name, label, sort, active); seeded from the tenant's task-stage defaults |
| 6 | Step 3 tabs "Milestones" / "Tasks" / "Attachment View" | Pre-defined milestones and tasks | — | MISSING | PM-2 |
| 7 | Task row: Task Name (`taskName`), Milestone (`milestoneId`, "Select Milestone"), Stage (`taskStageId`), Task Type (`taskTypeId`), Description (`taskDescription`), Estimated Effort Est. Hrs (`estimatedHours`) / Est. Min (`estimatedMin`), Attachments ("Drop files here to upload or Browse"), add / cancel, "no tasks found" | Template tasks | — | MISSING | PM-2: `project_template_tasks` (template, name, milestone_name, stage, task_type_id, description, estimated_minutes, attachments on the documents disk) |
| 8 | Milestone row: Milestone (`milestoneId` from the milestone master), description (`description`), "no Milestones found" | Template milestones | — | MISSING | PM-2: `project_template_milestones` (template, milestone master id, description, sort) |
| 9 | Step 4 Review | Read-only summary | Wizard review step (form wizard engine) | HAVE (platform) | Comes with the wizard |
| 10 | "Create project from template" *(inferred: why templates exist)* | Copy stages, milestones and tasks into a new project | — | MISSING | PM-3: `Projects::createFromTemplate()` |

### 21.4.2 Setup → project masters and form settings (`app-project-status`, `app-task-type`, `app-project-milestone`, `app-project-health-dimension`, `app-project-fields-setting`, `app-task-fields-setting`, `app-invoice-dynamic-fields`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Project stage: list, "Add Project Stage", Stage name (`projectSubStatus`), EDIT | Project sub-statuses (e.g. Not started, In process, Pending, Completed) | — | MISSING | PM-1: `project_stages` (name, sort, status) seeded with four neutral stages (setup 01 §1.32) |
| 2 | Task type: list, "Add Task type", Task type name (`taskType`), EDIT | Task categories | — | MISSING | PM-1: `project_task_types` (name, status) |
| 3 | Milestone master: "ADD MILESTONE", Milestone Name (`milestoneName`), EDIT, "No milestone" | Named milestone kinds reused across projects | — | MISSING | PM-1: `project_milestone_types` (name, status) |
| 4 | Project health: dimension rows Rename (`displayName`), Enable (`isEnabled`), Update | Configurable health dimensions (e.g. schedule, budget, quality *(inferred)*) | — | MISSING | PM-1: `project_health_dimensions` (key, label, enabled) seeded with schedule / budget / scope / quality |
| 5 | Project form settings ("Project form"): per field Rename Label (`displayName`), Enable (`isEnabled`), Mandatory (`isMandatory`), Visible to owner (`visibleToOwner`), Edited by owner (`editByOwner`), Visible to team (`visibleToTeam`), Update | Field rules of the project form | Custom Field Engine has label / required / status per custom field | MISSING | PM-1: setting `projects.field_rules` (Setup type `matrix`, CA-1) over the standard fields listed in §21.4.4 rows 2–39; default all enabled, none mandatory, owner sees / edits, team sees |
| 6 | Project form "Project field 1–10" (`projectField1..10`) | Tenant-defined project fields | Custom Field Engine (entities employee, company, department, location, designation, asset, …) | PARTIAL | PM-1: entity `project` in `peopleos.custom_fields.entities` (replaces the ten fixed slots) |
| 7 | Task form settings ("Task form"): per field Rename Label, Enable, Mandatory, Edited By Owner (`editByUser`), Edited By Team (`editByAnyone`), Update | Field rules of the task form (organisation default) | — | MISSING | PM-1: setting `projects.task_field_rules` (matrix) over the §21.4.6 row 6 task fields; per-project override in the project form (§21.4.4 row 39) |
| 8 | Task "Task field 1–10" (`taskField1..10`) + "Custom Fields" section in task details | Tenant-defined task fields | Custom Field Engine | PARTIAL | PM-1: entity `project_task` |
| 9 | Invoice form: "Invoice form settings let you change label names, enable them, and decide if they are required or optional" — Fields, Enable (`isEnabled`), Rename (`displayName`) (`settings`), Update | Invoice field rules incl. Invoice field 1–10 | — | MISSING | PM-8: setting `projects.invoice_field_rules` (matrix) + custom-field entity `project_invoice` |
| 10 | Customer master (`app-customer-layout`): ADD CUSTOMER, Customer name (`customerName`), Customer code (`customerCode`), Contact name (`contactName`), Email id (`emailAddress`), Phone number (`phoneNumber`), Customer address (`address`), Country (`countryId`), State (`stateId`), City (`cityId`), PIN (`pincode`), Active / Inactive, Clone, SHOW MORE, filter (Tags, Project, Status) | Customers billed by projects | — | MISSING | PM-1 = CA-17 / setup 01 §1.29: `customers` (code, name, contact_name, email, phone, address, country_code, state_code, city, postal_code, status) + `CustomerResource` |
| 11 | Customer "Use import": 1 Download Excel Sheet, 2 Upload edited sheet, "Excel errors found — download the commented file", "save edited excel sheet" | Bulk customers | Bulk upload framework (new / existing mode, error file) | PARTIAL | PM-1: `CustomerImports` on the existing import framework |
| 12 | `app-customer-form` / `app-billing-contact-details` (Basic Details: multiple legal entities, company name, registered address, country, state, city, currency, PIN, industry; Contact Details: decision maker, HR user, technical admin — name, phone, email, position; Billing Details: single company single billing / group company multiple billing, domain, billing address, zip, contact email / phone, GST no., PAN) | HROne's own subscriber profile ("Manage your company profile, billing information"), not project customers | SaaS billing profile (SaaS.7) | N-A (vendor account, not a project feature) | — |
| 13 | Vendor master (`app-vendor`, `app-add-vendor-flyer`) | Suppliers | — | MISSING | Rated in setup 01 §1.33–1.34 (CA-17); listed here for completeness, not counted again |
| 14 | Timesheet settings panel (`app-timesheet-setting`): server-driven Yes / No questions; workflow "No, all project have same workflow" / "Yes, for all projects will be different workflow" / "Following projects will have exclusive workflow" + Select projects (`conditionalTimesheetProjects`) | Timesheet approval routing | Workflows support start conditions | MISSING | Rated in setup 01 §1.35 (`timesheet.workflow_mode`, `timesheet.exclusive_projects`); built in PM-6 |

### 21.4.3 Setup → Timesheet policy (`app-timesheet-policy`, `app-reminders`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | List "Timesheet Policy": Add Policy, Status Active / Inactive filter, "Timesheet Policy filter" (Select Timesheet Policy `polices`), Edit, Clone, SHOW MORE, "APPLICABLE ON:" | Who fills timesheets and how | Policy engine (`Policy`, `PolicyVersion`, `PolicyAssignmentRule`) + policy wizard for every type | PARTIAL (platform) | PM-6: policy type `timesheet` in `peopleos.policies.types` — list, filters, clone and applicability come with the policy wizard |
| 2 | Wizard steps: Policy · Reminders · Applicability · Advance Applicability · Review | — | Policy wizard (Policy → sections → Applicability → Advance applicability → Review) | HAVE (platform) | — |
| 3 | Step "Policy": fields not captured (the `app-policy` selector collides with the separation policy component) *(inferred: name, description, period — weekly / monthly, minimum hours per day / week, mandatory for attendance days)* | Timesheet rules | — | MISSING | PM-6: fields `name`, `description`, `period` (weekly / monthly), `min_hours_per_day`, `min_hours_per_period`, `require_on_present_days` (all optional, defaults off); confirm labels when the page is captured |
| 4 | Step "Reminders": "Do you want to configure timesheet updates?" Yes / No; "Select frequency *": Weekly / Monthly | Remind employees to fill timesheets | Reminder schedulers exist per domain (e.g. `PerformanceReminders`) | MISSING | PM-6: policy fields `reminders_enabled` (default off), `reminder_frequency` (weekly / monthly); `peopleos:timesheets:remind` scheduled command |
| 5 | Step "Applicability" (`app-ou-applicability`: Select any one, Search here, SELECT ALL, Add bulk employees) | Who the policy covers | Applicability step | HAVE (platform) | — |
| 6 | Step "Advance Applicability" (`app-ou-advance-applicability`: "What would you like to set your advance applicability on?", From days, To days) | Tenure-based applicability | Advance applicability step | HAVE (platform) | — |
| 7 | Review + "You want to apply this policy on New employee / All employees" | Publish | Policy wizard review / publish | HAVE (platform) | — |

### 21.4.4 Project form (`app-project-form` wizard, opened by "Add New Project" and the edit icon; steps `app-advance`, `app-budget`, `app-availability`, `app-timesheet-details`, `app-visibility`, `app-task-settings`)

Steps: Project policy · Advance · Budget · Billable · Timesheet details · Visibility · Task Fields · Review. Field
names of step 1 come from the project-form settings list (setup 01 §1.31) because the step component was not captured.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Wizard frame: Next / Previous, "Activity Log" / "View activity log", "Note: Your last request is still pending. You cannot initiate a new one." | Project edits go through approval; history kept | Audit trail + workflow on other records | MISSING | PM-3: `ProjectWizard` (FormWizard engine); changes by owners raise `project.changed` (workflow optional, default none = applies directly) |
| 2 | Step "Project policy": Project code | Unique code | — | MISSING | PM-3: `projects.code` (number series via `EmployeeCodeSeries`-style code series, HS-P9) |
| 3 | Customer | Billed customer | — | MISSING | `projects.customer_id` (nullable = internal project) |
| 4 | Name | Project name | — | MISSING | `projects.name` |
| 5 | Currency | Currency of rates and budget | Currency masters exist (expense currencies) | MISSING | `projects.currency` (ISO; default tenant currency) |
| 6 | Owner | Project owner (manager) | — | MISSING | `projects.owner_employee_id` |
| 7 | Collaborator | Co-owners | — | MISSING | `project_members.is_collaborator` |
| 8 | Attachment | Contract / SOW files | Documents disk | MISSING | `project_attachments` on the documents disk |
| 9 | Step "Advance": "Who can be added to this projects?" (`allowOrganizationAccess`): "Anyone in the organization — Any member across the organization can be added" / "Own team members only — Only direct and indirect reportees can be added" | Member eligibility | Reporting lines (`ReportingRelationship`) give reportees | MISSING | `projects.member_access` (`organisation` \| `own_team`, default `organisation`) |
| 10 | Start date (`startDate`), End date (`endDate`) | Planned dates | — | MISSING | `projects.start_date`, `end_date` |
| 11 | Tags: "Add tag" (`projectTagInput`, suggestions) | Free tags | — | MISSING | `projects.tags` json |
| 12 | Status (`projectStatus`): Open / Closed | Lifecycle | — | MISSING | `projects.status` (`open` \| `closed`) |
| 13 | Sub-status (`projectSubStatusId`) | Stage from the project stage master | — | MISSING | `projects.stage_id` → `project_stages` |
| 14 | Actual closed date (`actualCloseDate`) + "Actual closed date should be greater than or equal to start date." | When it really closed | — | MISSING | `projects.closed_on` (validated ≥ start_date) |
| 15 | Project field 1–10 (`projectField1..10`) | Custom fields | Custom Field Engine | PARTIAL | §21.4.2 row 6 |
| 16 | Terms (`termCondition`) | Terms and conditions text | — | MISSING | `projects.terms` |
| 17 | Step "Budget": "At what level do you want to do project planning?" (`projectPlanning`): Project / Role wise | Plan by total or by role | — | MISSING | `projects.planning_level` (`project` \| `role`) |
| 18 | Project type (`projectType`): Fixed cost project / Time and material project | Billing model | — | MISSING | `projects.type` (`fixed_cost` \| `time_and_material`) |
| 19 | "Add Rate Breakup" rows (`roleWiseBreakUp`): Role (`roleId`, "Select role"), Rate (`rate`), Discount rate (`discountedRate`), HH (`hour`), MM (`min`); "Total hours:", "Total value:" | Role-wise estimate | Roles exist (but as access roles); no project roles | MISSING | `project_role_rates` (project, project_role_id, rate, discounted_rate, minutes); new master `project_roles` (Developer, Tester … tenant-defined) — not access roles |
| 20 | Project value (`projectValue`) | Contract value | — | MISSING | `projects.value` |
| 21 | Project budget (`projectBudget`) | Cost budget | — | MISSING | `projects.budget` |
| 22 | Budget hours (`projectBudgetHours`) | Hour budget | — | MISSING | `projects.budget_minutes` |
| 23 | Expense budget (`expenseBudget`) | Expense budget (non-billable) | Expense module has no project link | MISSING | `projects.expense_budget`; actual from PM-10 expense link |
| 24 | "Can user add task estimated effort greater than project budgeted hours?" (`validateBudgetHours`) Yes / No | Guard task estimates against the budget | — | MISSING | `projects.validate_budget_hours` (bool, default false) |
| 25 | "Can user add task estimated effort greater than milestone estimated efforts?" (`validateMilestone`) | Guard against milestone estimate | — | MISSING | `projects.validate_milestone` (bool, default false) |
| 26 | "Restrict user to burn more hours than the estimated hour on task?" (`restrictExtraEfforts`) | Block timesheets beyond the task estimate | — | MISSING | `projects.restrict_extra_effort` (bool) |
| 27 | "Restrict user to burn actual hours more than % of estimate hour" (`extraTimesheetEfforts`) | Allowed overrun % | — | MISSING | `projects.extra_effort_percent` (nullable) |
| 28 | Step "Billable": billable category rows (`projectCategory`): category (`availableCategoryId`, "Categories billable to the customer"), Category type (`billToCustomer`), "Maximum amount that can be billed to customer" (`partialBillableAmount`), add / delete | Which timesheet / expense categories bill to the customer and caps | — | MISSING | `project_billable_categories` (project, category, bill_type full / partial / none, cap_amount) |
| 29 | "Do you want to map any billable categories for expense?" (`isBillableCategory`) Yes / No | Expense billability | — | MISSING | `projects.expense_billable` (bool) — used by PM-10 |
| 30 | Timesheet type (`projectBillableCategory`) | Billable / non-billable timesheet category default | — | MISSING | `projects.default_billable_category` |
| 31 | "At what level do you want to track hours on Timesheet?" (`timesheetTrackHours`): Project / Task | Timesheet granularity | — | MISSING | `projects.track_hours_at` (`project` \| `task`, default `task`) |
| 32 | Effort by (`timesheetEffortBy`): Task owner / All team members mapped in project | Who may log time | — | MISSING | `projects.effort_by` (`task_owner` \| `all_members`) |
| 33 | Step "Timesheet details": "Backdated timesheet allowed?" (`allowBackdatedTimesheet`) + Days (`backdatedTimesheetDays`, "User can add backdated timesheet between 1-90 days!") | Back-dating window | — | MISSING | `projects.backdated_days` (null = not allowed; 1–90) |
| 34 | "Future dated timesheet allowed?" (`allowFutureTimesheet`) + Days (`futureTimesheetDays`, 1–60) | Forward window | — | MISSING | `projects.future_days` (null = not allowed; 1–60) |
| 35 | Step "Visibility" (`isBasedOnEmployee`): "Project visible to employees organisation wise" / "Project visible to specific employees" | Who sees the project in pickers (timesheet, expense, OD) | `AudienceCriteriaSchema` for audiences | MISSING | `projects.visibility` json (organisation criteria or employee list) via `AudienceCriteriaSchema` |
| 36 | Organization filter (`app-organization-filter`: compareWith, Select) | Org-wise visibility | `AudienceCriteriaSchema` | PARTIAL (platform) | Reuse |
| 37 | Location filter (`app-location-filter`) | Location-wise visibility | As row 36 | PARTIAL (platform) | Reuse |
| 38 | Employee codes (`employeeCode`) | Specific employees | Employee pickers | PARTIAL (platform) | Reuse |
| 39 | Step "Task Fields" (`app-task-settings`): Fields, Rename Label (`displayName`), Enable (`isEnabled`), Mandatory (`isMandatory`), Task Owner Edit (`editByUser`), Team Member Edit (`editByAnyone`) (`fieldsName`) | Per-project override of task field rules | — | MISSING | `projects.task_field_rules` json (falls back to `projects.task_field_rules` setting) |
| 40 | Step "Review" | Summary | Wizard review | HAVE (platform) | — |
| 41 | Project approval (`app-project-approval`, `app-initiated-project-approval`, `app-milestone-details-card`): Project name, "Earlier" / "New" values, Remarks, "No changes available — No changes have been done in the project", workflow tracker, due labels | Approve project creation / changes | Workflow engine + Approval Center; before/after preview exists for other records (`BeforeAfterPreview`) | MISSING | PM-3: Approval Center source `project_change` with before/after diff; trigger `project.changed` |
| 42 | Milestone activity log (`app-milestone-activity-log`) with milestone / project approval cards | History of approvals | Audit | MISSING | PM-3 / PM-5 |

### 21.4.5 My projects workspace (`/app/project-management/projects`, `/redirect/PROJECTS`, `/redirect/TASKS`; `app-my-projects`, `app-project-card`, `app-project-list-view`, `app-project-filter`, `app-project-health-status-flyer`)

Captured live after the reboot (`route_app_project_management`, `…_projects`, `…_redirect_TASKS`, `…_redirect_PROJECTS`,
each with tabs): the workspace renders for the crawl user with no projects. Live tabs are "My Projects (0)", "My Tasks",
"Invoice", "Timesheet", "Analytics" (the component's "Reports" tab is not shown to this user); the page loads
employee status / type, pay groups, currency, project dimensions, project field details, sub-statuses, the import list
and the timesheet calendar.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tabs: "My Projects (n)", "My Tasks", "Invoice", "Timesheet", "Analytics" (+ "Reports" in the component); button "Add New Project"; "Filter" | Project workspace | — | MISSING | PM-3: `ProjectsWorkspace` page (tabs) under a new navigation group "Projects" |
| 2 | Card view search "Search project" + Filter (`app-project-filter`): Type (`projectType`), Tags (`projectTag`), Project Sub Status (`subStatusName`), Timesheet (`projectBillableCategory`), Project Owner (`projectOwners`), Customer (`customerId`), Status (`isActive`), Reset / Apply, "CLEAR ALL" | Find projects | — | MISSING | PM-3 table / card filters |
| 3 | Project card fields: Type, Time sheet, Start Date, End Date, Budgeted Hours, Actual Hours, Expense Budget "(Non-billable)", Actual Expense, Project Budget, Actual Project Cost, Active / INACTIVE, "Health status :" | At-a-glance project economics | — | MISSING | PM-3: computed `actual_minutes` (approved timesheets), `actual_expense` (PM-10), `actual_cost` (approved minutes × member cost rate) |
| 4 | Card tabs "About" / "Insights" | Details vs metrics | — | MISSING | PM-9 insights (burn, variance) |
| 5 | "No projects — Please add projects to begin your tasks" (seen live) | Empty state | — | MISSING | — |
| 6 | Health status flyer: "The dimensions for the project health are down below", per dimension Health status (`dimensionInput` → `fieldValue`), "PROJECT HEALTH", Activity log (`app-health-status-activity-log`) | Owner rates health per dimension; history kept | — | MISSING | PM-3: `project_health_updates` (project, dimension, status green / amber / red, note, by, at; append-only) |
| 7 | List view (`app-project-list-view`): search, "Customize — Please activate the columns you want to view in the table", Save, Filter, paging "Select page of projects", "no project" | Table view | Filament tables (column toggles) | PARTIAL (platform) | PM-3 table with toggleable columns |

### 21.4.6 Project details (`/app/project-management/projects/:id`, `/redirect/PROJECT|TASK|SUBTASK|COMMENT|MILESTONE/...`; `app-project-details-header`, `app-project-management-header`, `app-project-details`, `app-project-detail`, `app-task-details`, `app-subtask`, `app-comment`, `app-timesheet`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tabs: "Tasks (n)", "Milestones", "Team Members", "Settings"; header "Add Task", "Transaction Id", activity log (`app-hrone-activity-log`), edit project, "Add new stage" popup (`stageName`) | Project page | — | MISSING | PM-4: `ProjectResource` view page with relation tabs |
| 2 | Deep links `/redirect/TASK/:id/:taskId`, `/SUBTASK/...`, `/COMMENT/...`, `/MILESTONE/...` | Notification links straight to an item | Notifications link to records | MISSING | PM-4 routes for task / comment / milestone anchors |
| 3 | Task board / table (`app-project-details`): "Stages" columns, "Boards", "CUSTOMIZE BOARD", "+ Add Stage", "Reorder" / "Save order", "Customize — Table columns", "Search tasks here...", "Group by" | Kanban and table of tasks | — | MISSING | PM-4: `project_tasks` board (stage columns from `project_stages` of the project) + table |
| 4 | Board card actions (`app-grid-view`): Add task, "Move to" (stage), Clone, Timesheet, "No due date", "Request for" | Work tasks on the board | — | MISSING | PM-4 actions; "Timesheet" opens the hours dialog (§21.4.8 row 9) |
| 5 | Task filter (`app-project-detail`): Task Name (`taskName`), Task Type (`taskType`), Task priority (`taskPriority`), Start date (`startDate`), Due date (`endDate`), Assigned to (`assignTo`), Task status (`taskStatus`), Task due (`overDue`), Milestone (`milestoneId`), Tag (`tagArr`), Employees (`employeeId`), Reset / Apply, "clear all", "No Tasks — No tasks have been found which match your search query." | Filter tasks | — | MISSING | PM-4 filters |
| 6 | Task details (`app-task-details`): Task name (`taskName`), Task owners (`taskOwners`), Add tag (`addTag`), Est. Hrs (`estimatedHours`) / Est. Min (`estimatedMin`), Stage (`taskStageId`), Task type (`taskTypeId`), Priority (`taskPriority`), Actual hours (`actualHours`) / Actual min (`actualMin`), Milestone (`milestoneId`), Start date (`startDate`), End date (`endDate`), Description (`taskDescription`), Task field 1–10, Custom Fields, Edit / Cancel | The task record | — | MISSING | PM-4: `project_tasks` (project, parent_id for subtasks, name, owner ids json or `project_task_owners`, tags, estimated_minutes, stage_id, task_type_id, priority low / medium / high / critical, actual_minutes (computed from timesheets, editable only when tracking at project level), milestone_id, start_date, due_date, description, custom fields) |
| 7 | Task attachments: "Add Attachment", list (Name, Date, Time, Type, Action), "Sorry there are no attachments to view" | Files on a task | Documents disk | MISSING | PM-4 `project_task_attachments` |
| 8 | Task activity log: Name, Date, Time, "Activity Done", "Sorry there are no data to view" | History | Audit trail (`Auditable`) | PARTIAL (platform) | Show the model's audit events |
| 9 | Comments (`app-comment`): "Add a comment here. Use @ to mention a person" | Discussion with mentions | Request discussion (`RequestDiscussion`) exists for requests | PARTIAL | PM-4: comments on tasks reusing the discussion component; mentions notify |
| 10 | Sub tasks (`app-subtask`): "Mention a sub task name" | Checklist under a task | — | MISSING | PM-4: subtasks = `project_tasks.parent_id` |
| 11 | Task timesheet tab (`app-timesheet`): search, HH / MM, Billable Category (`projectBillableCategory`), Status (`status`), Reset / Apply Filter | Time logged on the task | — | MISSING | PM-6 relation |
| 12 | Tab Milestones (`app-milestone-grid-view`): Refresh, View Activity Log, "New Milestone", Customize table, "Search milestones here...", Group by, "No milestones — Add a milestone to get started.", Action | Milestone list | — | MISSING | PM-5: `project_milestones` relation |
| 13 | Milestone filters (`app-milestone-filters`): Select Owner (`milestoneOwner`), Start Date From / To (`startDateFrom`, `startDateTo`), End Date From / To (`endDateFrom`, `endDateTo`), Reset / Apply | Filter milestones | — | MISSING | PM-5 |
| 14 | Milestone form (`app-milestone-form`): Milestone (`milestoneInfo`, from the master), Description, Start date, End date, Owner (`milestoneOwnerId`), Est. efforts HH:MM (`estHours`, `estMins`), "View rate breakup", Actual efforts hour / min (`actualHour`, `actualMin`), To be invoiced (`toBeInvoiced`), Milestone type (`milestoneType`: Fixed value / Actual hours logged), Milestone revenue (`milestoneRevenue`), Remaining effort cost (`remainingEffortCost`), Project Code | Milestone record | — | MISSING | PM-5: `project_milestones` (project, milestone_type_id, description, start_date, end_date, owner_employee_id, estimated_minutes, actual_minutes computed, invoice_basis `fixed` \| `actual_hours`, revenue, to_be_invoiced bool, status) |
| 15 | Estimated rate breakup dialog (`app-emtimated-rate-breakup-dialog`): rows Role (`roleId`), Rate (`rate`), HH / MM, Estimated value (`estimatedValue`); "Total hours", "Total value"; Reset / Apply (`milestoneEstimatedBreakup`) | Role-wise milestone estimate | — | MISSING | PM-5: `project_milestone_estimates` (milestone, project_role_id, rate, minutes, value) |
| 16 | Milestone approval (`app-milestone-approval`, `app-milestone-approval-req`): Milestone name, Earlier / New, Remarks, workflow | Approve milestone changes | Workflow + Approval Center | MISSING | PM-5: trigger `milestone.changed`, Approval Center source |
| 17 | Tab Team Members (`app-team-grid-view`): Refresh, View Activity Log, "Add Team Member", Customize, "Search team members here...", Group by, Filter Team, "No team members — Add a team member to get started.", Action | Project team | — | MISSING | PM-4: `project_members` relation |
| 18 | Team filter (`app-team-filters`): Employee Type (`employeeType`), Role (`role`), Employee Status (`employeeStatus`), Reporting Manager (`reportingManager`), Employee (`employee`) | Filter members | — | MISSING | PM-4 |
| 19 | Team member form (`app-team-member-form`): Select Employee (`employeeId`), Role (`roleId`), Currency (`currencyCode`), Rate (`ratePerHour`), Discount Rate (`discountedRatePerHour`), Bill Rate / cost (`costPerHour`), Est. efforts hrs / min (`estimatedHours`, `estimatedMin`) | Member economics | — | MISSING | `project_members` (project, employee_id, project_role_id, currency, bill_rate, discounted_rate, cost_rate, estimated_minutes, is_collaborator, joined_on, left_on). Cost rate is compensation-sensitive: visible only with `project.manage` + `compensation.view` |
| 20 | Task rights per member: Can create task (`createTask`), Can view task (`viewTask`), Can view analytics (`viewAnalytics`), Can edit team (`editTeam`), View/Edit/Delete milestone (`editMilestone`), Edit timesheet (`editTimesheet`), Can generate invoice (`createInvoice`), Can edit invoice (`editInvoice`), Can view invoice (`viewInvoice`), Can cancel invoice (`cancelInvoice`) | Per-project rights | Permission keys are tenant-wide (roles) | MISSING | `project_members.rights` json over a fixed list in `config/peopleos.php` (`projects.member_rights`); `ProjectPolicy` checks role permission AND member right |
| 21 | Settings → Alerts (`app-project-settings-alerts`: tabs Task / Project / Milestone; `app-task-alerts`): "Configure alerts based on tasks" Yes / No, "Send alert reminder" (`sendAlertWhenDueExceeds`), Duration type Before / After (`durationType`), Duration days (`durationDays`), Frequency Once / Daily / Alternate days (`frequencyType`), Send alert to (`sendAlertTo`), Note, Save | Due-date alerts for tasks, milestones and the project | Domain reminder commands exist elsewhere | MISSING | PM-7: `project_alert_rules` (project, subject task / milestone / project, enabled, before_after, days, frequency once / daily / alternate, recipients owner / members / project owner); command `peopleos:projects:alerts` |

### 21.4.7 My tasks (`app-task-list`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Search tasks here......", columns Task Name, Project Name, Project Code, Task Stage, Milestone, Due on; "No task found"; row opens task details | The user's tasks across projects | Task inbox (`TaskInbox`) holds workflow / onboarding tasks, not project tasks | MISSING | PM-4: "My tasks" tab (tasks where the user is an owner), also feeding Home "Tasks for today" |

### 21.4.8 Timesheets (`app-timesheet-view`, `app-timesheet-request`, `app-timesheet-request-form`, `app-new-timesheet-request-form`, `app-hours-dialog`, `app-timesheet-drafts`, `app-timesheet-approval`, `app-timesheet-request-approval`, `app-timesheet-search`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Timesheet tab tiles: Approved hours, Pending hours, Billable hours, Non-billable hours | Totals for the period | — | MISSING | PM-6: `TimesheetsTab` totals |
| 2 | Calendar view (`app-hrone-calendar-view`; captured live as a month grid) + filter (`app-calendar-view-filter`; live fields "Select Project", "Select Month/Year", "Select employee") | Hours by day on a calendar, for self or team | Attendance calendar exists (`AttendanceCalendar`) | MISSING | PM-6: calendar of `timesheet_entries` with project, month and (for managers) reportee pickers |
| 3 | "Are you sure you want to change the timesheet view? All data would be lost if you change the view." | Switch between weekly grid and date-wise form | — | MISSING | PM-6: two entry modes |
| 4 | Weekly grid "Make your timesheet" (`app-new-timesheet-request-form`): Start date, rows Select Project (`projectId`), Select Task (`projectTaskId`), hours per working day (`workingDays`), "Add row", "Total hours:", "Enter hours", Save as draft, Reset, Submit | Week-at-a-glance entry | — | MISSING | PM-6: `timesheets` (employee, period_start, period_end, status draft / submitted / approved / partly approved / rejected / cancelled, submitted_at) + `timesheet_entries` (timesheet, date, project_id, task_id nullable when tracking at project level, minutes, billable_category, work_mode, office_id, city, start_time, end_time, comment, status, approver_comment) |
| 5 | Date-wise form "New Timesheet" (`app-timesheet-request-form`): "CHOOSE YOUR TIME", calendar (single selection on dates; "Please select new date, this date is already existed!"), "Add more dates", rows Date, Project Name (`projectId`), Billable category (`projectBillableCategory`), Task Name (`projectTaskId`), Work mode (`workplaceId`), Office (`officeId`) / City (`cityCode`), Start Time (`startHour`, `startMin`), End Time (`endHour`, `endMin`), hours / minutes (`hour`, `min`), Comment (`comment`), Status, Actions; Save, Save as draft, Reset All | Detailed time entry with place of work | Work modes master and offices (locations) exist | MISSING | PM-6 (same tables); work mode / office reuse `work_modes`, `locations` |
| 6 | Drafts (`app-draft-dialog`, `app-timesheet-drafts`: Draft date, DRAFT, "Complete Request") | Resume a draft | Drafts exist for some requests (module 20) | MISSING | PM-6: status `draft` |
| 7 | Validation from the project: backdated / future windows, effort by, track at, restrict extra effort, % overrun (§21.4.4 rows 24–34) | Server-side rules | — | MISSING | PM-6: `Timesheets::validate()` enforcing project settings |
| 8 | Policy rules (§21.4.3) | Minimums and reminders | — | MISSING | PM-6 |
| 9 | Hours dialog (`app-hours-dialog`): "Please log your time for … on the task below", Start date, End date, Start time (hh:mm), End time (hh:mm), HH / MM, Time duration, Total time, "Add description", "Copy to next days", Save; validations "Total hours should not be more than 24 hours!", "End time cannot be more than 2 days from today", "End time must be greater than start time", "Start time must be less than end time" | Quick log from a task card | — | MISSING | PM-6 (same rules) |
| 10 | Submit → approval (`app-timesheet-approval`): "Timesheet Request — You can select or deselect the request to approve or reject"; workflow tracker, due labels, missed-to-respond | Manager / project owner approval | Workflow engine + Approval Center | MISSING | PM-6: trigger `timesheet.submitted`, Approval Center source `timesheet`; routing per `timesheet.workflow_mode` (setup 01 §1.35) |
| 11 | Approval detail (`app-timesheet-request-approval`): "TIMESHEET DETAILS", Requestee name, Request date, columns Date, Project Name, Task Name, Category, Start Date / End Date, Start Time / End Time, Total Hours, Comments, Status, Action, Approver's remarks; "Grouped by:", Requests, "Date(s) Selected", Select / Select all (`isChecked`), Approve / Reject per line, "Total Approved:", "Total Rejected:", "Add comments", "Enter comment", "Reset actions", Save / Submit / Close / Done | Line-level approve / reject with remarks | Approval Center decides whole items | MISSING | PM-6: line-level decisions (entry status) and a partly-approved timesheet |
| 12 | HR Desk timesheet search (`app-timesheet-search`): Filters Project (`projectId`), Task (`projectTaskId`), Requested date (`requestDate`), Approved date (`approveDate`), Status (`RequestStatusIds`: Rejected / Approved / Pending); cards "Project Name :", "Requested date :", "Timesheet type :", Show More, Clear All; opens workflow / detail | Find timesheet requests | Request history lists exist per request type (module 20) | MISSING | PM-6 HR list |
| 13 | Timesheet on the employee's attendance day flyer ("Timesheet — No Data Available. Task - Comment :") | Time logged that day beside punches | `AttendanceCalendar` day panel | MISSING | PM-6: show the day's entries in the attendance day panel |
| 14 | Timesheet tab legacy view (`app-timesheet-req`: Attendance Date, Value, Reason, Leave Code / Name, Attendance Month, Arrear Days) | Shared table component (labels belong to attendance requests) | — | N-A (shared component; no project meaning) | — |
| 15 | Cancel a submitted timesheet *(inferred: request lifecycle)* | Withdraw before approval | Request cancel patterns exist | MISSING | PM-6: cancel while pending |

### 21.4.9 Project invoices (`app-invoice`, `app-generate-invoice`, `app-generated-invoice-list`, `app-invoice-summary`, `app-invoice-grid`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tabs "Generate invoice (n)" / "All invoiced (n)"; "Milestone type: Fixed value / Actual hours logged" | Bill milestones | — | MISSING | PM-8: `InvoicesTab` (permission `project.invoice` + member right) |
| 2 | Generate-invoice table. Live columns for milestone type "Fixed value": PROJECT NAME, PROJECT CODE, PROJECT STATUS, MILESTONE NAME, MILESTONE STATUS, MILESTONE START DATE, MILESTONE END DATE, MILESTONE OWNER NAME, MILESTONE REVENUE, INVOICED AMOUNT, TO BE INVOICED AMOUNT, ACTIONS ("Raise Invoice"), "DATA NOT FOUND"; for "Actual hours logged" the component adds Approved Hours, Invoiced Hours, To Be Invoiced Hours; search; "Fish selection" (row checkboxes); Filter | What can be billed | — | MISSING | PM-8: to-be-invoiced = fixed milestone revenue, or approved billable minutes × bill rate not yet invoiced; the column set follows the milestone type |
| 3 | Generate-invoice filters: Project name (`projectId`), Project status (`projectStatus`), Milestone name (`milestoneId`), Milestone status (`milestoneStatus`), Milestone owner (`milestoneOwner`), Milestone Start Date From / To (`milestoneStartFrom`, `milestoneStartTo`), Milestone End Date From / To (`milestoneDueFrom`, `milestoneDueTo`), To be invoiced hours HH / MM, To be invoiced amount (`toBeInvoicedAmount`); Reset / Apply Filter / Clear All | Filter | — | MISSING | PM-8 |
| 4 | Invoice form (`app-invoice-summary`): Project Code, Project Name, Milestone Name; "Discount details": Sub Total, Discount (Amount) (`totalDiscountAmount`), Discounted Amount | Invoice header and discount | — | MISSING | PM-8: `project_invoices` (number via code series, project, milestone, customer, currency, sub_total, discount, discounted_amount, tax_total, total, minutes) |
| 5 | "Tax details" rows (`taxDetails`): Tax type (`taxId`), Tax % (`taxPer`) | Taxes on the invoice | Expense tax types master (`ExpenseTaxType`) | MISSING | PM-8: `project_invoice_taxes` (invoice, tax_type_id, rate, amount); rates come from the tax master, never hard-coded |
| 6 | "Total amount details", "Total invoice hours (hh:mm)" | Totals | — | MISSING | PM-8 |
| 7 | "Invoice date details": Invoice date (`invoiceStartDate`), Invoice due date (`invoiceDueDate`) | Dates | — | MISSING | PM-8 |
| 8 | "Comment details": Customer facing comments (`customerComment`), External comments (`externalComment`), "Write your remark here" | Notes | — | MISSING | PM-8 |
| 9 | "OU fields": Select Company, Select Business Unit; "Select template" | Billing entity and invoice template | Companies / BUs; letter templates engine (letter studio) | MISSING | PM-8: `company_id`, `business_unit_id`; invoice PDF from a letter-studio template type `project_invoice` |
| 10 | "Payment details": Payment status, "Payment received" (`isPaymentReceived`), Payment type (`paymentType`: Cheque / Cash / Online), Payment date (`paymentDate`), Payment remark (`remarks`), Payment bank (`bankId`), Cheque no. (`chequeNo`), Cheque date (`chequeDate`), Transaction Id (`transactionId`), Payment Code | Record customer payment | Company payment banks (`payment_banks`, CA-5) | MISSING | PM-8: payment columns on `project_invoices` (received bool, mode, date, bank_id, cheque_no, cheque_date, transaction_ref, remark) |
| 11 | "Dynamic Fields": Invoice field 1–10 (`invoiceField1..10`) | Custom fields | Custom Field Engine | PARTIAL | §21.4.2 row 9 |
| 12 | "Terms and conditions" | Terms text | — | MISSING | PM-8 (default from `projects.terms`) |
| 13 | Edit, "Cancel Invoice" — "Are you sure you want to cancel invoice? Once you have cancelled this invoice, the respective timesheet dates of the milestone would be visible in the generate invoice tab." | Cancel and release the hours | — | MISSING | PM-8: status `cancelled` frees the invoiced entries (`timesheet_entries.project_invoice_id` nulled, audited) |
| 14 | All-invoiced table: Invoice Code, Invoice Status, Project Code, Project Name, Milestone Name, Milestone Owner Name, Invoiced Hours, Invoiced Amount (currency), Invoiced Date, Invoice Due Date, Payment Status, Payment Date, Actions (View Details) | Invoice register | — | MISSING | PM-8 table; dataset `project_invoices` (PM-9) |
| 15 | All-invoiced filters: Project name, Milestone name, Milestone owner, Invoiced Amount From / To (`invoiceAmountFrom`, `invoiceAmountTo`), Payment Date From / To (`paymentDateFrom`, `paymentDateTo`); search | Filter | — | MISSING | PM-8 |

### 21.4.10 Projects → Reports and Analytics tabs, and project links in other modules

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| 1 | Reports tab (`app-reports`): "Search reports", "No Reports — Please add reports to begin your tasks" | Project reports (catalogue: "Projects detail") | Report library | MISSING | PM-9: datasets `projects`, `project_tasks`, `project_members`, `timesheet_entries`, `project_invoices` (category "Projects"), templates "Projects detail", "Timesheet register", "Billable vs non-billable hours", "Budget vs actual", "Invoice register", "Unbilled hours" |
| 2 | Analytics tab (`app-analytic-dashboard-home` inside projects; captured live: "Share", "Hi There! Please select from the curated list of dashboards we have for you.", "Select Dashboard/s (0) :", "Skip for Now", "Create DashBoard"; APIs `/api/library/DefaultDashboardList/P`, `/api/core/analytics/list/P`) | Curated and custom dashboards over project widgets | Dashboards | PARTIAL (platform) | PM-9: seeded "Projects overview" board (hours burn, health, unbilled) offered through the AN-15 gallery |
| 3 | Expense receipt / bulk receipts / expense report "Project" (`projectId` on `app-create-expense`, `app-add-bulk-expense`, `app-expenses`, `app-report`) | Book expenses to a project | Expense module (EX-A…G) has no project field | MISSING | PM-10: nullable `project_id` on `expense_receipts`, `expense_reports`; visible when a project is visible to the employee |
| 4 | Expense settings: "Enable project based approval" (`isProjectBasedApproval`), allow multiple projects in a report (`isAllowMultipleProjectReport`), project visible / required on travel plan (`isProjectVisibleOnTravelPlan`, `isProjectRequiredOnTravelPlan`), expense advance against project (`isExpenseAdvanceProject`) | Project-driven expense rules | Blocked in `00-build-status.md` ("project-based approval … depends on projects") | MISSING | PM-10: settings `expense.project_approval` (default off), `expense.multiple_projects_per_report` (default on), `travel.project_visibility` (hidden / optional / required, default hidden), `expense.advance_project` (default off); project owner as an approver role in workflows |
| 5 | Travel request "Project" (`projectId` on `app-travel-request`, `app-book-travel-plan-edit-drawer`) | Trip for a project | Travel (EX-F) has no project | MISSING | PM-10: `travel_requests.project_id` |
| 6 | Expense advance "Project" (`projectId` on `app-advance-request`) | Advance for a project | — | MISSING | PM-10: `expense_advances.project_id` |
| 7 | On duty request: Project (`projectId`), Project task (`projectTaskId`), Category (`projectBillableCategory`) (`app-on-duty-view-form`) | OD booked to a project task | Time-office on-duty (HP-1) without project | MISSING | PM-10: optional project / task / category on on-duty requests, feeding a timesheet entry when approved (setting `timesheet.on_duty_creates_entry`, default off) |
| 8 | Expense report search filter "Project" (`app-report-card`) | Find reports by project | — | MISSING | PM-10 |
| 9 | Job requisition Customer / Project (`app-job-opening-request`, `app-gol-hiring-details`) | Hiring for a project | — | N-A (recruitment, separate RMS) | — |
| 10 | Work plan (`app-initiate-work-plan`, `app-initiated-work-plan`: work mode, office, city, shift per date; approve / reject per date) — listed with projects in gap register PR-05 | Hybrid-work plan, not a project feature | — | N-A here (hybrid work, setup re-verification SV-18) | Built with SV-18 |
| 11 | Reporting type "Project manager" (PeopleOS `peopleos.employment.reporting_types.project`) | — | Exists | HAVE | PM-4 may offer the project owner as the default "project manager" reporting line (optional) |


---

## Counts

| Section | HAVE | PARTIAL | MISSING | N-A | Rows |
|---|---|---|---|---|---|
| 21.1 Analytics dashboards (chrome, CXO views, time office, movement, engage, expense, helpdesk, 9 box, performance, pulse, survey, ROI, payroll, happiness, HR ops, custom dashboards) | 46 | 76 | 34 | 8 | 164 |
| 21.2.1 Report centre, viewer, saved views, schedules, custom reports | 17 | 14 | 6 | 3 | 40 |
| 21.2.2 Report catalogue (230 reports) | 94 | 99 | 20 | 17 | 230 |
| 21.3 Inbox insights | 1 | 5 | 9 | 3 | 18 |
| 21.4 Projects and timesheets (setup, form, workspace, details, tasks, timesheets, invoices, links) | 7 | 13 | 119 | 4 | 143 |
| **Total** | **165** | **207** | **188** | **35** | **595** |

HAVE rows in 21.4 are platform pieces (policy wizard steps, review steps, reporting type) that projects get for free.

## Biggest gaps

1. **No projects module** (§21.4, 119 MISSING rows): no customers, project templates, projects, members and
   per-project rights, tasks / subtasks / board, milestones, timesheets with line-level approval, alerts, project
   invoices, or project links on expenses, travel, advances and on-duty. Expense "project-based approval" is blocked on it.
2. **Dashboard numbers do not drill down or export** (§21.1.0 #5–#10): every HROne tile opens the people behind it
   with give badge / points / 1-on-1 actions and an export; PeopleOS widgets show values only.
3. **No CXO views** (§21.1.1, CA-10 — built 2026-10-30; ROI waits for AN-11): dashboards cannot be sliced to an organisation unit, shared with named people
   or mailed periodically; the payroll dashboard has no freeze day.
4. **Module dashboards missing**: time office, employee movement (as one board), engage, payroll, HR ops (process
   efficiency against target days) and ROI (hours / money saved per task type) (§21.1.2–21.1.4, 21.1.11, 21.1.12).
5. **Report outputs**: CSV only — no `.xlsx` / PDF, no background generation for large reports, no cross-report
   download history, no password-protected files, no recents / favourites (§21.2.1 #14–#17, #33, #37, #39).
6. **~100 catalogue reports are PARTIAL** because the data is recorded but no dataset exposes it: loans,
   reimbursement ledger / claims, variable pay, salary holds, TDS worksheet, CTC component breakup, 360 / check-ins /
   1-on-1 / TNI / review details, confirmation, transfer, induction, exit interviews, skills, work experience,
   custom fields, policy assignments, sign-ins (§21.2.2).
7. **No pivot visualization** in the builder: CTC revision (pivoted), salary YTD component-wise, monthly attendance
   register, month-wise leave balance, ratings by approver (pivoted), day-wise shift roster (§21.2.2).
8. **Inbox insights** (§21.3): no organisation-wide view of pending / overdue tasks by type, of tasks stuck with
   relieved employees, of routing errors, and no bulk remind / archive for HR.
9. **9-box grid and performance dashboard depth** (§21.1.7–21.1.8): no grid (HP-8g), no review-cycle split, no
   new-hire filter, no initiatives; HROne's top / bottom performer lists conflict with PeopleOS's no-ranking rule
   (owner decision).
10. **Report centre sharing and parameters** (§21.2.1 #9, #21, #22): saved reports are private or shared with everyone
    who can read the dataset — no named users / roles, no email delivery, and templates have fixed periods instead of
    run-time period / org-unit prompts.

Also open: statutory joiner forms (EPF Form 11, ESI Form 1, Gratuity Form F) and bonus Form C / D (BONUS rule
pending); e-signature status reports wait for SV-17; e-KYC for CA-15; LTA liability for PY-04.

**Owner decisions needed:** (a) whether to show ranked lists (top / bottom performers, top agents, top points holders
outside HR, unhappy-employee names, "bad hires") — this file proposes factual, permission-gated equivalents;
(b) the spreadsheet / PDF library for `.xlsx`, PDF and password-protected exports (shared with BP-14); (c) whether a
pulse "benchmark score" may be tenant-entered; (d) whether reports may be sent to an AI provider (aggregated,
suppressed data only); (e) confirm that tenant-written SQL reports stay out (N-A by design).

## Build packages (ordered)

Conventions: additive; defaults reproduce today's behaviour; datasets are the only place that derives report values
(register in `DatasetRegistry`, add templates to `ReportLibrary`, extend `tests/Feature/Analytics/ReportLibraryTest.php`
so every template runs); sensitive fields behind the dataset's sensitive permission and masked; groups below
`peopleos.analytics.min_group` suppressed; no statutory value hard-coded (forms and rates from verified rule data);
new permission keys in `config/peopleos.php` then `php artisan peopleos:sync-permissions`; organisation switches as
Setup centre settings; per-group options as policy types; payroll and approvals through the tagged extension points.

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size |
|---|---|---|---|---|---|
| 1 | **AN-24 Payroll and compensation datasets + pivot** — pivot visualization in `ReportRunner` (rows × columns × value); datasets `ctc_components`, `salary_holds`, `tds_worksheets`, payroll audit (unprocess); fields `pf_wages`, HRA landlord columns, `has_declaration`, encashment amount / liability value, NPS classification, statutory `declared_on`; templates incl. CTC revision (pivoted), salary YTD component-wise, negative net pay, OT hours + amount | 21.2.2 Payroll #7, 8, 13, 14, 20, 23, 26, 30, 31, 36–38, 41, 53, 54, 57, 59; Performance #21; Time Office #19, 20, 22, 28, 32, 36 (pivot part) | `app/Domain/Analytics/Services/{ReportRunner,ReportLibrary,DatasetRegistry}.php`, new `Datasets/*`, `Filament/Support/ReportBuilderSchema.php`, report view blade | — | L |
| 2 | **AN-26 Loans, reimbursements, variable pay datasets** (**Done 2026-10-30**) — `loans`, `loan_installments`, `reimbursement_ledger`, `reimbursement_claims`, `variable_pay_plans`, `variable_pay_requests` + 10 templates | 21.2.2 Payroll #32–34, 47, 48, 50, 51, 60–62; 21.1.12 Payroll #6, #8 | new `Datasets/*`, `ReportLibrary.php` | — | M |
| 3 | **AN-27 Performance datasets** (**Done 2026-10-30**; 360 nominations / results as `feedback_360_status` and aggregate-only `feedback_360_results`) — `one_on_ones`, `feedback_requests`, `feedback_responses`, `check_ins`, `goal_plans` (incl. people without a plan), `pip_checkpoints`, `appraisal_reviews`, `appraisal_ratings`, `development_needs` + templates | 21.2.2 Performance #1, 3–7, 9, 10, 12, 15, 17, 18, 20, 23, 24 | new `Datasets/*`, `ReportLibrary.php` | — (360 nominations wait for SV-12) | M |
| 4 | **AN-29 Workforce datasets** — `confirmation_reviews`, `transfer_requests` (+ tasks, allowance), `exit_interviews`, `interview_status` on exits, `induction_plans`, `sign_ins` (audit LOGIN), `personal_details` (addresses, sensitive), person-change history, `policy_assignments`, `role_coverage`, `employee_skills`, `work_experience`, `Dataset::customFields(entity)`, printable no-dues certificate | 21.2.2 Workforce #4, 6, 7, 9, 14–18, 24, 25, 28, 31–35, 40, 42, 45, 48 | new `Datasets/*`, `Dataset.php`, `ReportLibrary.php`, a clearance certificate view | SV-7 for role coverage | L |
| 5 | **AN-28 Time-office datasets** — `geo_locations`, `location_requests`, `leave_month_balances`, `day_roster`, `punch_count` / `odd_punches` / `extra_hours` fields, arrear calculation template, mobile check-in, monthly deductions, bulk attendance slip (queued PDF) | 21.2.2 Time Office #1, 3, 10, 15, 19–22, 28, 30, 33, 36; 21.1.2 #6, #13 | new `Datasets/*`, `AttendanceDataset.php`, `AttendanceMonthlyDataset.php`, slip view | AN-17 (queue) for bulk slips | M |
| 6 | **AN-21 / AN-22 / AN-23 Asset, engage and helpdesk datasets** — asset acknowledgement column + `asset_assignments`, `asset_history`; `announcement_reads`, `handbook_acknowledgements`, `survey_answers` (anonymity rules); requester employee fields + resolver fields on `servicedesk`, templates by requestee / resolver | 21.2.2 Asset #1, 2; Engage #1, 5, 8, 9, 12; Helpdesk #1, 2 | `ServiceDeskDataset.php`, new `Datasets/*`, assets migration (`acknowledged_at`), My assets action | — | M |
| 7 | **AN-17 Export formats and background runs** — `.xlsx` and PDF writers, queued `ExportReportJob` with `ReportRun` status queued / running / completed, chunked rows beyond `max_rows` for queued runs, "My downloads" page (90 days; run now, regenerate, cancel, delete, view filters) | 21.2.1 #4, 14, 15, 17, 39; 21.2.2 output column | `ReportExports.php`, new job, `ReportRun` migration (status, filters snapshot), new page | Owner decision (library) | M |
| 8 | **AN-2 Drill-down and widget export** — `WorkforceMetrics::DRILLDOWNS`, "Show people" slide-over, per-widget export, row actions give badge / reward points / schedule 1-on-1 (initiative after HP-8j) | 21.1.0 #5–#10; every "show more" / "Export list" row in 21.1 | `WorkforceMetrics.php`, `Dashboards.php`, dashboard-viewer blade, `EngagementActions`, `PerformanceActions` | AN-17 for large exports | M |
| 9 | **AN-1 Dashboard views (= CA-10)** — `dashboard_views` (dashboard key, name, applicability, viewer users / roles, schedule + recipients, status), shared `DashboardFilter` component, view picker on every module dashboard, payroll freeze day setting | 21.1.0 #1, #2; 21.1.1 #1–#8; view rows in 21.1.3–21.1.12 | migration, `DashboardResource.php`, `Dashboards.php`, `ReportSchedules.php`, `config/peopleos.php` (setting) | CA-9 (scope helper) | M |
| 10 | **AN-16 Report centre UX** — process grouping, counts, recents / favourites tabs, "My schedules", prompt-at-run parameters (period, org unit), duplicate, constant column, `CommandSearch` indexing, `team_safe` template flag (for RQ-11) | 21.2.1 #2, 5, 9, 18, 28, 34, 37, 38, 40 | `ReportLibraryPage.php` + blade, `ReportLibrary.php`, `ReportRunner.php`, `CommandSearch.php`, new `report_favourites` | — | M |
| 11 | **AN-18 Report sharing and delivery** — share with named users / roles, schedule email with a link (never the file), external addresses refused | 21.2.1 #21, #22 | `Report` migration (`shared_user_ids`, `shared_role_ids`), `ReportPolicy.php`, `ReportSchedules.php` | — | S |
| 12 | **AN-3 Time office dashboard** | 21.1.2 #1–#13 | new page + blade, `WorkforceMetrics.php` (series), `ReportLibrary.php` (`punches_by_source`) | AN-28, AN-2 (drill-down) | M |
| 13 | **AN-4 Employee movement board** — month basis, upcoming joiners, notice, headcount / in-out chart, early exits (owner label), attrition, reasons | 21.1.3 #1–#3, #7 | `AnalyticsDefaults.php` (seed) or new page, `ReportLibrary.php` (`early_exits`) | AN-2 | S |
| 14 | **AN-12 Payroll overview board** — effective CTC decomposition, CTC bands, contributions, deductions, variables, liabilities, loans | 21.1.12 Payroll #1–#3, #7 | `AnalyticsDefaults.php`, `WorkforceMetrics.php`, `CtcRegisterDataset.php` (`ctc_band`), config `analytics.ctc_bands` | AN-24, AN-26 | M |
| 15 | **AN-5 / AN-6 / AN-7 Engage, expense and helpdesk dashboard depth** — engage board (monthly badges / points, leaderboards for `recognition.manage`), expense reimbursable split / 12-month trend / net to pay, helpdesk month-on-month, closed on time / late split, ageing buckets, per-agent open, sub-category level | 21.1.4 #1–#7; 21.1.5 #2, #3, #5; 21.1.6 #2–#4, #6, #8–#11 | `AnalyticsDefaults.php`, `WorkforceMetrics.php`, `ServiceDeskAnalytics.php` + blade, `Dataset::employeeFields()` (joining date) | AN-2 | M |
| 16 | **AN-11 ROI dashboard** — config `analytics.roi.tasks` (manual / system minutes per task key, tenant-editable), counts from audit events, month picker, HR monthly cost setting | 21.1.11 #1–#8 | new page + blade, `config/peopleos.php`, `config/peopleos_setup.php` | — | M |
| 17 | **AN-14 HR Ops dashboard** — dataset `workflow_tasks`, average completion vs target days, efficiency bands, targets in Setup | 21.1.12 HR Ops #1–#4 | new dataset + page, `config/peopleos_setup.php` | — | M |
| 18 | **AN-30 Inbox insights** — `InboxInsights` page (favourites, overdue, all pending, relieved owners, to be archived, routing errors), scope picker, search, filters, bulk remind, bulk archive (system tasks only), datasets `pending_tasks`, `approval_steps`, workflow applicability | 21.3 #1–#14; 21.2.2 Workforce #22, 23, 39, 49 | new page, `app/Domain/Experience/Services/InboxInsights.php`, `ApprovalCenter.php`, `InboxArchives.php`, permission `workflow.insights` | RQ-8 (module 20) shares the count service | L |
| 19 | **AN-9 / AN-8 / AN-10 / AN-13 Performance, 9-box, pulse and happiness depth** — new-hire / open-closed filters, 1-on-1 counts, employee drill panel; 9-box analytics view over HP-8g; pulse lifetime / per-question stats; happiness improvement vs going-good tags | 21.1.8 #4, 5, 7, 13; 21.1.7 #1–#8; 21.1.9 #1, 2, 5, 10; 21.1.12 Happiness #2–#4 | `PerformanceAnalytics.php`, `SurveyResults`, `HappinessIndex.php`, blades | HP-8g, HP-8i, HP-8j | M |
| 20 | **AN-15 Dashboard builder upgrades** — curated gallery, "Add to dashboard" from a report, copy widget, per-widget filter, widget height, table total, zoom, per-user favourite and personal boards, warn before deleting a report used on boards | 21.1.12 Custom dashboards #2, 5, 6, 8, 10, 12; 21.2.1 #23, #30; 21.3 #16 | `DashboardResource.php`, `Dashboards.php`, dashboard-viewer blade, `dashboards` migration (owner, favourites) | AN-1 | M |
| 21 | **AN-20 Password-protected reports** + **AN-25 statutory joiner forms** (EPF Form 11, ESI Form 1, Gratuity Form F) | 21.2.1 #16, #33; 21.1.12 Custom dashboards #11; 21.2.2 Payroll #10, 11, 17 | `ReportExports.php`, setting `analytics.protected_reports`; compliance builders + rule data | AN-17 library decision; statutory rule verification | M |
| 22 | **AN-19 Analyse a report with AI** (optional) | 21.2.1 #26 | `app/Domain/Ai/*`, report view | Owner decision | S |
| 23 | **PM-1 Project masters and form settings** — customers (+ import), project stages, task types, milestone types, health dimensions, project roles, field-rule matrices (project, task, invoice), custom-field entities `project`, `project_task`, `project_invoice`, permissions, Setup module `projects` | 21.4.2 #1–#11, #14 | new `app/Domain/Projects/{Models,Services}`, migrations, resources, `config/peopleos.php`, `config/peopleos_setup.php` | CA-1 (matrix type, `projects` options) | M |
| 24 | **PM-3 Projects core** — `projects` (all §21.4.4 columns), `project_role_rates`, `project_billable_categories`, `project_attachments`, `ProjectWizard` (8 steps), approval trigger `project.changed` with before/after, workspace page (cards, list, filters), health updates | 21.4.4 #1–#42; 21.4.5 #1–#7 | `app/Domain/Projects/*`, `ProjectResource`, `ProjectsWorkspace` page, `WorkflowTrigger`, Approval Center source | PM-1 | L |
| 25 | **PM-2 Project templates** — templates, stages, milestones, tasks, create-from-template | 21.4.1 #1–#10 | `ProjectTemplateResource`, `Projects::createFromTemplate()` | PM-1, PM-4, PM-5 | M |
| 26 | **PM-4 Tasks, members and the board** — `project_tasks` (+ subtasks, owners, tags, attachments, comments with mentions), board / table with stages, filters, `project_members` with economics and member rights, My tasks | 21.4.6 #1–#11, #17–#20; 21.4.7 #1 | `app/Domain/Projects/*`, relation managers, board view, `ProjectPolicy` | PM-3 | L |
| 27 | **PM-5 Milestones** — `project_milestones`, `project_milestone_estimates`, filters, approval trigger `milestone.changed` | 21.4.6 #12–#16 | as above | PM-3 | M |
| 28 | **PM-6 Timesheets** — policy type `timesheet` (period, minimums, reminders), `timesheets` + `timesheet_entries`, weekly grid and date-wise entry, hours dialog, drafts, project-rule validation, approval with line-level decisions and `timesheet.workflow_mode` / `exclusive_projects`, HR search, calendar, attendance day panel, reminders command | 21.4.3 #1, 3, 4; 21.4.8 #1–#13, #15; setup 01 §1.35 | `app/Domain/Projects/Services/Timesheets.php`, pages, Approval Center source `timesheet`, `config/peopleos.php` (policy type, settings), scheduler | PM-3, PM-4 | L |
| 29 | **PM-7 Project alerts** | 21.4.6 #21 | `project_alert_rules`, command `peopleos:projects:alerts` | PM-4, PM-5 | S |
| 30 | **PM-8 Project invoices** — generate (fixed / actual hours), invoice form (discount, taxes from the tax master, dates, comments, OU, template, payments, custom fields, terms), cancel releasing hours, register | 21.4.9 #1–#15; 21.4.2 #9 | `project_invoices`, `project_invoice_taxes`, letter-studio template type, resource | PM-5, PM-6 | L |
| 31 | **PM-9 Project datasets, reports and dashboard** — datasets `projects`, `project_tasks`, `project_members`, `timesheet_entries`, `project_invoices`; templates incl. "Projects detail"; seeded "Projects overview" board | 21.2.2 Project Management #1; 21.4.10 #1, #2; 21.4.5 #4 | new `Datasets/*`, `ReportLibrary.php`, `AnalyticsDefaults.php` | PM-3…PM-8 | M |
| 32 | **PM-10 Project links in other modules** — `project_id` on expense receipts / reports / advances / travel requests, on-duty project / task / category (optional timesheet entry), expense settings for project-based approval, multiple projects per report, travel project visibility, advance by project | 21.4.10 #3–#8 | expense / travel / time-office migrations and forms, `config/peopleos.php` settings, workflow approver role "project owner" | PM-3 | M |
